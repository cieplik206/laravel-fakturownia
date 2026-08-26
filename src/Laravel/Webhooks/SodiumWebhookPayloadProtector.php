<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Webhooks;

use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookPayloadProtector;
use Cieplik206\Fakturownia\Stateful\Webhooks\EncryptedWebhookPayload;
use Cieplik206\Fakturownia\Stateful\Webhooks\ProtectedWebhookPayload;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookDelivery;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final class SodiumWebhookPayloadProtector implements WebhookPayloadProtector
{
    private const DeduplicationKdfContext = 'FKTWDID1';

    private const EncryptionKdfContext = 'FKTWENC1';

    private SensitiveParameterValue $deduplicationMasterKey;

    private SensitiveParameterValue $encryptionMasterKey;

    public function __construct(
        #[SensitiveParameter] string $deduplicationMasterKey,
        #[SensitiveParameter] string $encryptionMasterKey,
        private readonly int $keyVersion,
    ) {
        if (strlen($deduplicationMasterKey) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            throw new InvalidArgumentException('The stable webhook deduplication master key must contain exactly 32 bytes.');
        }

        if (strlen($encryptionMasterKey) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            throw new InvalidArgumentException('The webhook encryption master key must contain exactly 32 bytes.');
        }

        if ($keyVersion < 1 || $keyVersion > 65_535) {
            throw new InvalidArgumentException('The webhook payload key version is invalid.');
        }

        $this->deduplicationMasterKey = new SensitiveParameterValue($deduplicationMasterKey);
        $this->encryptionMasterKey = new SensitiveParameterValue($encryptionMasterKey);
    }

    public function protect(WebhookDelivery $delivery, DateTimeImmutable $receivedAt): ProtectedWebhookPayload
    {
        if ($receivedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The webhook protection time must use UTC.');
        }

        $deduplicationMasterKey = $this->deduplicationMasterKey->getValue();
        $encryptionMasterKey = $this->encryptionMasterKey->getValue();

        if (! is_string($deduplicationMasterKey)
            || strlen($deduplicationMasterKey) !== SODIUM_CRYPTO_KDF_KEYBYTES
            || ! is_string($encryptionMasterKey)
            || strlen($encryptionMasterKey) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            throw new LogicException('A webhook protection master key is corrupted.');
        }

        $identityKey = sodium_crypto_kdf_derive_from_key(
            SODIUM_CRYPTO_AUTH_KEYBYTES,
            1,
            self::DeduplicationKdfContext,
            $deduplicationMasterKey,
        );
        $encryptionKey = sodium_crypto_kdf_derive_from_key(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $this->keyVersion,
            self::EncryptionKdfContext,
            $encryptionMasterKey,
        );

        try {
            $rawBody = $delivery->rawBody();
            $payloadHmac = hash_hmac('sha256', $this->frame('payload:v1').$this->frame($rawBody), $identityKey);
            $deliveryIdHmac = $delivery->providerDeliveryId === null
                ? null
                : hash_hmac(
                    'sha256',
                    $this->frame('delivery:v1')
                        .$this->frame($delivery->connectionKey)
                        .$this->frame((string) $delivery->providerDeliveryId),
                    $identityKey,
                );
            $receivedAtCanonical = $receivedAt->format('Y-m-d\TH:i:s.u\Z');
            $associatedData = $this->frame('cieplik206.fakturownia.webhook-payload.v1')
                .$this->frame('key-version:'.$this->keyVersion)
                .$this->frame($delivery->connectionKey)
                .$this->frame($deliveryIdHmac ?? '-')
                .$this->frame($payloadHmac)
                .$this->frame($receivedAtCanonical);
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $rawBody,
                $associatedData,
                $nonce,
                $encryptionKey,
            );
            $nonceBase64 = sodium_bin2base64($nonce, SODIUM_BASE64_VARIANT_ORIGINAL);
            $ciphertextBase64 = sodium_bin2base64($ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL);

            return new ProtectedWebhookPayload(
                $deliveryIdHmac,
                $payloadHmac,
                new EncryptedWebhookPayload(
                    $this->keyVersion,
                    EncryptedWebhookPayload::Algorithm,
                    $nonceBase64,
                    $ciphertextBase64,
                    hash('sha256', $ciphertextBase64),
                ),
            );
        } finally {
            sodium_memzero($identityKey);
            sodium_memzero($encryptionKey);
        }
    }

    /** @return array{deduplication_master_key: string, encryption_master_key: string, key_version: int} */
    public function __debugInfo(): array
    {
        return [
            'deduplication_master_key' => '[REDACTED]',
            'encryption_master_key' => '[REDACTED]',
            'key_version' => $this->keyVersion,
        ];
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Webhook payload protectors cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Webhook payload protectors cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Webhook payload protectors cannot be unserialized.');
    }

    private function frame(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}
