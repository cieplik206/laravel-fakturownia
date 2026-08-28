<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use InvalidArgumentException;

final readonly class FinalizeAttachmentPayloadCodec implements OperationPayloadCodec
{
    public const int SchemaVersion = 1;

    public const string WriteActivationSlot = 'invoice.attachment.finalize';

    /** @var list<string> */
    private const array Keys = [
        'schema_version', 'write_activation_slot', 'connection_key', 'remote_id', 'resource_id',
        'upload_operation_id', 'artifact_id', 'file_name', 'disk', 'content_address', 'mime_type',
        'size_bytes', 'expected_attachments_count', 'revision_key_hmac_sha256', 'source_snapshot_hmac_sha256',
    ];

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(FinalizeAttachmentCommand $command): CanonicalObject
    {
        return new CanonicalObject([
            'schema_version' => self::schemaVersion(),
            'write_activation_slot' => self::WriteActivationSlot,
            'connection_key' => $command->connectionKey->value,
            'remote_id' => $command->remoteId,
            'resource_id' => $command->resourceId->value,
            'upload_operation_id' => $command->uploadOperationId->value,
            'artifact_id' => $command->artifactId->value,
            'file_name' => $command->fileName,
            'disk' => $command->object->disk,
            'content_address' => (string) $command->object->contentAddress,
            'mime_type' => $command->object->mimeType,
            'size_bytes' => $command->object->sizeBytes,
            'expected_attachments_count' => $command->expectedAttachmentsCount,
            'revision_key_hmac_sha256' => $command->revisionKeyHmacSha256,
            'source_snapshot_hmac_sha256' => $command->sourceSnapshotHmacSha256,
        ]);
    }

    public function decode(CanonicalObject $payload): FinalizeAttachmentCommand
    {
        $keys = array_keys($payload->values);
        sort($keys, SORT_STRING);
        $expected = self::Keys;
        sort($expected, SORT_STRING);

        if ($keys !== $expected
            || $payload->values['schema_version'] !== self::schemaVersion()
            || $payload->values['write_activation_slot'] !== self::WriteActivationSlot) {
            throw new InvalidArgumentException('Attachment finalize payload contract is invalid.');
        }

        return new FinalizeAttachmentCommand(
            new ConnectionKey($this->string($payload, 'connection_key')),
            $this->string($payload, 'remote_id'),
            new InvoiceResourceId($this->string($payload, 'resource_id')),
            new OperationId($this->string($payload, 'upload_operation_id')),
            new ArtifactId($this->string($payload, 'artifact_id')),
            $this->string($payload, 'file_name'),
            new ArtifactObjectDescriptor(
                $this->string($payload, 'disk'),
                ContentAddress::parse($this->string($payload, 'content_address')),
                $this->string($payload, 'mime_type'),
                $this->integer($payload, 'size_bytes'),
            ),
            $this->integer($payload, 'expected_attachments_count'),
            $this->string($payload, 'revision_key_hmac_sha256'),
            $this->string($payload, 'source_snapshot_hmac_sha256'),
        );
    }

    public function canonicalize(CanonicalObject $payload): CanonicalObject
    {
        return $this->encode($this->decode($payload));
    }

    public function writeActivationSlot(CanonicalObject $payload): string
    {
        $this->decode($payload);

        return self::WriteActivationSlot;
    }

    private function string(CanonicalObject $payload, string $key): string
    {
        $value = $payload->values[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("Attachment finalize {$key} must be a string.");
        }

        return $value;
    }

    private function integer(CanonicalObject $payload, string $key): int
    {
        $value = $payload->values[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException("Attachment finalize {$key} must be an integer.");
        }

        return $value;
    }
}
