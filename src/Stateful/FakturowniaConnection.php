<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful;

use Cieplik206\Fakturownia\Client\FakturowniaClient;
use Cieplik206\Fakturownia\Read\Administration\AdministrationOperatorReference;
use Cieplik206\Fakturownia\Read\Administration\AdministrationReadScope;
use Cieplik206\Fakturownia\Read\Data\AccountInvoiceListQuery;
use Cieplik206\Fakturownia\Read\Data\AccountInvoiceReadPage;
use Cieplik206\Fakturownia\Read\FakturowniaReadClient;
use Cieplik206\Fakturownia\Stateful\Ksef\InvoiceKsefStateQuery;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefStateReader;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class FakturowniaConnection implements JsonSerializable
{
    private SensitiveParameterValue $key;

    private SensitiveParameterValue $client;

    public function __construct(
        #[SensitiveParameter] ConnectionKey $key,
        public DeploymentStage $deploymentStage,
        #[SensitiveParameter] FakturowniaClient $client,
        private ?OperationQuery $operationQuery = null,
        private ?KsefStateReader $ksefStateReader = null,
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

    public function read(): FakturowniaReadClient
    {
        return $this->client()->read();
    }

    public function operations(): FakturowniaOperations
    {
        if (! $this->operationQuery instanceof OperationQuery) {
            throw new LogicException('Operation Query is unavailable for this Fakturownia connection.');
        }

        return new FakturowniaOperations($this->key(), $this->operationQuery);
    }

    public function ksefStates(): InvoiceKsefStateQuery
    {
        if (! $this->ksefStateReader instanceof KsefStateReader) {
            throw new LogicException('KSeF state query is unavailable for this Fakturownia connection.');
        }

        return new InvoiceKsefStateQuery($this->key(), $this->ksefStateReader);
    }

    public function accountInvoices(
        #[SensitiveParameter] AdministrationOperatorReference $operator,
        AccountInvoiceListQuery $query = new AccountInvoiceListQuery,
    ): AccountInvoiceReadPage {
        $scope = new AdministrationReadScope($this->key(), $operator);

        return $this->read()->accountInvoices()->list($scope, $query);
    }

    /** @return array{key: string, deployment_stage: string, client: string, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'key' => '[REDACTED]',
            'deployment_stage' => $this->deploymentStage->value,
            'client' => $this->client()::class,
            'credentials' => '[REDACTED]',
        ];
    }

    /** @return array{key: string, deployment_stage: string, client: string, credentials: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Connections cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Connections cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Connections cannot be unserialized.');
    }

    private function client(): FakturowniaClient
    {
        $client = $this->client->getValue();

        if (! $client instanceof FakturowniaClient) {
            throw new LogicException('The connection client is corrupted.');
        }

        return $client;
    }
}
