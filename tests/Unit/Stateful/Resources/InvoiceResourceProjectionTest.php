<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceProjectionStore;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceReader;
use Cieplik206\Fakturownia\Stateful\Resources\Exceptions\InvoiceResourceProjectionConflict;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResource;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceQuery;
use Cieplik206\Fakturownia\Stateful\Resources\IssueInvoiceResourceProjectionMapper;
use Cieplik206\Fakturownia\Stateful\Resources\ProtectedInvoiceResourceSnapshot;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use DateTimeImmutable;
use LogicException;
use RuntimeException;

final readonly class S46IssueOperationView implements OperationView
{
    public function __construct(
        private OperationId $id,
        private IntegrationScope $integrationScope,
        private OperationType $type,
        private CanonicalObject $canonicalPayload,
    ) {}

    public function operationId(): OperationId
    {
        return $this->id;
    }

    public function scope(): IntegrationScope
    {
        return $this->integrationScope;
    }

    public function operationType(): OperationType
    {
        return $this->type;
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make(correlationId: 'workflow:invoice:1');
    }

    public function payload(): CanonicalObject
    {
        return $this->canonicalPayload;
    }
}

final class S46InMemoryInvoiceResourceStore implements InvoiceResourceProjectionStore, InvoiceResourceReader
{
    /** @var array<string, InvoiceResource> */
    public array $resources = [];

    public bool $failBeforeCommit = false;

    public function apply(InvoiceResourceProjectionPlan $plan): InvoiceResource
    {
        $remoteKey = $plan->connectionKey->value."\0".$plan->snapshot->remoteId();
        $localKey = $plan->connectionKey->value."\0".$plan->localReferenceType."\0".$plan->localReferenceHmac->hex;
        $existing = $this->resources[$remoteKey] ?? $this->resources[$localKey] ?? null;

        if ($existing instanceof InvoiceResource) {
            $plan->assertIdempotentWith($existing);

            return $existing;
        }

        $now = new DateTimeImmutable('2026-08-26T10:00:00.123456+00:00');
        $resource = new InvoiceResource(
            id: $plan->resourceId,
            connectionKey: $plan->connectionKey,
            localReferenceType: $plan->localReferenceType,
            localReferenceHmac: $plan->localReferenceHmac,
            remoteId: $plan->snapshot->remoteId(),
            remoteNumber: $plan->snapshot->remoteNumber(),
            createdByOperationId: $plan->operationId,
            lastOperationId: $plan->operationId,
            snapshot: $plan->snapshot,
            snapshotFingerprint: $plan->snapshotFingerprint,
            rowVersion: 1,
            createdAt: $now,
            lastSeenAt: $now,
            syncedAt: $now,
        );

        if ($this->failBeforeCommit) {
            throw new LogicException('Simulated kernel terminal transaction rollback.');
        }

        $this->resources[$remoteKey] = $resource;
        $this->resources[$localKey] = $resource;

        return $resource;
    }

    public function findById(ConnectionKey $connectionKey, InvoiceResourceId $resourceId): ?InvoiceResource
    {
        foreach ($this->uniqueResources() as $resource) {
            if ($resource->connectionKey->equals($connectionKey) && $resource->id->equals($resourceId)) {
                return $resource;
            }
        }

        return null;
    }

    public function findByRemoteId(ConnectionKey $connectionKey, string $remoteId): ?InvoiceResource
    {
        return $this->resources[$connectionKey->value."\0".$remoteId] ?? null;
    }

    public function findByLocalReferenceDigests(
        ConnectionKey $connectionKey,
        string $localReferenceType,
        array $localReferenceDigests,
    ): ?InvoiceResource {
        foreach ($localReferenceDigests as $digest) {
            $resource = $this->resources[
                $connectionKey->value."\0".$localReferenceType."\0".$digest->hex
            ] ?? null;

            if ($resource instanceof InvoiceResource) {
                return $resource;
            }
        }

        return null;
    }

    /** @return list<InvoiceResource> */
    private function uniqueResources(): array
    {
        $resources = [];

        foreach ($this->resources as $resource) {
            $resources[(string) $resource->id] = $resource;
        }

        return array_values($resources);
    }
}

