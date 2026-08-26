<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel;

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\Client\ValueObjects\SecretValue;
use Cieplik206\Fakturownia\Stateful\ConnectionProfile;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Cieplik206\Fakturownia\Stateful\Exceptions\ConnectionConfigurationInvalid;
use Cieplik206\Fakturownia\Stateful\Exceptions\ConnectionConfigurationReason;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class ConfigConnectionResolver implements ConnectionResolver, JsonSerializable
{
    private SensitiveParameterValue $config;

    public function __construct(#[SensitiveParameter] Repository $config)
    {
        $this->config = new SensitiveParameterValue($config);
    }

    public function resolve(#[SensitiveParameter] ConnectionKey $connectionKey): ConnectionProfile
    {
        $connections = $this->config()->get('fakturownia.connections');

        if (! is_array($connections) || ! array_key_exists($connectionKey->value, $connections)) {
            throw new ConnectionConfigurationInvalid(ConnectionConfigurationReason::NotConfigured);
        }

        $connection = $connections[$connectionKey->value];

        if (! is_array($connection)) {
            throw new ConnectionConfigurationInvalid(ConnectionConfigurationReason::InvalidShape);
        }

        try {
            return $this->profile($connectionKey, $connection);
        } catch (InvalidArgumentException) {
            throw new ConnectionConfigurationInvalid(ConnectionConfigurationReason::InvalidValue);
        }
    }

    /** @param array<array-key, mixed> $connection */
    private function profile(
        #[SensitiveParameter] ConnectionKey $connectionKey,
        #[SensitiveParameter] array $connection,
    ): ConnectionProfile {
        $deploymentStage = $this->requiredString($connection, 'deployment_stage');
        $resolvedDeploymentStage = DeploymentStage::tryFrom($deploymentStage);

        if ($resolvedDeploymentStage === null) {
            throw new InvalidArgumentException('The deployment stage is unsupported.');
        }

        return new ConnectionProfile(
            $connectionKey,
            $resolvedDeploymentStage,
            new ConnectionConfig(
                BaseUrl::fromString(
                    $this->requiredString($connection, 'base_url'),
                    $this->allowedHosts($connection),
                ),
                SecretValue::fromPlaintext($this->requiredString($connection, 'token')),
                $this->requiredInt($connection, 'connect_timeout_seconds'),
                $this->requiredInt($connection, 'request_timeout_seconds'),
            ),
        );
    }

    /** @param array<array-key, mixed> $connection */
    private function requiredString(#[SensitiveParameter] array $connection, string $key): string
    {
        $value = $connection[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The [{$key}] value must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $connection */
    private function requiredInt(#[SensitiveParameter] array $connection, string $key): int
    {
        $value = $connection[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException("The [{$key}] value must be an integer.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $connection
     * @return list<string>
     */
    private function allowedHosts(#[SensitiveParameter] array $connection): array
    {
        $allowedHosts = $connection['allowed_hosts'] ?? null;

        if (! is_array($allowedHosts)) {
            throw new InvalidArgumentException('The [allowed_hosts] value must be a list of exact hosts.');
        }

        foreach ($allowedHosts as $allowedHost) {
            if (! is_string($allowedHost)) {
                throw new InvalidArgumentException('Each allowlisted host must be a string.');
            }
        }

        return array_values($allowedHosts);
    }

    /** @return array{configuration: string, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'configuration' => '[REDACTED]',
            'credentials' => '[REDACTED]',
        ];
    }

    /** @return array{configuration: string, credentials: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Connection resolvers cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Connection resolvers cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Connection resolvers cannot be unserialized.');
    }

    private function config(): Repository
    {
        $config = $this->config->getValue();

        if (! $config instanceof Repository) {
            throw new LogicException('The connection configuration repository is corrupted.');
        }

        return $config;
    }
}
