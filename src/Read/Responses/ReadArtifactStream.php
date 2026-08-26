<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Responses;

use Cieplik206\Fakturownia\Read\Contracts\ReadBodyStream;
use Cieplik206\Fakturownia\Read\Contracts\ReadClock;
use Cieplik206\Fakturownia\Read\Exceptions\ArtifactValidationFailed;
use Cieplik206\Fakturownia\Read\Exceptions\TransportFailed;
use Cieplik206\Fakturownia\Read\Requests\StreamReadRequest;
use Cieplik206\Fakturownia\Read\Support\ResponseStatusClassifier;
use Cieplik206\Fakturownia\Read\ValueObjects\ArtifactFormat;
use Cieplik206\Fakturownia\Read\ValueObjects\RedirectPolicy;
use DOMDocument;
use HashContext;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Throwable;

final class ReadArtifactStream implements JsonSerializable, ReadBodyStream
{
    private const PrefixBytes = 512;

    private const MaximumReadBytes = 1_048_576;

    private const MaximumTailBytes = 1_048_576;

    private string $buffer = '';

    private string $tail = '';

    private string $xmlScanTail = '';

    private string $xmlBuffer = '';

    private int $sourceBytesRead = 0;

    private bool $closed = false;

    private bool $validated = false;

    private ?string $checksum = null;

    private readonly HashContext $hashContext;

    private readonly ?int $declaredLength;

    private function __construct(
        private readonly StreamReadRequest $request,
        private readonly StreamReadResponse $response,
    ) {
        $this->hashContext = hash_init('sha256');
        $this->declaredLength = $this->validateHeadersAndRedirects();
        $this->buffer = $this->readSource(min(self::PrefixBytes, $request->maximumResponseBytes()));

        if ($this->buffer === '') {
            throw new ArtifactValidationFailed(
                $request->operation(),
                'non-empty body',
                $response->headers->providerRequestId(),
            );
        }

        $this->acceptSourceBytes($this->buffer);
        $this->validateMagic($this->buffer);

        if ($this->sourceEof()) {
            $this->finishValidation();
        }
    }

    public static function open(
        StreamReadRequest $request,
        StreamReadResponse $response,
        ReadClock $clock,
    ): self {
        try {
            (new ResponseStatusClassifier($clock))->assertSuccessful($request, $response->statusCode, $response->headers);

            return new self($request, $response);
        } catch (Throwable $exception) {
            self::closeSilently($response->body);

            throw $exception;
        }
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('The artifact read length must be positive.');
        }

        if ($this->closed) {
            throw new LogicException('The artifact stream is closed.');
        }

