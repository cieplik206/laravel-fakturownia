<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use InvalidArgumentException;

final readonly class DownloadInvoicePdfPayloadCodec implements OperationPayloadCodec
{
    public const int SchemaVersion = 1;

    public const string WriteActivationSlot = 'artifact.pdf.put';

    public function encode(DownloadInvoicePdfCommand $command): CanonicalObject
    {
        return new CanonicalObject([
            'connection_key' => $command->connectionKey->value,
            'generation' => $command->generation,
            'maximum_bytes' => $command->maximumBytes,
            'remote_id' => $command->remoteId,
            'rendering_profile' => $command->renderingProfile,
            'resource_id' => $command->resourceId->value,
            'revision_hmac' => $command->revisionKey->hex,
            'revision_hmac_key_version' => $command->revisionKey->keyVersion,
            'source_gov_id' => $command->sourceGovernmentId,
            'source_ksef_operation_id' => $command->sourceKsefOperationId?->value,
            'source_row_version' => $command->sourceRowVersion,
            'source_snapshot_hmac' => $command->sourceSnapshotFingerprint->hex,
            'source_snapshot_hmac_key_version' => $command->sourceSnapshotFingerprint->keyVersion,
        ]);
    }

    public function decode(CanonicalObject $payload): DownloadInvoicePdfCommand
    {
        $values = $payload->values;
        $expectedKeys = [
            'connection_key',
            'generation',
            'maximum_bytes',
            'remote_id',
            'rendering_profile',
            'resource_id',
            'revision_hmac',
            'revision_hmac_key_version',
            'source_gov_id',
            'source_ksef_operation_id',
            'source_row_version',
            'source_snapshot_hmac',
            'source_snapshot_hmac_key_version',
        ];

        if (array_keys($values) !== $expectedKeys) {
            throw new InvalidArgumentException('The invoice PDF payload shape is invalid.');
        }

        return new DownloadInvoicePdfCommand(
            new ConnectionKey($this->string($values, 'connection_key')),
            new InvoiceResourceId($this->string($values, 'resource_id')),
            $this->string($values, 'remote_id'),
            new VersionedHmacDigest(
                $this->integer($values, 'source_snapshot_hmac_key_version'),
                LookupHmacDomain::Payload,
                $this->string($values, 'source_snapshot_hmac'),
            ),
            $this->integer($values, 'source_row_version'),
            $this->nullableOperationId($values, 'source_ksef_operation_id'),
            $this->nullableString($values, 'source_gov_id'),
            $this->string($values, 'rendering_profile'),
            new VersionedHmacDigest(
                $this->integer($values, 'revision_hmac_key_version'),
                LookupHmacDomain::Payload,
                $this->string($values, 'revision_hmac'),
            ),
            $this->integer($values, 'generation'),
            $this->integer($values, 'maximum_bytes'),
        );
    }

    public function canonicalize(CanonicalObject $payload): CanonicalObject
    {
        return $this->encode($this->decode($payload));
    }

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function writeActivationSlot(CanonicalObject $payload): string
    {
        $this->decode($payload);

        return self::WriteActivationSlot;
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("The invoice PDF payload [{$key}] must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException("The invoice PDF payload [{$key}] must be an integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function nullableString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("The invoice PDF payload [{$key}] must be a string or null.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function nullableOperationId(array $values, string $key): ?OperationId
    {
        $value = $this->nullableString($values, $key);

        return $value === null ? null : new OperationId($value);
    }
}
