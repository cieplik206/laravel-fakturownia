<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful;

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;
use Cieplik206\Fakturownia\Client\FakturowniaClient;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class ConnectionProfile implements JsonSerializable
{
    private SensitiveParameterValue $key;

    private SensitiveParameterValue $client;

    public function __construct(
        #[SensitiveParameter] ConnectionKey $key,
        public DeploymentStage $deploymentStage,
        #[SensitiveParameter] ConnectionConfig $client,
    ) {
        $this->key = new SensitiveParameterValue($key);
        $this->client = new SensitiveParameterValue($client);
    }

    public function key(): ConnectionKey
    {
        $key = $this->key->getValue();

        if (! $key instanceof ConnectionKey) {
            throw new LogicException('The connection key is corrupted.');
        }

        return $key;
    }

    public function createClient(ClientFactory $clientFactory): FakturowniaClient
    {
        return $clientFactory->make($this->client());
    }

    /** @return array{key: string, deployment_stage: string, base_url: string, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'key' => '[REDACTED]',
            'deployment_stage' => $this->deploymentStage->value,
            'base_url' => '[REDACTED]',
            'credentials' => '[REDACTED]',
        ];
    }

    /** @return array{key: string, deployment_stage: string, base_url: string, credentials: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Connection profiles cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Connection profiles cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Connection profiles cannot be unserialized.');
    }

    private function client(): ConnectionConfig
    {
        $client = $this->client->getValue();

        if (! $client instanceof ConnectionConfig) {
            throw new LogicException('The connection client configuration is corrupted.');
        }

        return $client;
    }
}