        $length = min($length, self::MaximumReadBytes);
        $result = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($result));
        $remaining = $length - strlen($result);

        if ($remaining === 0 || $this->validated) {
            return $result;
        }

        if ($this->sourceBytesRead >= $this->request->maximumResponseBytes()) {
            $overflow = $this->readSource(1);

            if ($overflow !== '') {
                $this->fail('maximum body size');
            }

            if (! $this->sourceEof()) {
                self::closeSilently($this->response->body);

                throw new TransportFailed($this->request->operation());
            }

            $this->finishValidation();

            return $result;
        }

        $chunk = $this->readSource(min(
            $remaining,
            $this->request->maximumResponseBytes() - $this->sourceBytesRead,
        ));

        if ($chunk === '') {
            if (! $this->sourceEof()) {
                self::closeSilently($this->response->body);

                throw new TransportFailed($this->request->operation());
            }

            $this->finishValidation();

            return $result;
        }

        $this->acceptSourceBytes($chunk);
        $result .= $chunk;

        if ($this->sourceEof()) {
            $this->finishValidation();
        }

        return $result;
    }

    public function eof(): bool
    {
        return $this->buffer === '' && $this->validated;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        self::closeSilently($this->response->body);
    }

    public function isValidated(): bool
    {
        return $this->validated;
    }

    public function size(): int
    {
        if (! $this->validated) {
            throw new LogicException('The artifact size is unavailable before complete validation.');
        }

        return $this->sourceBytesRead;
    }

    public function sha256(): string
    {
        if (! $this->validated || $this->checksum === null) {
            throw new LogicException('The artifact checksum is unavailable before complete validation.');
        }

        return $this->checksum;
    }

    public function format(): ArtifactFormat
    {
        return $this->request->format;
    }

    public function providerRequestId(): ?string
    {
        return $this->response->headers->providerRequestId();
    }

    /** @return array{format: string, validated: bool, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'format' => $this->request->format->value,
            'validated' => $this->validated,
            'credentials' => '[REDACTED]',
        ];
    }

    /** @return array{format: string, validated: bool, credentials: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Artifact streams cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Artifact streams cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Artifact streams cannot be unserialized.');
    }

    private function validateHeadersAndRedirects(): ?int
    {
        if ($this->response->redirectCount > $this->request->maximumRedirects) {
            $this->fail('redirect count');
        }

        if ($this->response->crossHostRedirected && $this->response->redirectCount === 0) {
            $this->fail('redirect metadata');
        }

        if ($this->request->redirectPolicy === RedirectPolicy::Deny && $this->response->redirectCount !== 0) {
            $this->fail('redirect policy');
        }

        if ($this->request->redirectPolicy === RedirectPolicy::SameHost && $this->response->crossHostRedirected) {
            $this->fail('redirect host');
        }

        if ($this->response->crossHostRedirected && ! $this->response->credentialsStrippedOnRedirect) {
            $this->fail('redirect credential stripping');
        }

        $contentTypes = $this->response->headers->values('content-type');

        if (count($contentTypes) !== 1) {
            $this->fail('single content type');
        }

        $contentType = strtolower(trim(explode(';', $contentTypes[0])[0]));

        if (! in_array($contentType, $this->request->format->acceptedContentTypes(), true)) {
            $this->fail('content type');
        }

        $contentLengths = $this->response->headers->values('content-length');

        if (count($contentLengths) > 1) {
            $this->fail('single content length');
        }

        $contentLength = $contentLengths[0] ?? null;

        if ($contentLength === null) {
            return null;
        }

        if (preg_match('/^[0-9]{1,16}$/', $contentLength) !== 1) {
            $this->fail('content length');
        }

        $length = (int) $contentLength;

        if ($length > $this->request->maximumResponseBytes()) {
            $this->fail('maximum body size');
        }

        return $length;
    }

    private function validateMagic(string $prefix): void
    {
        $valid = match ($this->request->format) {
            ArtifactFormat::Pdf => str_starts_with($prefix, '%PDF-'),
            ArtifactFormat::Zip => str_starts_with($prefix, "PK\x03\x04")
                || str_starts_with($prefix, "PK\x05\x06")
                || str_starts_with($prefix, "PK\x07\x08"),
            ArtifactFormat::Xml, ArtifactFormat::Upo => $this->hasXmlMagic($prefix),
        };

        if (! $valid) {
            $this->fail('magic bytes');
        }
    }

    private function hasXmlMagic(string $prefix): bool
    {
        $withoutBom = str_starts_with($prefix, "\xEF\xBB\xBF") ? substr($prefix, 3) : $prefix;
        $trimmed = ltrim($withoutBom);
        $lower = strtolower($trimmed);

        return str_starts_with($trimmed, '<')
            && ! str_contains($lower, '<!doctype')
            && ! str_contains($lower, '<!entity')
            && ! str_contains($lower, '<html');
    }

    private function finishValidation(): void
    {
        if ($this->validated) {
            return;
        }

        if ($this->declaredLength !== null && $this->declaredLength !== $this->sourceBytesRead) {
            $this->fail('content length');
        }

        $validTrailer = match ($this->request->format) {
            ArtifactFormat::Pdf => preg_match('/%%EOF[\x00\x09\x0A\x0C\x0D\x20]*$/', $this->tail) === 1,
            ArtifactFormat::Zip => $this->hasZipEndOfCentralDirectory(),
            ArtifactFormat::Xml, ArtifactFormat::Upo => str_ends_with(rtrim($this->tail), '>')
                && $this->hasWellFormedXml(),
        };

        if (! $validTrailer) {
            $this->fail('trailer');
        }

        $this->checksum = hash_final($this->hashContext);
        $this->validated = true;
        self::closeSilently($this->response->body);
    }

    private function hasZipEndOfCentralDirectory(): bool
    {
        $position = strrpos($this->tail, "PK\x05\x06");

        if ($position === false || strlen($this->tail) - $position < 22) {
            return false;
        }

        $end = unpack(
            'vdisk/vcentral_disk/ventries_on_disk/ventries/Vcentral_size/Vcentral_offset/vcomment_length',
            substr($this->tail, $position + 4, 18),
        );

        if (! is_array($end)) {
            return false;
        }

        $disk = $end['disk'] ?? null;
        $centralDisk = $end['central_disk'] ?? null;
        $entriesOnDisk = $end['entries_on_disk'] ?? null;
        $entries = $end['entries'] ?? null;
        $centralSize = $end['central_size'] ?? null;
        $centralOffset = $end['central_offset'] ?? null;
        $commentLength = $end['comment_length'] ?? null;

        if (! is_int($disk)
            || ! is_int($centralDisk)
            || ! is_int($entriesOnDisk)
            || ! is_int($entries)
            || ! is_int($centralSize)
            || ! is_int($centralOffset)
            || ! is_int($commentLength)
            || $disk !== 0
            || $centralDisk !== 0
            || $entriesOnDisk !== $entries
            || $entries === 0xFFFF
            || $centralSize === 0xFFFFFFFF
            || $centralOffset === 0xFFFFFFFF
            || $position + 22 + $commentLength !== strlen($this->tail)) {
            return false;
        }

        $tailStart = $this->sourceBytesRead - strlen($this->tail);
        $absoluteEndOffset = $tailStart + $position;

        if ($centralOffset + $centralSize !== $absoluteEndOffset) {
            return false;
        }

        if ($entries === 0) {
            return $centralSize === 0 && $centralOffset === $absoluteEndOffset;
        }

        if ($centralSize < 46 || $centralOffset < $tailStart) {
            return false;
        }

        $relativeCentralOffset = $centralOffset - $tailStart;
        $central = substr($this->tail, $relativeCentralOffset, $centralSize);

        if (strlen($central) !== $centralSize) {
            return false;
        }

        return $this->hasExactZipCentralDirectory($central, $entries, $centralOffset);
    }

    private function hasExactZipCentralDirectory(string $central, int $expectedEntries, int $centralOffset): bool
    {
        $offset = 0;
        $entries = 0;

        while ($offset < strlen($central)) {
            if (substr($central, $offset, 4) !== "PK\x01\x02" || strlen($central) - $offset < 46) {
                return false;
            }

            $header = unpack(
                'vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal/Vexternal/Vlocal_offset',
                substr($central, $offset + 8, 38),
            );

            if (! is_array($header)) {
                return false;
            }

            $flags = $header['flags'] ?? null;
            $compressed = $header['compressed'] ?? null;
            $uncompressed = $header['uncompressed'] ?? null;
            $nameLength = $header['name_length'] ?? null;
            $extraLength = $header['extra_length'] ?? null;
            $commentLength = $header['comment_length'] ?? null;
            $diskStart = $header['disk_start'] ?? null;
            $localOffset = $header['local_offset'] ?? null;

            if (! is_int($flags)
                || ! is_int($compressed)
                || ! is_int($uncompressed)
                || ! is_int($nameLength)
                || ! is_int($extraLength)
                || ! is_int($commentLength)
                || ! is_int($diskStart)
                || ! is_int($localOffset)
                || ($flags & 0x0001) !== 0
                || $compressed === 0xFFFFFFFF
                || $uncompressed === 0xFFFFFFFF
                || $diskStart !== 0
                || $localOffset >= $centralOffset
                || $nameLength === 0) {
                return false;
            }

            $recordLength = 46 + $nameLength + $extraLength + $commentLength;

            if ($offset + $recordLength > strlen($central)) {
                return false;
            }

            $offset += $recordLength;
            $entries++;
        }

        return $offset === strlen($central) && $entries === $expectedEntries;
    }

    private function acceptSourceBytes(string $chunk): void
    {
        $this->sourceBytesRead += strlen($chunk);

        if ($this->sourceBytesRead > $this->request->maximumResponseBytes()) {
            $this->fail('maximum body size');
        }

        if ($this->request->format === ArtifactFormat::Xml || $this->request->format === ArtifactFormat::Upo) {
            $scan = strtolower($this->xmlScanTail.$chunk);

            if (str_contains($scan, '<!doctype') || str_contains($scan, '<!entity')) {
                $this->fail('XML declaration safety');
            }

            $this->xmlScanTail = substr($scan, -16);
            $this->xmlBuffer .= $chunk;
        }

        hash_update($this->hashContext, $chunk);
        $this->tail = substr($this->tail.$chunk, -self::MaximumTailBytes);
    }

    private function readSource(int $length): string
    {
        try {
            $chunk = $this->response->body->read($length);
        } catch (Throwable) {
            self::closeSilently($this->response->body);

            throw new TransportFailed($this->request->operation());
        }

        if (strlen($chunk) > $length) {
            self::closeSilently($this->response->body);

            throw new TransportFailed($this->request->operation());
        }

        return $chunk;
    }

    private function hasWellFormedXml(): bool
    {
        if (! class_exists(DOMDocument::class)) {
            return false;
        }

        try {
            $document = new DOMDocument;

            return $document->loadXML(
                $this->xmlBuffer,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function sourceEof(): bool
    {
        try {
            return $this->response->body->eof();
        } catch (Throwable) {
            self::closeSilently($this->response->body);

            throw new TransportFailed($this->request->operation());
        }
    }

    private function fail(string $safeReason): never
    {
        self::closeSilently($this->response->body);

        throw new ArtifactValidationFailed(
            $this->request->operation(),
            $safeReason,
            $this->response->headers->providerRequestId(),
        );
    }

    private static function closeSilently(ReadBodyStream $stream): void
    {
        try {
            $stream->close();
        } catch (Throwable) {
        }
    }
}
