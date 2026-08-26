<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final class WebhookDelivery implements JsonSerializable
{
    public const MaximumPayloadBytes = 1_048_576;

    public const MaximumHeaders = 64;

    public const MaximumHeaderValueBytes = 8_192;

    public const MaximumHeadersBytes = 32_768;

    private SensitiveParameterValue $rawBody;

    private SensitiveParameterValue $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $connectionKey,
        public readonly ?ProviderWebhookDeliveryId $providerDeliveryId,
        #[SensitiveParameter] string $rawBody,
        #[SensitiveParameter] array $headers,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $connectionKey) !== 1) {
            throw new InvalidArgumentException('The webhook connection key is invalid.');
        }

        if ($rawBody === '' || strlen($rawBody) > self::MaximumPayloadBytes) {
            throw new InvalidArgumentException('The webhook payload must contain between 1 byte and 1 MiB.');
        }

        $this->rawBody = new SensitiveParameterValue($rawBody);
        $this->headers = new SensitiveParameterValue($this->normalizeHeaders($headers));
    }

    public function rawBody(): string
    {
        $rawBody = $this->rawBody->getValue();

        if (! is_string($rawBody)) {
            throw new LogicException('The webhook payload is corrupted.');
        }

        return $rawBody;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        $headers = $this->headers->getValue();

        if (! is_array($headers)) {
            throw new LogicException('The webhook headers are corrupted.');
        }

        return $headers;
    }

    /** @return array{connection_key: string, delivery_id: string, payload: string, headers: string} */
    public function __debugInfo(): array
    {
        return [
            'connection_key' => $this->connectionKey,
            'delivery_id' => '[REDACTED]',
            'payload' => '[REDACTED]',
            'headers' => '[REDACTED]',
        ];
    }

    /** @return array{connection_key: string, delivery_id: string, payload: string, headers: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Webhook deliveries cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Webhook deliveries cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Webhook deliveries cannot be unserialized.');
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        if (count($headers) > self::MaximumHeaders) {
            throw new InvalidArgumentException('The webhook header count exceeds the supported limit.');
        }

        $normalized = [];
        $totalBytes = 0;

        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,127}$/D', $name) !== 1) {
                throw new InvalidArgumentException('A webhook header name is invalid.');
            }

            if (strlen($value) > self::MaximumHeaderValueBytes
                || preg_match('//u', $value) !== 1
                || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('A webhook header value is invalid.');
            }

            $normalizedName = strtolower($name);

            if (array_key_exists($normalizedName, $normalized)) {
                throw new InvalidArgumentException('Webhook header names must be unique after case normalization.');
            }

            $totalBytes += strlen($normalizedName) + strlen($value);

            if ($totalBytes > self::MaximumHeadersBytes) {
                throw new InvalidArgumentException('The webhook header bytes exceed the supported limit.');
            }

            $normalized[$normalizedName] = $value;
        }

        ksort($normalized);

        return $normalized;
    }
}
