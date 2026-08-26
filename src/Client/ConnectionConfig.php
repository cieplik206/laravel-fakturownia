<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client;

use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\Client\ValueObjects\SecretValue;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class ConnectionConfig implements JsonSerializable
{
    private SensitiveParameterValue $baseUrl;

    private SensitiveParameterValue $apiToken;

    public function __construct(
        #[SensitiveParameter] BaseUrl $baseUrl,
        #[SensitiveParameter] SecretValue $apiToken,
        public int $connectTimeoutSeconds = 10,
        public int $requestTimeoutSeconds = 30,
    ) {
        $this->baseUrl = new SensitiveParameterValue($baseUrl);
        $this->apiToken = new SensitiveParameterValue($apiToken);

        if ($connectTimeoutSeconds < 1 || $connectTimeoutSeconds > 60) {
            throw new InvalidArgumentException('The connection timeout must be between 1 and 60 seconds.');
        }

        if ($requestTimeoutSeconds < $connectTimeoutSeconds || $requestTimeoutSeconds > 300) {
            throw new InvalidArgumentException('The request timeout must be at least the connection timeout and at most 300 seconds.');
        }
    }

    public function createClient(): FakturowniaClient
    {
        return $this->apiToken()->createClient(
            $this->baseUrl(),
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }

    public function baseUrl(): BaseUrl
    {
        $baseUrl = $this->baseUrl->getValue();

        if (! $baseUrl instanceof BaseUrl) {
            throw new LogicException('The base URL value is corrupted.');
        }

        return $baseUrl;
    }

    /** @return array{base_url: string, api_token: string, connect_timeout_seconds: int, request_timeout_seconds: int} */
    public function __debugInfo(): array
    {
        return [
            'base_url' => '[REDACTED]',
            'api_token' => '[REDACTED]',
            'connect_timeout_seconds' => $this->connectTimeoutSeconds,
            'request_timeout_seconds' => $this->requestTimeoutSeconds,
        ];
    }

    /** @return array{base_url: string, api_token: string, connect_timeout_seconds: int, request_timeout_seconds: int} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Connection configurations cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Connection configurations cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Connection configurations cannot be unserialized.');
    }

    private function apiToken(): SecretValue
    {
        $apiToken = $this->apiToken->getValue();

        if (! $apiToken instanceof SecretValue) {
            throw new LogicException('The API token value is corrupted.');
        }

        return $apiToken;
    }
}
