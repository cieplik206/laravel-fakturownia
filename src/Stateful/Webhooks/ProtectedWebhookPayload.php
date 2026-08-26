<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks;

use InvalidArgumentException;
use LogicException;

final readonly class ProtectedWebhookPayload
{
    public function __construct(
        public ?string $providerDeliveryIdHmac,
        public string $payloadHmac,
        public EncryptedWebhookPayload $encryptedPayload,
    ) {
        if ($providerDeliveryIdHmac !== null) {
            $this->assertHmac($providerDeliveryIdHmac, 'delivery ID');
        }

        $this->assertHmac($payloadHmac, 'payload');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Protected webhook payloads cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Protected webhook payloads cannot be unserialized.');
    }

    private function assertHmac(string $value, string $field): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The webhook {$field} HMAC is invalid.");
        }
    }
}
