<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Stateful\Ksef\KsefConnectionProfile;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefOwnership;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefValidationMode;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;

final readonly class EnsureAcceptedPayloadCodec implements OperationPayloadCodec
{
    public const int SchemaVersion = 1;

    public const string ExplicitWriteActivationSlot = 'invoice.ksef.explicit_sdk';

    public const string ObserveOnlyWriteActivationSlot = 'invoice.ksef.provider_auto_send';

    /** @var list<string> */
    private const array PayloadKeys = [
        'schema_version',
        'write_activation_slot',
        'connection_key',
        'resource_id',
        'remote_id',
        'profile',
    ];

    /** @var list<string> */
    private const array ProfileKeys = [
        'connection_fingerprint_sha256',
        'ownership',
        'validation_mode',
        'expected_gov_auto_send_mode',
        'expected_buyer_company',
    ];

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(EnsureAcceptedCommand $command): CanonicalObject
    {
        return new CanonicalObject([
            'schema_version' => self::schemaVersion(),
            'write_activation_slot' => $this->slot($command->profile->ownership),
            'connection_key' => $command->connectionKey->value,
            'resource_id' => $command->resourceId->value,
            'remote_id' => $command->remoteId,
            'profile' => [
                'connection_fingerprint_sha256' => $command->profile->connectionFingerprintSha256,
                'ownership' => $command->profile->ownership->value,
                'validation_mode' => $command->profile->validationMode->value,
                'expected_gov_auto_send_mode' => $command->profile->expectedGovAutoSendMode,
                'expected_buyer_company' => $command->profile->expectedBuyerCompany,
            ],
        ]);
    }

    public function decode(CanonicalObject $payload): EnsureAcceptedCommand
    {
        $this->assertExactKeys($payload->values, self::PayloadKeys, 'payload');

        if (($payload->values['schema_version'] ?? null) !== self::schemaVersion()) {
            throw new InvalidArgumentException('The KSeF operation payload uses an unsupported schema.');
        }

        $profilePayload = $payload->values['profile'] ?? null;

        if (! is_array($profilePayload) || array_is_list($profilePayload)) {
            throw new InvalidArgumentException('The KSeF operation profile payload must be an object.');
        }

        $this->assertExactKeys($profilePayload, self::ProfileKeys, 'profile');
        $ownership = KsefOwnership::tryFrom($this->string($profilePayload, 'ownership'));
        $validationMode = KsefValidationMode::tryFrom($this->string($profilePayload, 'validation_mode'));

        if (! $ownership instanceof KsefOwnership || ! $validationMode instanceof KsefValidationMode) {
            throw new InvalidArgumentException('The KSeF operation profile contains an unsupported mode.');
        }

        $profile = new KsefConnectionProfile(
            connectionFingerprintSha256: $this->string($profilePayload, 'connection_fingerprint_sha256'),
            ownership: $ownership,
            validationMode: $validationMode,
            expectedGovAutoSendMode: $this->nullableString($profilePayload, 'expected_gov_auto_send_mode'),
            expectedBuyerCompany: $this->nullableBoolean($profilePayload, 'expected_buyer_company'),
        );
        $command = new EnsureAcceptedCommand(
            connectionKey: new ConnectionKey($this->string($payload->values, 'connection_key')),
            resourceId: new InvoiceResourceId($this->string($payload->values, 'resource_id')),
            remoteId: $this->string($payload->values, 'remote_id'),
            profile: $profile,
        );

        if (($payload->values['write_activation_slot'] ?? null) !== $this->slot($ownership)) {
            throw new InvalidArgumentException('The KSeF operation write activation does not match its ownership profile.');
        }

        return $command;
    }

    public function canonicalize(CanonicalObject $payload): CanonicalObject
    {
        return $this->encode($this->decode($payload));
    }

    public function writeActivationSlot(CanonicalObject $payload): string
    {
        return $this->slot($this->decode($payload)->profile->ownership);
    }

    private function slot(KsefOwnership $ownership): string
    {
        return match ($ownership) {
            KsefOwnership::ExplicitSdk => self::ExplicitWriteActivationSlot,
            KsefOwnership::ProviderAutoSend => self::ObserveOnlyWriteActivationSlot,
        };
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $expected
     */
    private function assertExactKeys(array $payload, array $expected, string $path): void
    {
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new InvalidArgumentException("The KSeF operation {$path} contains invalid fields.");
        }
    }

    /** @param array<array-key, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("The KSeF operation {$key} must be a string.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private function nullableString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("The KSeF operation {$key} must be null or a string.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private function nullableBoolean(array $payload, string $key): ?bool
    {
        $value = $payload[$key] ?? null;

        if ($value !== null && ! is_bool($value)) {
            throw new InvalidArgumentException("The KSeF operation {$key} must be null or a boolean.");
        }

        return $value;
    }
}
