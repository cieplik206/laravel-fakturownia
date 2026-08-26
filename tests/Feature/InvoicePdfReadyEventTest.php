<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Artifacts\DispatchInvoicePdfReady;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStatus;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactType;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactDescriptorReader;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfReadyResult;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfReadyResultCodec;
use Cieplik206\Fakturownia\Stateful\Events\InvoicePdfReady;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeScopedOperationQuery;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Events\OperationTerminalized;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeOperationSnapshot;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeOperationSnapshotBatch;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use LogicException;

final readonly class S69AuthoritativeQuery implements AuthoritativeOperationQuery
{
    public function __construct(private AuthoritativeOperationSnapshot $snapshot) {}

    public function within(IntegrationScope|IntegrationScopeSet $scopes): AuthoritativeScopedOperationQuery
    {
        return new S69ScopedAuthoritativeQuery($this->snapshot);
    }
}

final readonly class S69ScopedAuthoritativeQuery implements AuthoritativeScopedOperationQuery
{
    public function __construct(private AuthoritativeOperationSnapshot $snapshot) {}

    public function find(OperationId $operationId): ?AuthoritativeOperationSnapshot
    {
        return $this->snapshot->operationId->equals($operationId) ? $this->snapshot : null;
    }

    public function findMany(iterable $operationIds): AuthoritativeOperationSnapshotBatch
    {
        return new AuthoritativeOperationSnapshotBatch(
            IntegrationScopeSet::from([$this->snapshot->scope]),
            $operationIds,
            [$this->snapshot],
        );
    }
}

final readonly class S69ArtifactReader implements ArtifactDescriptorReader
{
    public function __construct(private ArtifactDescriptor $artifact) {}

    public function find(ConnectionKey $connectionKey, ArtifactId $artifactId): ?ArtifactDescriptor
    {
        return $this->artifact->connectionKey === $connectionKey->value
            && hash_equals($this->artifact->id, $artifactId->value)
                ? $this->artifact
                : null;
    }

    public function findByOperation(ConnectionKey $connectionKey, OperationId $operationId): ?ArtifactDescriptor
    {
        return $this->artifact->connectionKey === $connectionKey->value
            && hash_equals($this->artifact->operationId, $operationId->value)
                ? $this->artifact
                : null;
    }

    public function findByRevision(
        ConnectionKey $connectionKey,
        InvoiceResourceId $resourceId,
        ArtifactType $type,
        string $revisionKeyHmac,
    ): ?ArtifactDescriptor {
        return $this->artifact->connectionKey === $connectionKey->value
            && hash_equals($this->artifact->resourceId, $resourceId->value)
            && $this->artifact->type === $type
            && hash_equals($this->artifact->revisionKeyHmac, $revisionKeyHmac)
                ? $this->artifact
                : null;
    }
}

final class S69ObjectStore implements ContentAddressedArtifactStore
{
    public int $inspections = 0;

    public function __construct(public ?ArtifactObjectDescriptor $object) {}

    public function put(ArtifactContentStream $content, string $mimeType): ArtifactObjectDescriptor
    {
        throw new LogicException('The event listener must not write artifact objects.');
    }

    public function inspect(ContentAddress $contentAddress): ?ArtifactObjectDescriptor
    {
        $this->inspections++;

        return $this->object?->contentAddress->equals($contentAddress) ? $this->object : null;
    }

    public function open(ContentAddress $contentAddress): ArtifactContentStream
    {
        throw new LogicException('The event listener must not open artifact objects.');
    }
}

final readonly class S69Clock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

/**
 * @return array{OperationTerminalized, AuthoritativeOperationSnapshot, ArtifactDescriptor, ArtifactObjectDescriptor, DateTimeImmutable}
 */
function s69ReadyFixture(): array
{
    $scope = IntegrationScope::of('fakturownia', 'sales');
    $operationId = new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P7');
    $eventId = new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P8');
    $resourceId = new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1Q9P9');
    $revision = hash('sha256', 'invoice-pdf-ready-revision');
    $snapshotHmac = hash('sha256', 'invoice-pdf-ready-snapshot');
    $artifactId = ArtifactId::fromRevisionHmac($revision);
    $object = new ArtifactObjectDescriptor(
        'shared-artifacts',
        ContentAddress::fromSha256(hash('sha256', "%PDF-1.7\nready\n%%EOF\n")),
        'application/pdf',
        22,
    );
    $result = new InvoicePdfReadyResult(
        $artifactId,
        $resourceId,
        $revision,
        $snapshotHmac,
        $object,
    );
    $codec = new InvoicePdfReadyResultCodec;
    $context = IntegrationContext::make('workflow:pdf:ready');
    $snapshot = AuthoritativeOperationSnapshot::fromArray([
        'version' => AuthoritativeOperationSnapshot::Version,
        'operation_id' => $operationId->value,
        'scope' => $scope->toArray(),
        'operation_type' => DownloadInvoicePdfOperationDefinitionProvider::OperationType,
        'status' => 'succeeded',
        'disposition' => 'succeeded',
        'effect_state' => 'applied',
        'terminal_proof_kind' => 'execute',
        'result_availability' => 'available',
        'result' => $codec->encode($result)->toArray(),
        'context' => $context->toArray(),
        'safe_failure' => null,
    ], new IntegrationContextConstraints, static fn (string $resultType, int $schemaVersion): InvoicePdfReadyResultCodec => $codec);
    $occurredAt = new DateTimeImmutable('2026-08-26T12:00:00+00:00');
    $artifact = new ArtifactDescriptor(
        $artifactId->value,
        $scope->connection->value,
        $operationId->value,
        $resourceId->value,
        ArtifactType::InvoicePdf,
        $revision,
        $snapshotHmac,
        null,
        $object,
        ArtifactStatus::Ready,
        $occurredAt,
        $occurredAt,
    );

    return [
        new OperationTerminalized($eventId, $operationId, $scope, OperationStatus::Succeeded),
        $snapshot,
        $artifact,
        $object,
        $occurredAt,
    ];
}

it('dispatches the semantic PDF event only after the descriptor and immutable object are verifiably ready', function (): void {
    [$terminalized, $snapshot, $artifact, $object, $occurredAt] = s69ReadyFixture();
    $objects = new S69ObjectStore($object);
    Event::fake([InvoicePdfReady::class]);
    $listener = new DispatchInvoicePdfReady(
        new S69AuthoritativeQuery($snapshot),
        new S69ArtifactReader($artifact),
        $objects,
        app(Dispatcher::class),
        new S69Clock($occurredAt),
    );

    $listener->handle($terminalized);

    Event::assertDispatched(InvoicePdfReady::class, static fn (InvoicePdfReady $event): bool => $event->eventId->equals($terminalized->eventId)
        && $event->operationId->equals($terminalized->operationId)
        && $event->artifactId->value === $artifact->id
        && $event->resourceId->value === $artifact->resourceId
        && $event->object->contentAddress->equals($object->contentAddress)
        && $event->occurredAt == $occurredAt
    );
    expect($objects->inspections)->toBe(1);
});

it('does not dispatch the semantic PDF event when the projected object is absent', function (): void {
    [$terminalized, $snapshot, $artifact, , $occurredAt] = s69ReadyFixture();
    Event::fake([InvoicePdfReady::class]);
    $listener = new DispatchInvoicePdfReady(
        new S69AuthoritativeQuery($snapshot),
        new S69ArtifactReader($artifact),
        new S69ObjectStore(null),
        app(Dispatcher::class),
        new S69Clock($occurredAt),
    );

    $listener->handle($terminalized);

    Event::assertNotDispatched(InvoicePdfReady::class);
});