function s46Operation(
    string $operationId = '01K3K8N8G8V3A6R5T4Y2W1Q9P7',
    ?CanonicalObject $payload = null,
    string $provider = 'fakturownia',
    string $connection = 'sales',
    string $operationType = IssueInvoiceResourceProjectionMapper::OperationType,
): S46IssueOperationView {
    $draft = InvoiceFixtures::draft();
    $identity = RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
        InvoiceFixtures::scope(),
        'OID-ORDER-123',
        'ORDER-123',
        OidUniquenessGate::notPassed(),
    );

    return new S46IssueOperationView(
        new OperationId($operationId),
        IntegrationScope::of($provider, $connection),
        new OperationType($operationType),
        $payload ?? (new IssueInvoicePayloadCodec)->encode(new IssueInvoiceCommand($draft, $identity)),
    );
}

function s46IssueResult(
    string $remoteId = '9001',
    string $status = 'issued',
    ?Money $totalGross = null,
    ?string $oid = 'OID-ORDER-123',
): IssueInvoiceResult {
    $draft = InvoiceFixtures::draft();

    return new IssueInvoiceResult(
        remoteId: $remoteId,
        number: 'FV/2026/08/1',
        kind: $draft->kind,
        status: $status,
        issueDate: $draft->issueDate,
        buyerTaxNumber: '1234567890',
        totalGross: $totalGross ?? $draft->totalGross(),
        oid: $oid,
        positions: $draft->positions,
    );
}

it('maps only the canonical issue operation and typed result into an immutable provider resource plan', function (): void {
    $plan = (new IssueInvoiceResourceProjectionMapper(InvoiceFixtures::hmac()))
        ->map(s46Operation(), s46IssueResult());

    expect($plan->resourceId->value)->toBe('01K3K8N8G8V3A6R5T4Y2W1Q9P7')
        ->and($plan->connectionKey->value)->toBe('sales')
        ->and($plan->localReferenceType)->toBe(InvoiceResource::LocalReferenceType)
        ->and($plan->localReferenceHmac->hex)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plan->snapshotFingerprint->hex)->toMatch('/^[a-f0-9]{64}$/');

    expect(fn (): string => serialize($plan))->toThrow(LogicException::class);
});

it('rolls back without a mapping and replays one projection idempotently', function (): void {
    $plan = (new IssueInvoiceResourceProjectionMapper(InvoiceFixtures::hmac()))
        ->map(s46Operation(), s46IssueResult());
    $store = new S46InMemoryInvoiceResourceStore;
    $store->failBeforeCommit = true;

    expect(fn () => $store->apply($plan))->toThrow(LogicException::class)
        ->and($store->resources)->toBe([]);

    $store->failBeforeCommit = false;
    $first = $store->apply($plan);
    $second = $store->apply($plan);

    expect($second)->toBe($first)
        ->and(count($store->resources))->toBe(2)
        ->and($first->rowVersion)->toBe(1);
});

it('fails closed on remote identity collision and durable snapshot drift', function (): void {
    $mapper = new IssueInvoiceResourceProjectionMapper(InvoiceFixtures::hmac());
    $store = new S46InMemoryInvoiceResourceStore;
    $original = $mapper->map(s46Operation(), s46IssueResult());
    $store->apply($original);

    $otherOperation = $mapper->map(
        s46Operation(operationId: '01K3K8N8G8V3A6R5T4Y2W1Q9P8'),
        s46IssueResult(),
    );
    $driftedSnapshot = $mapper->map(s46Operation(), s46IssueResult(status: 'paid'));

    expect(fn () => $store->apply($otherOperation))
        ->toThrow(InvoiceResourceProjectionConflict::class)
        ->and(fn () => $store->apply($driftedSnapshot))
        ->toThrow(InvoiceResourceProjectionConflict::class);
});

