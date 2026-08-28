<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use InvalidArgumentException;

final readonly class FinalizeAttachmentResultCodec implements OperationResultCodec
{
    public const string ResultType = 'fakturownia.attachment_finalized';

    public const int SchemaVersion = 1;

    /** @var list<string> */
    private const array Keys = [
        'remote_id', 'resource_id', 'upload_operation_id', 'artifact_id', 'file_name', 'disk',
        'content_address', 'mime_type', 'size_bytes', 'attachments_count', 'revision_key_hmac_sha256',
        'source_snapshot_hmac_sha256',
    ];

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
        if (! $result instanceof FinalizeAttachmentResult) {
            throw new InvalidArgumentException('Attachment finalize result type is invalid.');
        }

        return new EncodedResult(self::ResultType, self::SchemaVersion, [
            'remote_id' => $result->remoteId,
            'resource_id' => $result->resourceId->value,
            'upload_operation_id' => $result->uploadOperationId->value,
            'artifact_id' => $result->artifactId->value,
            'file_name' => $result->fileName,
            'disk' => $result->object->disk,
            'content_address' => (string) $result->object->contentAddress,
            'mime_type' => $result->object->mimeType,
            'size_bytes' => $result->object->sizeBytes,
            'attachments_count' => $result->attachmentsCount,
            'revision_key_hmac_sha256' => $result->revisionKeyHmacSha256,
            'source_snapshot_hmac_sha256' => $result->sourceSnapshotHmacSha256,
        ]);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        $keys = array_keys($result->payload);
        sort($keys, SORT_STRING);
        $expected = self::Keys;
        sort($expected, SORT_STRING);

        if ($result->resultType !== self::ResultType
            || $result->schemaVersion !== self::SchemaVersion
            || $keys !== $expected) {
            throw new InvalidArgumentException('Attachment finalize result envelope is invalid.');
        }

        return new FinalizeAttachmentResult(
            $this->string($result, 'remote_id'),
            new InvoiceResourceId($this->string($result, 'resource_id')),
            new OperationId($this->string($result, 'upload_operation_id')),
            new ArtifactId($this->string($result, 'artifact_id')),
            $this->string($result, 'file_name'),
            new ArtifactObjectDescriptor(
                $this->string($result, 'disk'),
                ContentAddress::parse($this->string($result, 'content_address')),
                $this->string($result, 'mime_type'),
                $this->integer($result, 'size_bytes'),
            ),
            $this->integer($result, 'attachments_count'),
            $this->string($result, 'revision_key_hmac_sha256'),
            $this->string($result, 'source_snapshot_hmac_sha256'),
        );
    }

    private function string(EncodedResult $result, string $key): string
    {
        $value = $result->payload[$key];

        if (! is_string($value)) {
            throw new InvalidArgumentException("Attachment finalize result {$key} must be a string.");
        }

        return $value;
    }

    private function integer(EncodedResult $result, string $key): int
    {
        $value = $result->payload[$key];

        if (! is_int($value)) {
            throw new InvalidArgumentException("Attachment finalize result {$key} must be an integer.");
        }

        return $value;
    }
}
