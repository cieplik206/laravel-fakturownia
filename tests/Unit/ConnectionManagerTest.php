<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;
use Cieplik206\Fakturownia\Client\DefaultClientFactory;
use Cieplik206\Fakturownia\Client\FakturowniaClient;
use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\Client\ValueObjects\SecretValue;
use Cieplik206\Fakturownia\Laravel\ConfigConnectionResolver;
use Cieplik206\Fakturownia\Stateful\ConnectionProfile;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Cieplik206\Fakturownia\Stateful\Exceptions\ConnectionConfigurationInvalid;
use Cieplik206\Fakturownia\Stateful\Exceptions\ConnectionConfigurationReason;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\Contracts\ScopedOperationQuery;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshot;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshotBatch;
use Illuminate\Config\Repository;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;

it('isolates clients profiles and credentials for every connection', function (): void {
    $config = new Repository([
        'fakturownia' => [
            'connections' => [
                'sales' => connectionFixture('sales.fakturownia.pl', 'sales-token'),
                'accounting' => connectionFixture('accounting.fakturownia.pl', 'accounting-token'),
            ],
        ],
    ]);
    $clientFactory = new RecordingClientFactory;
    $manager = new FakturowniaManager(
        new ConfigConnectionResolver($config),
        $clientFactory,
    );

    $sales = $manager->connection(new ConnectionKey('sales'));
    $accounting = $manager->connection(new ConnectionKey('accounting'));
    $secondSales = $manager->connection(new ConnectionKey('sales'));
    $connectionReflection = new ReflectionClass($sales);

    expect($sales->key()->value)->toBe('sales')
        ->and($accounting->key()->value)->toBe('accounting')
        ->and($sales->deploymentStage)->toBe(DeploymentStage::Production)
        ->and($accounting->deploymentStage)->toBe(DeploymentStage::Production)
        ->and($sales)->not->toBe($accounting)
        ->and($sales)->not->toBe($secondSales)
        ->and($clientFactory->madeBaseUrls)->toBe([
            'https://sales.fakturownia.pl',
            'https://accounting.fakturownia.pl',
            'https://sales.fakturownia.pl',
        ])
        ->and($clientFactory->madeCredentialDigests[0])->not->toBe($clientFactory->madeCredentialDigests[1])
        ->and($clientFactory->madeClients[0])->not->toBe($clientFactory->madeClients[1])
        ->and($connectionReflection->getMethod('client')->isPrivate())->toBeTrue()
        ->and($connectionReflection->hasMethod('profile'))->toBeFalse()
        ->and($connectionReflection->getProperty('client')->isPrivate())->toBeTrue();
});

it('keeps deployment metadata separate from contract probe evidence', function (): void {
    $stages = array_map(
        static fn (DeploymentStage $stage): string => $stage->value,
        DeploymentStage::cases(),
    );

    expect($stages)->toBe(['production', 'non_production'])
        ->not->toContain('demo_pl')
        ->not->toContain('demo_regional')
        ->not->toContain('ksef_demo');
});

it('delegates operation reads through the exact Fakturownia connection scope', function (): void {
    $query = new RecordingFakturowniaOperationQuery;
    $manager = new FakturowniaManager(
        new ConfigConnectionResolver(new Repository([
            'fakturownia' => [
                'connections' => [
                    'sales' => connectionFixture('sales.fakturownia.pl', 'sales-token'),
                ],
            ],
        ])),
        new DefaultClientFactory,
        $query,
    );
    $operationId = new OperationId('01J0000000000000000000000T');
    $operations = $manager->connection(new ConnectionKey('sales'))->operations();

    expect($operations->find($operationId))->toBeNull()
        ->and($operations->findMany([$operationId])->missingOperationIds()[0]->equals($operationId))->toBeTrue()
        ->and($query->scopes)->toHaveCount(2)
        ->and($query->scopes[0]->provider->value)->toBe('fakturownia')
        ->and($query->scopes[0]->connection->value)->toBe('sales')
        ->and($query->scopes[1]->equals($query->scopes[0]))->toBeTrue();
});

it('fails closed when a connection is missing or invalid', function (array $connections, string $key): void {
    $resolver = new ConfigConnectionResolver(new Repository([
        'fakturownia' => ['connections' => $connections],
    ]));

    expect(fn () => $resolver->resolve(new ConnectionKey($key)))
        ->toThrow(ConnectionConfigurationInvalid::class);
})->with([
    'missing' => [[], 'missing'],
    'not an array' => [['bad' => 'invalid'], 'bad'],
    'unknown deployment stage' => [[
        'bad' => array_merge(connectionFixture('bad.fakturownia.pl', 'token'), ['deployment_stage' => 'unknown']),
    ], 'bad'],
    'contract probe environment is not a deployment stage' => [[
        'bad' => array_merge(connectionFixture('bad.fakturownia.pl', 'token'), ['deployment_stage' => 'demo_pl']),
    ], 'bad'],
    'host is not explicitly allowlisted' => [[
        'bad' => array_merge(connectionFixture('bad.fakturownia.pl', 'token'), ['allowed_hosts' => ['other.fakturownia.pl']]),
    ], 'bad'],
]);

