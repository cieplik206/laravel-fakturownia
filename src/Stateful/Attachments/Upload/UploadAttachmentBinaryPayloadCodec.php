<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Upload;

use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;

final readonly class UploadAttachmentBinaryPayloadCodec implements OperationPayloadCodec
{
    public const int SchemaVersion = 1;

    public const string WriteActivationSlot = 'invoice.attachment.binary.upload';

    /** @var list<string> */
    private const array Keys = [
        'schema_version',
        'write_activation_slot',
        'connection_key',
        'remote_id',
        'resource_id',
        'local_reference',
        'file_name',
        'content_address',
        'mime_type',
        'size_bytes',
        'expected_attachments_count',
        'revision_key_hmac_sha256',
        'source_snapshot_hmac_sha256',
    ];

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(UploadAttachmentBinaryCommand $command): CanonicalObject
    {
        return new CanonicalObject([
            'schema_version' => self::schemaVersion(),
            'write_activation_slot' => self::WriteActivationSlot,
            'connection_key' => $command->connectionKey->value,
            'remote_id' => $command->remoteId,
            'resource_id' => $command->resourceId->value,
            'local_reference' => $command->localReference,
            'file_name' => $command->fileName,
            'content_address' => (string) $command->contentAddress,
            'mime_type' => $command->mimeType,
            'size_bytes' => $command->sizeBytes,
            'expected_attachments_count' => $command->expectedAttachmentsCount,
            'revision_key_hmac_sha256' => $command->revisionKeyHmacSha256,
            'source_snapshot_hmac_sha256' => $command->sourceSnapshotHmacSha256,
        ]);
    }

    public function decode(CanonicalObject $payload): UploadAttachmentBinaryCommand
    {
        $keys = array_keys($payload->values);
        sort($keys, SORT_STRING);
        $expected = self::Keys;
        sort($expected, SORT_STRING);

        if ($keys !== $expected
            || $payload->values['schema_version'] !== self::schemaVersion()
            || $payload->values['write_activation_slot'] !== self::WriteActivationSlot) {
            throw new InvalidArgumentException('Attachment upload payload contract is invalid.');
        }

        return new UploadAttachmentBinaryCommand(
            new ConnectionKey($this->string($payload, 'connection_key')),
            $this->string($payload, 'remote_id'),
            new InvoiceResourceId($this->string($payload, 'resource_id')),
            $this->string($payload, 'local_reference'),
            $this->string($payload, 'file_name'),
            ContentAddress::parse($this->string($payload, 'content_address')),
            $this->string($payload, 'mime_type'),
            $this->integer($payload, 'size_bytes'),
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
        $value = $payload->values[$key];

        if (! is_string($value)) {
            throw new InvalidArgumentException("Attachment upload {$key} must be a string.");
        }

        return $value;
    }

    private function integer(CanonicalObject $payload, string $key): int
    {
        $value = $payload->values[$key];

        if (! is_int($value)) {
            throw new InvalidArgumentException("Attachment upload {$key} must be an integer.");
        }

        return $value;
    }
}
