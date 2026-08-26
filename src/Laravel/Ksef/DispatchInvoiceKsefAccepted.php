<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Ksef;

use Cieplik206\Fakturownia\Stateful\Events\InvoiceKsefAccepted;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefTerminalOutcome;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefStateReader;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedResult;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Events\OperationTerminalized;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class DispatchInvoiceKsefAccepted
{
    public function __construct(
        private AuthoritativeOperationQuery $operations,
        private KsefStateReader $states,
        private Dispatcher $events,
        private Clock $clock,
    ) {}

    public function handle(OperationTerminalized $event): void
    {
        if ($event->scope->provider->value !== 'fakturownia') {
            return;
        }

        $snapshot = $this->operations->within($event->scope)->find($event->operationId);

        if ($snapshot?->operationType->value !== EnsureAcceptedOperationDefinitionProvider::OperationType
            || ! $snapshot->result instanceof EnsureAcceptedResult
            || $snapshot->result->outcome !== KsefTerminalOutcome::Accepted
            || $snapshot->result->governmentId === null) {
            return;
        }

        $state = $this->states->findByOperation($event->scope->connection, $event->operationId);

        if ($state === null
            || $state->governmentId !== $snapshot->result->governmentId
            || $state->remoteId !== $snapshot->result->remoteId) {
            return;
        }

        $this->events->dispatch(new InvoiceKsefAccepted(
            eventId: $event->eventId,
            operationId: $event->operationId,
            connectionKey: $event->scope->connection,
            resourceId: $state->resourceId,
            remoteId: $state->remoteId,
            governmentId: $state->governmentId,
            context: $snapshot->context,
            occurredAt: $this->clock->now(),
        ));
    }
}
