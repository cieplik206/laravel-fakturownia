<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status;

use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class AuthoritativeChangeCostInvoiceStatusReconciliationStrategy implements AuthoritativeReconciliationStrategy
{
    public function __construct(private FakturowniaManager $manager) {}

    public function reconcile(AuthoritativeReconciliationContext $context): AuthoritativeReconciliationOutcome
    {
        $command = (new ChangeCostInvoiceStatusPayloadCodec)->decode($context->payload());

        if ($context->scope()->provider->value !== 'fakturownia'
            || $context->operationType()->value !== ChangeCostInvoiceStatusOperationFactory::OperationType
            || ! $command->connectionKey->equals($context->scope()->connection)) {
            return AuthoritativeReconciliationOutcome::inconclusive(
                'fakturownia.cost_invoice.status.scope_mismatch',
            );
        }

        try {
            $invoice = $this->manager
                ->connection($command->connectionKey)
                ->read()
                ->invoices()
                ->get($command->remoteId);
        } catch (Throwable) {
            return AuthoritativeReconciliationOutcome::inconclusive(
                'fakturownia.cost_invoice.status.read_unavailable',
            );
        }

        $status = $invoice->status;
        $observation = new CanonicalObject([
            'income' => $invoice->income,
            'status' => $status?->raw,
        ]);

        if ($invoice->income !== false || $status === null) {
            return AuthoritativeReconciliationOutcome::ambiguousMatches(
                $this->manualReviewFailure(),
                'fakturownia.cost_invoice.status.resource_mismatch',
                $observation,
            );
        }

        if (hash_equals($command->targetStatus->raw, $status->raw)) {
            return AuthoritativeReconciliationOutcome::foundExact(
                new ChangeCostInvoiceStatusResult($command->remoteId, $status),
                'fakturownia.cost_invoice.status.target_observed',
                $observation,
            );
        }

        if (hash_equals($command->expectedStatus->raw, $status->raw)) {
            return AuthoritativeReconciliationOutcome::absentConclusive(
                new SafeOperationFailure(
                    'fakturownia_cost_invoice_status_not_applied',
                    'The expected prior cost invoice status remains unchanged.',
                ),
                'fakturownia.cost_invoice.status.prior_state_observed',
                $observation,
            );
        }

        return AuthoritativeReconciliationOutcome::ambiguousMatches(
            $this->manualReviewFailure(),
            'fakturownia.cost_invoice.status.unexpected_state_observed',
            $observation,
        );
    }

    private function manualReviewFailure(): SafeOperationFailure
    {
        return new SafeOperationFailure(
            'fakturownia_cost_invoice_status_manual_review',
            'The cost invoice status requires operator review before another write.',
        );
    }
}