it('rejects mismatched payload result scope type oid money and result class before storage', function (): void {
    $mapper = new IssueInvoiceResourceProjectionMapper(InvoiceFixtures::hmac());
    $wrongResult = new class implements OperationResult
    {
        public function resultType(): string
        {
            return 'fakturownia.invoice.other.result';
        }
    };

    expect(fn () => $mapper->map(s46Operation(provider: 'allegro'), s46IssueResult()))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mapper->map(s46Operation(connection: 'other'), s46IssueResult()))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mapper->map(
            s46Operation(operationType: 'fakturownia.invoice.correct'),
            s46IssueResult(),
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mapper->map(s46Operation(), s46IssueResult(oid: 'OTHER')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mapper->map(
            s46Operation(),
            s46IssueResult(totalGross: Money::fromDecimal('1.00', 'PLN')),
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mapper->map(s46Operation(), $wrongResult))
        ->toThrow(InvalidArgumentException::class);
});

it('pins the future kernel projection target without exposing a terminal transaction or event issuer', function (): void {
    $storeMethods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(InvoiceResourceProjectionStore::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect(InvoiceResourceProjectionPlan::TargetId)->toBe('fakturownia.invoice_resource')
        ->and(InvoiceResourceProjectionPlan::SchemaVersion)->toBe(1)
        ->and($storeMethods)->toBe(['apply'])
        ->and($storeMethods)->not->toContain('transaction', 'commit', 'terminalize', 'emit', 'dispatch');
});

it('reads resources only through an explicit connection scope and HMAC local lookup', function (): void {
    $plan = (new IssueInvoiceResourceProjectionMapper(InvoiceFixtures::hmac()))
        ->map(s46Operation(), s46IssueResult());
    $store = new S46InMemoryInvoiceResourceStore;
    $resource = $store->apply($plan);
    $query = new InvoiceResourceQuery(new ConnectionKey('sales'), $store, InvoiceFixtures::hmac());

    expect($query->find($resource->id))->toBe($resource)
        ->and($query->findByRemoteId('9001'))->toBe($resource)
        ->and($query->findByTransactionOrder('ORDER-123'))->toBe($resource)
        ->and($query->findByRemoteId('404'))->toBeNull()
        ->and(array_keys($store->resources))->not->toContain('ORDER-123');

    expect(fn (): string => serialize($query))->toThrow(LogicException::class);
});

it('rejects a reader that returns a resource outside the requested connection or identity', function (): void {
    $plan = (new IssueInvoiceResourceProjectionMapper(InvoiceFixtures::hmac()))
        ->map(s46Operation(), s46IssueResult());
    $store = new S46InMemoryInvoiceResourceStore;
    $resource = $store->apply($plan);
    $hostileReader = new class($resource) implements InvoiceResourceReader
    {
        public function __construct(private readonly InvoiceResource $resource) {}

        public function findById(ConnectionKey $connectionKey, InvoiceResourceId $resourceId): InvoiceResource
        {
            return $this->resource;
        }

        public function findByRemoteId(ConnectionKey $connectionKey, string $remoteId): InvoiceResource
        {
            return $this->resource;
        }

        public function findByLocalReferenceDigests(
            ConnectionKey $connectionKey,
            string $localReferenceType,
            array $localReferenceDigests,
        ): InvoiceResource {
            return $this->resource;
        }
    };
    $wrongScope = new InvoiceResourceQuery(new ConnectionKey('other'), $hostileReader, InvoiceFixtures::hmac());
    $rightScope = new InvoiceResourceQuery(new ConnectionKey('sales'), $hostileReader, InvoiceFixtures::hmac());

    expect(fn () => $wrongScope->find($resource->id))->toThrow(RuntimeException::class)
        ->and(fn () => $rightScope->findByRemoteId('different'))->toThrow(RuntimeException::class)
        ->and(fn () => $rightScope->findByTransactionOrder('OTHER'))->toThrow(RuntimeException::class);
});

it('validates and redacts an encrypted canonical resource snapshot envelope', function (): void {
    $plan = (new IssueInvoiceResourceProjectionMapper(InvoiceFixtures::hmac()))
        ->map(s46Operation(), s46IssueResult());
    $nonce = base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES));
    $ciphertext = base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES + 32));
    $snapshot = new ProtectedInvoiceResourceSnapshot(
        snapshotSchemaVersion: 1,
        encryptionKeyVersion: 3,
        cipher: 'XCHACHA20-POLY1305',
        nonceBase64: $nonce,
        ciphertextBase64: $ciphertext,
        ciphertextSha256: hash('sha256', $ciphertext),
        fingerprint: $plan->snapshotFingerprint,
    );

    expect($snapshot->__debugInfo())
        ->toMatchArray(['ciphertext' => '[REDACTED]', 'fingerprint' => '[REDACTED]']);

    expect(fn (): string => serialize($snapshot))->toThrow(LogicException::class)
        ->and(fn () => new ProtectedInvoiceResourceSnapshot(
            1,
            3,
            'AES-256-GCM',
            $nonce,
            $ciphertext,
            hash('sha256', $ciphertext),
            $plan->snapshotFingerprint,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProtectedInvoiceResourceSnapshot(
            1,
            3,
            'XCHACHA20-POLY1305',
            $nonce,
            $ciphertext,
            str_repeat('0', 64),
            $plan->snapshotFingerprint,
        ))->toThrow(InvalidArgumentException::class);
});
