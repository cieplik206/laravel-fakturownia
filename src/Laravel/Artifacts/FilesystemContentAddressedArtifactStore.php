<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageKey;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLock;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\ResourceArtifactContentStream;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use LogicException;
use RuntimeException;
use Throwable;

final readonly class FilesystemContentAddressedArtifactStore implements ContentAddressedArtifactStore
{
    private const int ChunkBytes = 1_048_576;

    private const int MaximumObjectBytes = 1_073_741_824;

    public function __construct(
        private Factory $filesystems,
        private Repository $configuration,
        private ArtifactAddressLock $locks,
    ) {}

    public function put(ArtifactContentStream $content, string $mimeType): ArtifactObjectDescriptor
    {
        $temporary = tmpfile();

        if (! is_resource($temporary)) {
            throw new RuntimeException('A temporary artifact staging stream cannot be created.');
        }

        try {
            [$address, $sizeBytes, $detectedMimeType] = $this->stage($content, $temporary);

            if (! hash_equals($detectedMimeType, $mimeType)) {
                throw new RuntimeException('The artifact MIME type does not match its verified content.');
            }

            $namespace = $this->namespace();
            $lease = $this->locks->acquire($namespace, $address);

            try {
                $existing = $this->inspect($address);

                if ($existing instanceof ArtifactObjectDescriptor) {
                    $this->assertDescriptor($existing, $namespace, $address, $mimeType, $sizeBytes);

                    return $existing;
                }

                $lease->renewFor(120);
                $lease->assertOwned();

                if (fseek($temporary, 0) !== 0
                    || ! $this->disk($namespace)->writeStream(
                        ArtifactStorageKey::for($namespace, $address),
                        $temporary,
                        ['visibility' => Filesystem::VISIBILITY_PRIVATE],
                    )) {
                    throw new RuntimeException('The immutable artifact object could not be stored.');
                }

                $lease->assertOwned();
                $stored = $this->inspect($address);

                if (! $stored instanceof ArtifactObjectDescriptor) {
                    throw new RuntimeException('The artifact store did not expose the completed object.');
                }

                $this->assertDescriptor($stored, $namespace, $address, $mimeType, $sizeBytes);

                return $stored;
            } finally {
                $lease->release();
            }
        } finally {
            fclose($temporary);
        }
    }

    public function inspect(ContentAddress $contentAddress): ?ArtifactObjectDescriptor
    {
        $namespace = $this->namespace();
        $stream = $this->disk($namespace)->readStream(ArtifactStorageKey::for($namespace, $contentAddress));

        if (! is_resource($stream)) {
            return null;
        }

        try {
            [$actualAddress, $sizeBytes, $mimeType] = $this->digest($stream);
        } finally {
            fclose($stream);
        }

        if (! $actualAddress->equals($contentAddress)) {
            throw new RuntimeException('The artifact object checksum conflicts with its content address.');
        }

        return new ArtifactObjectDescriptor($namespace->disk, $actualAddress, $mimeType, $sizeBytes);
    }

    public function open(ContentAddress $contentAddress): ArtifactContentStream
    {
        $namespace = $this->namespace();
        $stream = $this->disk($namespace)->readStream(ArtifactStorageKey::for($namespace, $contentAddress));

        if (! is_resource($stream)) {
            throw new RuntimeException('The artifact object is unavailable.');
        }

        return new ResourceArtifactContentStream($stream);
    }

    /** @param resource $temporary
     * @return array{ContentAddress, int, string}
     */
    private function stage(ArtifactContentStream $content, $temporary): array
    {
        try {
            while (! $content->eof()) {
                $chunk = $content->read(self::ChunkBytes);

                if ($chunk === '' && ! $content->eof()) {
                    throw new RuntimeException('The artifact source stream stopped before EOF.');
                }

                $this->write($temporary, $chunk);
            }

            if (fseek($temporary, 0) !== 0) {
                throw new RuntimeException('The staged artifact cannot be rewound.');
            }

            return $this->digest($temporary);
        } catch (Throwable $failure) {
            throw $failure;
        }
    }

    /** @param resource $stream
     * @return array{ContentAddress, int, string}
     */
    private function digest($stream): array
    {
        $hash = hash_init('sha256');
        $sizeBytes = 0;
        $prefix = '';

        while (! feof($stream)) {
            $chunk = fread($stream, self::ChunkBytes);

            if (! is_string($chunk)) {
                throw new RuntimeException('The artifact object cannot be read.');
            }

            if ($chunk === '' && ! feof($stream)) {
                throw new RuntimeException('The artifact object stream stopped before EOF.');
            }

            $sizeBytes += strlen($chunk);

            if ($sizeBytes > self::MaximumObjectBytes) {
                throw new RuntimeException('The artifact object exceeds the hard storage limit.');
            }

            $prefix = substr($prefix.$chunk, 0, 512);
            hash_update($hash, $chunk);
        }

        if ($sizeBytes < 1) {
            throw new RuntimeException('The artifact object cannot be empty.');
        }

        return [ContentAddress::fromSha256(hash_final($hash)), $sizeBytes, $this->mimeType($prefix)];
    }

    /** @param resource $target */
    private function write($target, string $bytes): void
    {
        $offset = 0;

        while ($offset < strlen($bytes)) {
            $written = fwrite($target, substr($bytes, $offset));

            if (! is_int($written) || $written < 1) {
                throw new RuntimeException('The artifact staging stream cannot be written.');
            }

            $offset += $written;
        }
    }

    private function mimeType(string $prefix): string
    {
        if (str_starts_with($prefix, '%PDF-')) {
            return 'application/pdf';
        }

        if (str_starts_with($prefix, "PK\x03\x04")) {
            return 'application/zip';
        }

        if (preg_match('/^(?:\xEF\xBB\xBF)?\s*</', $prefix) === 1) {
            return 'application/xml';
        }

        return 'application/octet-stream';
    }

    private function assertDescriptor(
        ArtifactObjectDescriptor $descriptor,
        ArtifactStorageNamespace $namespace,
        ContentAddress $address,
        string $mimeType,
        int $sizeBytes,
    ): void {
        if (! hash_equals($descriptor->disk, $namespace->disk)
            || ! $descriptor->contentAddress->equals($address)
            || ! hash_equals($descriptor->mimeType, $mimeType)
            || $descriptor->sizeBytes !== $sizeBytes) {
            throw new RuntimeException('The immutable artifact object conflicts with the staged content.');
        }
    }

    private function namespace(): ArtifactStorageNamespace
    {
        $disk = $this->configuration->get('fakturownia.artifacts.disk');
        $prefix = $this->configuration->get('fakturownia.artifacts.prefix');

        if (! is_string($disk) || ! is_string($prefix)) {
            throw new LogicException('The artifact filesystem namespace is not configured.');
        }

        return new ArtifactStorageNamespace($disk, $prefix);
    }

    private function disk(ArtifactStorageNamespace $namespace): Filesystem
    {
        return $this->filesystems->disk($namespace->disk);
    }
}
