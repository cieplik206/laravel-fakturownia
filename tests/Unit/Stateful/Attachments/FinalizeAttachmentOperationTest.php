<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\AttachmentPresenceObservation;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\AuthoritativeFinalizeAttachmentOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\AuthoritativeFinalizeAttachmentReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\Contracts\AttachmentPresenceReader;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\Contracts\FinalizeAttachmentTransport;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\DisabledFinalizeAttachmentTransport;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentCommand;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentOperationFactory;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentOperationHandler;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentResult;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentResultCodec;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\AttachmentOperationFailure;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\AuthoritativeReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinitionValidator;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionValidator;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use DateTimeImmutable;

final class S89EffectBoundary implements EffectBoundary
{
    public int $calls = 0;

    public function open(): void
    {
        $this->calls++;
    }

    public function wasOpened(): bool
    {
        return $this->calls > 0;
    }
}

final class S89FinalizeTransport implements FinalizeAttachmentTransport
{
    public int $calls = 0;

    public function finalize(
        ConnectionKey $connection,
        string $remoteId,
        string $fileName,
        EffectBoundary $boundary,
    ): void {
        $this->calls++;
        $boundary->open();
    }
}

final readonly class S89Execution implements OperationExecution
{
    public function __construct(private CanonicalObject $payload, private EffectBoundary $boundary) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1R8A3');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'accounting');
    }

    public function operationType(): OperationType
    {
        return new OperationType(FinalizeAttachmentOperationFactory::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('attachment-finalize:1');
    }

    public function payload(): CanonicalObject
    {
        return $this->payload;
    }

    public function effectBoundary(): EffectBoundary
    {
        return $this->boundary;
    }
}

final readonly class S89PresenceReader implements AttachmentPresenceReader
{
    public function __construct(private ?AttachmentPresenceObservation $observation) {}

    public function observe(ConnectionKey $connection, string $remoteId): ?AttachmentPresenceObservation
    {
        return $this->observation;
    }
}

final readonly class S89ReconciliationContext implements AuthoritativeReconciliationContext
{
    public function __construct(private CanonicalObject $payload) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1R8A3');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'accounting');
    }

    public function operationType(): OperationType
    {
        return new OperationType(FinalizeAttachmentOperationFactory::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('attachment-finalize:1');
    }

    public function payload(): CanonicalObject
    {
        return $this->payload;
    }

    public function observationNumber(): int
    {
        return 1;
    }

    public function effectPossiblyStartedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-28T12:00:00+00:00');
    }

    public function observationStartedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-28T12:01:00+00:00');
    }

    public function priorObservations(): array
    {
        return [];
    }

    public function reconciliationTrigger(): ReconciliationTrigger
    {
        return ReconciliationTrigger::LostResponse;
    }
}

function s89Command(): FinalizeAttachmentCommand
{
    $revision = str_repeat('c', 64);

    return new FinalizeAttachmentCommand(
        new ConnectionKey('accounting'),
        '910123',
        new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1R8A0'),
        new OperationId('01K3K8N8G8V3A6R5T4Y2W1R8A1'),
        ArtifactId::fromRevisionHmac($revision),
        'invoice_123.pdf',
        new ArtifactObjectDescriptor(
            'shared-artifacts',
            ContentAddress::fromSha256(str_repeat('d', 64)),
            'application/pdf',
            1024,
        ),
        2,
        $revision,
        str_repeat('e', 64),
    );
}

it('executes one independent finalize effect and round trips its result', function (): void {
    $command = s89Command();
    $payload = (new FinalizeAttachmentPayloadCodec)->encode($command);
    $boundary = new S89EffectBoundary;
    $transport = new S89FinalizeTransport;
    $execution = new S89Execution($payload, $boundary);
    $outcome = (new FinalizeAttachmentOperationHandler($transport))->execute($execution);
    $result = $outcome->result;

    if (! $result instanceof FinalizeAttachmentResult) {
        throw new LogicException('Expected a typed attachment finalize result.');
    }

    expect($result)
        ->and($transport->calls)->toBe(1)
        ->and($boundary->calls)->toBe(1)
        ->and((new FinalizeAttachmentPayloadCodec)->decode($payload))->toEqual($command)
        ->and((new FinalizeAttachmentResultCodec)->decode(
            (new FinalizeAttachmentResultCodec)->encode($result),
        ))->toEqual($result)
        ->and((new FinalizeAttachmentOutcomeProjectionPlanner)->planResult($execution, $result)->mutations)
        ->toHaveCount(2);
});

it('keeps finalize disabled before its effect boundary and freezes both definitions', function (): void {
    $payload = (new FinalizeAttachmentPayloadCodec)->encode(s89Command());
    $boundary = new S89EffectBoundary;
    $regular = iterator_to_array(FinalizeAttachmentOperationDefinitionProvider::definitions(), false)[0];
    $authoritative = iterator_to_array(
        AuthoritativeFinalizeAttachmentOperationDefinitionProvider::definitions(),
        false,
    )[0];

    expect(fn () => (new FinalizeAttachmentOperationHandler(
        new DisabledFinalizeAttachmentTransport,
    ))->execute(new S89Execution($payload, $boundary)))
        ->toThrow(AttachmentOperationFailure::class, 'not enabled by reviewed live evidence')
        ->and($boundary->calls)->toBe(0)
        ->and((new OperationDefinitionValidator)->violations($regular))->toBe([])
        ->and((new AuthoritativeOperationDefinitionValidator)->violations($authoritative))->toBe([]);
});

it('reconciles exact presence, unchanged absence and ambiguous remote changes without another write', function (): void {
    $payload = (new FinalizeAttachmentPayloadCodec)->encode(s89Command());
    $context = new S89ReconciliationContext($payload);
    $found = (new AuthoritativeFinalizeAttachmentReconciliationStrategy(
        new S89PresenceReader(new AttachmentPresenceObservation(3, ['a.pdf', 'b.pdf', 'invoice_123.pdf'])),
    ))->reconcile($context);
    $absent = (new AuthoritativeFinalizeAttachmentReconciliationStrategy(
        new S89PresenceReader(new AttachmentPresenceObservation(2, ['a.pdf', 'b.pdf'])),
    ))->reconcile($context);
    $ambiguous = (new AuthoritativeFinalizeAttachmentReconciliationStrategy(
        new S89PresenceReader(new AttachmentPresenceObservation(3, ['a.pdf', 'b.pdf', 'other.pdf'])),
    ))->reconcile($context);

    expect($found->result)->toBe(AuthoritativeReconciliationResult::FoundExact)
        ->and($absent->result)->toBe(AuthoritativeReconciliationResult::AbsentConclusive)
        ->and($ambiguous->result)->toBe(AuthoritativeReconciliationResult::AmbiguousMatches);
});
