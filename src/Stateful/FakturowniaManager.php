<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful;

use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\Fakturownia\Stateful\Exceptions\ConnectionConfigurationInvalid;
use Cieplik206\Fakturownia\Stateful\Exceptions\ConnectionConfigurationReason;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class FakturowniaManager implements JsonSerializable
{
    private SensitiveParameterValue $connectionResolver;

    private SensitiveParameterValue $clientFactory;

    private ?OperationQuery $operationQuery;

    public function __construct(
        #[SensitiveParameter] ConnectionResolver $connectionResolver,
        #[SensitiveParameter] ClientFactory $clientFactory,
        ?OperationQuery $operationQuery = null,
    ) {
        $this->connectionResolver = new SensitiveParameterValue($connectionResolver);
        $this->clientFactory = new SensitiveParameterValue($clientFactory);
        $this->operationQuery = $operationQuery;
    }

    public function connection(#[SensitiveParameter] ConnectionKey $connectionKey): FakturowniaConnection
    {
        $profile = $this->connectionResolver()->resolve($connectionKey);

        if (! $profile->key()->equals($connectionKey)) {
            throw new ConnectionConfigurationInvalid(ConnectionConfigurationReason::ResolvedKeyMismatch);
        }

        return new FakturowniaConnection(
            $connectionKey,
            $profile->deploymentStage,
            $profile->createClient($this->clientFactory()),
            $this->operationQuery,
        );
    }

    /** @return array{resolver: string, client_factory: string, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'resolver' => '[REDACTED]',
            'client_factory' => '[REDACTED]',
            'credentials' => '[REDACTED]',
        ];
    }

    /** @return array{resolver: string, client_factory: string, credentials: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Fakturownia managers cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Fakturownia managers cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Fakturownia managers cannot be unserialized.');
    }

    private function connectionResolver(): ConnectionResolver
    {
        $connectionResolver = $this->connectionResolver->getValue();

        if (! $connectionResolver instanceof ConnectionResolver) {
            throw new LogicException('The connection resolver is corrupted.');
        }

        return $connectionResolver;
    }

    private function clientFactory(): ClientFactory
    {
        $clientFactory = $this->clientFactory->getValue();

        if (! $clientFactory instanceof ClientFactory) {
            throw new LogicException('The client factory is corrupted.');
        }

        return $clientFactory;
    }
}