it('reports configuration failures without account identifiers or credentials', function (): void {
    $resolver = new ConfigConnectionResolver(new Repository([
        'fakturownia' => [
            'connections' => [
                'sensitive-account-key' => array_merge(
                    connectionFixture('private-tenant.fakturownia.pl', 'private-api-token'),
                    ['token' => ' private-api-token'],
                ),
            ],
        ],
    ]));

    try {
        $resolver->resolve(new ConnectionKey('sensitive-account-key'));
    } catch (ConnectionConfigurationInvalid $exception) {
        expect($exception->reason)->toBe(ConnectionConfigurationReason::InvalidValue)
            ->and($exception->getMessage())->not->toContain('sensitive-account-key')
            ->and($exception->getMessage())->not->toContain('private-tenant.fakturownia.pl')
            ->and($exception->getMessage())->not->toContain('private-api-token')
            ->and($exception->getPrevious())->toBeNull();

        return;
    }

    throw new RuntimeException('The invalid connection configuration was unexpectedly accepted.');
});

it('rejects a resolver profile for a different connection before building a client', function (): void {
    $resolver = new class implements ConnectionResolver
    {
        public function resolve(ConnectionKey $connectionKey): ConnectionProfile
        {
            return new ConnectionProfile(
                new ConnectionKey('accounting'),
                DeploymentStage::Production,
                new ConnectionConfig(
                    BaseUrl::fromString('https://accounting.fakturownia.pl', ['accounting.fakturownia.pl']),
                    SecretValue::fromPlaintext('accounting-token'),
                ),
            );
        }
    };
    $clientFactory = new class implements ClientFactory
    {
        public bool $called = false;

        public function make(ConnectionConfig $connectionConfig): FakturowniaClient
        {
            $this->called = true;

            return (new DefaultClientFactory)->make($connectionConfig);
        }
    };
    $manager = new FakturowniaManager($resolver, $clientFactory);

    try {
        $manager->connection(new ConnectionKey('sales'));
    } catch (ConnectionConfigurationInvalid $exception) {
        expect($exception->reason)->toBe(ConnectionConfigurationReason::ResolvedKeyMismatch)
            ->and($clientFactory->called)->toBeFalse();

        return;
    }

    throw new RuntimeException('The mismatched connection profile was unexpectedly accepted.');
});

/** @return array{deployment_stage: string, base_url: string, allowed_hosts: list<string>, token: string, connect_timeout_seconds: int, request_timeout_seconds: int} */
function connectionFixture(string $host, string $token): array
{
    return [
        'deployment_stage' => 'production',
        'base_url' => "https://{$host}",
        'allowed_hosts' => [$host],
        'token' => $token,
        'connect_timeout_seconds' => 5,
        'request_timeout_seconds' => 20,
    ];
}

function clientCredentialDigest(FakturowniaClient $client): string
{
    $protectedConnector = (new ReflectionProperty($client, 'connector'))->getValue($client);

    if (! $protectedConnector instanceof SensitiveParameterValue) {
        throw new RuntimeException('The test-only transport seam did not resolve protected connector storage.');
    }

    $connector = $protectedConnector->getValue();

    if (! $connector instanceof Connector) {
        throw new RuntimeException('The test-only transport seam did not resolve a connector.');
    }

    $pendingRequest = new PendingRequest($connector, new class extends Request
    {
        protected Method $method = Method::GET;

        public function resolveEndpoint(): string
        {
            return '/account.json';
        }
    });
    $apiToken = $pendingRequest->query()->get('api_token');

    if (! is_string($apiToken)) {
        throw new RuntimeException('The test-only transport seam did not resolve the API token.');
    }

    return hash('sha256', $apiToken);
}

final class RecordingClientFactory implements ClientFactory
{
    /** @var list<string> */
    public array $madeBaseUrls = [];

    /** @var list<string> */
    public array $madeCredentialDigests = [];

    /** @var list<FakturowniaClient> */
    public array $madeClients = [];

    public function make(ConnectionConfig $connectionConfig): FakturowniaClient
    {
        $client = (new DefaultClientFactory)->make($connectionConfig);

        $this->madeBaseUrls[] = (string) $connectionConfig->baseUrl();
        $this->madeCredentialDigests[] = clientCredentialDigest($client);
        $this->madeClients[] = $client;

        return $client;
    }
}

final class RecordingFakturowniaOperationQuery implements OperationQuery
{
    /** @var list<IntegrationScope> */
    public array $scopes = [];

    public function within(IntegrationScope|IntegrationScopeSet $scopes): ScopedOperationQuery
    {
        if (! $scopes instanceof IntegrationScope) {
            throw new InvalidArgumentException('The Fakturownia wrapper must authorize exactly one scope.');
        }

        $this->scopes[] = $scopes;

        return new EmptyFakturowniaScopedOperationQuery($scopes);
    }
}

final readonly class EmptyFakturowniaScopedOperationQuery implements ScopedOperationQuery
{
    public function __construct(private IntegrationScope $scope) {}

    public function find(OperationId $operationId): ?OperationSnapshot
    {
        return null;
    }

    public function findMany(iterable $operationIds): OperationSnapshotBatch
    {
        return new OperationSnapshotBatch(
            IntegrationScopeSet::from([$this->scope]),
            $operationIds,
            [],
        );
    }
}
