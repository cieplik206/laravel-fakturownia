<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use InvalidArgumentException;

final readonly class InvoicePdfReadyResultCodec implements OperationResultCodec
{
    public const string ResultType = 'fakturownia.invoice_pdf_ready';

    public const int SchemaVersion = 1;

    public static function resultType(): string
    {
        return self::ResultType;
    }

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        if (! $result instanceof InvoicePdfReadyResult) {
            throw new InvalidArgumentException('Invoice PDF result codec received an unsupported result.');
        }

        return new EncodedResult(self::ResultType, self::SchemaVersion, [
            'artifact_id' => $result->artifactId->value,
            'content_address' => (string) $result->object->contentAddress,
            'disk' => $result->object->disk,
            'mime_type' => $result->object->mimeType,
            'resource_id' => $result->resourceId->value,
            'revision_hmac' => $result->revisionKeyHmac,
            'size_bytes' => $result->object->sizeBytes,
            'source_snapshot_hmac' => $result->sourceSnapshotFingerprintHmac,
        ]);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        if ($result->resultType !== self::ResultType || $result->schemaVersion !== self::SchemaVersion) {
            throw new InvalidArgumentException('Invoice PDF result envelope is unsupported.');
        }

        $payload = $result->payload;
        $expectedKeys = [
            'artifact_id',
            'content_address',
            'disk',
            'mime_type',
            'resource_id',
            'revision_hmac',
            'size_bytes',
            'source_snapshot_hmac',
        ];

        if (array_keys($payload) !== $expectedKeys) {
            throw new InvalidArgumentException('Invoice PDF result payload shape is invalid.');
        }

        return new InvoicePdfReadyResult(
            new ArtifactId($this->string($payload, 'artifact_id')),
            new InvoiceResourceId($this->string($payload, 'resource_id')),
            $this->string($payload, 'revision_hmac'),
            $this->string($payload, 'source_snapshot_hmac'),
            new ArtifactObjectDescriptor(
                $this->string($payload, 'disk'),
                ContentAddress::parse($this->string($payload, 'content_address')),
                $this->string($payload, 'mime_type'),
                $this->integer($payload, 'size_bytes'),
            ),
        );
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("Invoice PDF result [{$key}] must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function integer(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("Invoice PDF result [{$key}] must be a positive integer.");
        }

        return $value;
    }
}
