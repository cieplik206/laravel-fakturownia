<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Resources;

use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResultCodec;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceSnapshotProtector;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Resources\ProtectedInvoiceResourceSnapshot;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use JsonException;
use LogicException;
use SensitiveParameterValue;

final class SodiumInvoiceResourceSnapshotProtector implements InvoiceResourceSnapshotProtector
{
    private const string AssociatedDataProtocol = 'cieplik206.fakturownia.invoice-resource.snapshot.v1';

    /** @var array<int, SensitiveParameterValue> */
    private array $keys = [];

    private int $activeVersion;

    public function __construct(private readonly Repository $config)
    {
        $this->activeVersion = $this->configuredActiveVersion();

        foreach ($this->configuredKeys() as $version => $encodedKey) {
            $this->keys[$version] = new SensitiveParameterValue($this->decodeKey($encodedKey));
        }

        if (! isset($this->keys[$this->activeVersion])) {
            throw new InvalidArgumentException('The active invoice resource encryption key is not configured.');
        }
    }

    public function protect(InvoiceResourceProjectionPlan $plan): ProtectedInvoiceResourceSnapshot
    {
        $codec = new IssueInvoiceResultCodec;
        $plaintext = (new CanonicalJsonV1)->encode(new CanonicalObject($codec->encode($plan->snapshot)->toArray()));
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = $this->key($this->activeVersion);

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $this->associatedData(
                    $plan->resourceId,
                    $plan->connectionKey,
                    $plan->operationId,
                    $plan->snapshotFingerprint->keyVersion,
                    $plan->snapshotFingerprint->hex,
                ),
                $nonce,
                $key,
            );
            $nonceBase64 = sodium_bin2base64($nonce, SODIUM_BASE64_VARIANT_ORIGINAL);
            $ciphertextBase64 = sodium_bin2base64($ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL);

            return new ProtectedInvoiceResourceSnapshot(
                InvoiceResourceProjectionPlan::SnapshotSchemaVersion,
                $this->activeVersion,
                'XCHACHA20-POLY1305',
                $nonceBase64,
                $ciphertextBase64,
                hash('sha256', $ciphertextBase64),
                $plan->snapshotFingerprint,
            );
        } finally {
            sodium_memzero($key);
        }
    }

    public function recover(
        InvoiceResourceId $resourceId,
        ConnectionKey $connectionKey,
        OperationId $operationId,
        ProtectedInvoiceResourceSnapshot $snapshot,
    ): IssueInvoiceResult {
        $key = $this->key($snapshot->encryptionKeyVersion);

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                sodium_base642bin($snapshot->ciphertextBase64, SODIUM_BASE64_VARIANT_ORIGINAL),
                $this->associatedData(
                    $resourceId,
                    $connectionKey,
                    $operationId,
                    $snapshot->fingerprint->keyVersion,
                    $snapshot->fingerprint->hex,
                ),
                sodium_base642bin($snapshot->nonceBase64, SODIUM_BASE64_VARIANT_ORIGINAL),
                $key,
            );
        } finally {
            sodium_memzero($key);
        }

        if (! is_string($plaintext)) {
            throw new InvalidArgumentException('The invoice resource snapshot cannot be authenticated.');
        }

        try {
            $decoded = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The invoice resource snapshot is not canonical JSON.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('The invoice resource snapshot envelope is invalid.');
        }

        $result = (new IssueInvoiceResultCodec)->decode(EncodedResult::fromArray($decoded));

        if (! $result instanceof IssueInvoiceResult) {
            throw new LogicException('The invoice resource result codec returned an invalid type.');
        }

        return $result;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Invoice resource snapshot protectors cannot be serialized.');
    }

    /** @return array{keys: string, active_version: int} */
    public function __debugInfo(): array
    {
        return ['keys' => '[REDACTED]', 'active_version' => $this->activeVersion];
    }

    /** @return array<int, string> */
    private function configuredKeys(): array
    {
        $keys = $this->configurationValue(['fakturownia', 'resources', 'encryption', 'keys'], []);

        if (! is_array($keys) || $keys === []) {
            throw new InvalidArgumentException('Invoice resource encryption keys are not configured.');
        }

        $validated = [];

        foreach ($keys as $version => $key) {
            if (! is_int($version) && ! ctype_digit($version)) {
                throw new InvalidArgumentException('An invoice resource encryption key entry is invalid.');
            }

            $validated[(int) $version] = $this->encryptionKeyEntry($key);
        }

        return $validated;
    }

    private function configuredActiveVersion(): int
    {
        $version = $this->configurationValue(['fakturownia', 'resources', 'encryption', 'active_version'], 1);

        if (! is_int($version) || $version < 1 || $version > 65_535) {
            throw new InvalidArgumentException('The active invoice resource encryption key version is invalid.');
        }

        return $version;
    }

    private function encryptionKeyEntry(mixed $key): string
    {
        if (! is_string($key)) {
            throw new InvalidArgumentException('An invoice resource encryption key entry is invalid.');
        }

        return $key;
    }

    /** @param non-empty-list<string> $segments */
    private function configurationValue(array $segments, mixed $default): mixed
    {
        return $this->config->get(implode('.', $segments), $default);
    }

    private function decodeKey(string $encoded): string
    {
        if (str_starts_with($encoded, 'base64:')) {
            $encoded = substr($encoded, 7);
        }

        $key = base64_decode($encoded, true);

        if (! is_string($key)
            || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
            || base64_encode($key) !== $encoded) {
            throw new InvalidArgumentException('An invoice resource encryption key must be canonical base64 for 32 bytes.');
        }

        return $key;
    }

    private function key(int $version): string
    {
        $value = $this->keys[$version] ?? null;
        $key = $value?->getValue();

        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('The invoice resource encryption key version is unavailable.');
        }

        return $key;
    }

    private function associatedData(
        InvoiceResourceId $resourceId,
        ConnectionKey $connectionKey,
        OperationId $operationId,
        int $fingerprintKeyVersion,
        string $fingerprint,
    ): string {
        return $this->frame(self::AssociatedDataProtocol)
            .$this->frame((string) InvoiceResourceProjectionPlan::SnapshotSchemaVersion)
            .$this->frame($resourceId->value)
            .$this->frame($connectionKey->value)
            .$this->frame($operationId->value)
            .$this->frame((string) $fingerprintKeyVersion)
            .$this->frame($fingerprint);
    }

    private function frame(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}
