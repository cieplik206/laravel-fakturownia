<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Throwable;

final readonly class InvoicePdfStager
{
    private const int ChunkBytes = 1_048_576;

    private const int MaximumTailBytes = 1_048_576;

    public function stage(ArtifactContentStream $source, int $maximumBytes): StagedInvoicePdf
    {
        if ($maximumBytes < 9 || $maximumBytes > 100 * 1_048_576) {
            throw DownloadInvoicePdfOperationFailure::sourceRejected();
        }

        $temporary = tmpfile();

        if (! is_resource($temporary)) {
            throw DownloadInvoicePdfOperationFailure::requestNotStarted();
        }

        $hash = hash_init('sha256');
        $prefix = '';
        $tail = '';
        $size = 0;

        try {
            while (! $source->eof()) {
                $chunk = $source->read(min(self::ChunkBytes, $maximumBytes - min($size, $maximumBytes) + 1));

                if ($chunk === '' && ! $source->eof()) {
                    throw DownloadInvoicePdfOperationFailure::requestNotStarted();
                }

                $size += strlen($chunk);

                if ($size > $maximumBytes) {
                    throw DownloadInvoicePdfOperationFailure::sourceRejected();
                }

                $this->write($temporary, $chunk);
                hash_update($hash, $chunk);
                $prefix = substr($prefix.$chunk, 0, 5);
                $tail = substr($tail.$chunk, -self::MaximumTailBytes);
            }

            $this->assertValidPdf($prefix, $tail, $size);

            if (fseek($temporary, 0) !== 0) {
                throw DownloadInvoicePdfOperationFailure::requestNotStarted();
            }

            return new StagedInvoicePdf(
                new ResourceArtifactContentStream($temporary),
                ContentAddress::fromSha256(hash_final($hash)),
                $size,
            );
        } catch (Throwable $failure) {
            fclose($temporary);

            if ($failure instanceof DownloadInvoicePdfOperationFailure) {
                throw $failure;
            }

            throw DownloadInvoicePdfOperationFailure::requestNotStarted();
        } finally {
            $source->close();
        }
    }

    /** @param resource $target */
    private function write($target, string $chunk): void
    {
        $offset = 0;

        while ($offset < strlen($chunk)) {
            $written = fwrite($target, substr($chunk, $offset));

            if (! is_int($written) || $written < 1) {
                throw DownloadInvoicePdfOperationFailure::requestNotStarted();
            }

            $offset += $written;
        }
    }

    private function assertValidPdf(string $prefix, string $tail, int $size): void
    {
        if ($size < 9
            || ! str_starts_with($prefix, '%PDF-')
            || preg_match('/%%EOF[\x00\x09\x0A\x0C\x0D\x20]*$/', $tail) !== 1) {
            throw DownloadInvoicePdfOperationFailure::sourceRejected();
        }
    }
}
