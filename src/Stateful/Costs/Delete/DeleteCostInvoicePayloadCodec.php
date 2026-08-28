<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;

final readonly class DeleteCostInvoicePayloadCodec implements OperationPayloadCodec
{
    public const int SchemaVersion = 1;

    public const string WriteActivationSlot = 'invoice.cost.delete';

    /** @var list<string> */
    private const array Keys = [
        'schema_version',
        'write_activation_slot',
        'connection_key',
        'remote_id',
        'local_reference',
        'operator_reference',
        'decision_reference',
        'remote_snapshot_hmac_sha256',
    ];

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(DeleteCostInvoiceCommand $command): CanonicalObject
    {
        return new CanonicalObject([
            'schema_version' => self::schemaVersion(),
            'write_activation_slot' => self::WriteActivationSlot,
            'connection_key' => $command->connectionKey->value,
            'remote_id' => $command->remoteId,
            'local_reference' => $command->localReference,
            'operator_reference' => $command->operatorReference,
            'decision_reference' => $command->decisionReference,
            'remote_snapshot_hmac_sha256' => $command->remoteSnapshotHmacSha256,
        ]);
    }

    public function decode(CanonicalObject $payload): DeleteCostInvoiceCommand
    {
        $keys = array_keys($payload->values);
        sort($keys, SORT_STRING);
        $expectedKeys = self::Keys;
        sort($expectedKeys, SORT_STRING);

        if ($keys !== $expectedKeys
            || $payload->values['schema_version'] !== self::schemaVersion()
            || $payload->values['write_activation_slot'] !== self::WriteActivationSlot) {
            throw new InvalidArgumentException('Cost invoice delete payload contract is invalid.');
        }

        return new DeleteCostInvoiceCommand(
            new ConnectionKey($this->string($payload->values['connection_key'], 'connection key')),
            $this->string($payload->values['remote_id'], 'remote ID'),
            $this->string($payload->values['local_reference'], 'local reference'),
            $this->string($payload->values['operator_reference'], 'operator reference'),
            $this->string($payload->values['decision_reference'], 'decision reference'),
            $this->string($payload->values['remote_snapshot_hmac_sha256'], 'snapshot HMAC'),
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

    private function string(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Cost invoice delete {$field} must be a string.");
        }

        return $value;
    }
}
