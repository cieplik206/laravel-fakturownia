<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\Fakturownia\Read\Exceptions\ResourceNotFound;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class AuthoritativeDeleteCostInvoiceReconciliationStrategy implements AuthoritativeReconciliationStrategy
{
    public function __construct(private FakturowniaManager $manager) {}

    public function reconcile(AuthoritativeReconciliationContext $context): AuthoritativeReconciliationOutcome
    {
        $command = (new DeleteCostInvoicePayloadCodec)->decode($context->payload());

        if ($context->scope()->provider->value !== 'fakturownia'
            || $context->operationType()->value !== DeleteCostInvoiceOperationFactory::OperationType
            || ! $command->connectionKey->equals($context->scope()->connection)) {
            return AuthoritativeReconciliationOutcome::inconclusive(
                'fakturownia.cost_invoice.delete.scope_mismatch',
            );
        }

        try {
            $invoice = $this->manager
                ->connection($command->connectionKey)
                ->read()
                ->invoices()
                ->get($command->remoteId);
        } catch (ResourceNotFound) {
            return AuthoritativeReconciliationOutcome::foundExact(
                new DeleteCostInvoiceResult($command->remoteId),
                'fakturownia.cost_invoice.delete.absence_observed',
                new CanonicalObject(['exists' => false]),
            );
        } catch (Throwable) {
            return AuthoritativeReconciliationOutcome::inconclusive(
                'fakturownia.cost_invoice.delete.read_unavailable',
            );
        }

        $observation = new CanonicalObject([
            'exists' => true,
            'income' => $invoice->income,
            'status' => $invoice->status?->raw,
        ]);

        if ($invoice->income !== false) {
            return AuthoritativeReconciliationOutcome::ambiguousMatches(
                $this->manualReviewFailure(),
                'fakturownia.cost_invoice.delete.resource_mismatch',
                $observation,
            );
        }

        return AuthoritativeReconciliationOutcome::absentConclusive(
            new SafeOperationFailure(
                'fakturownia_cost_invoice_delete_not_applied',
                'The cost invoice still exists after the delete attempt.',
            ),
            'fakturownia.cost_invoice.delete.resource_still_present',
            $observation,
        );
    }

    private function manualReviewFailure(): SafeOperationFailure
    {
        return new SafeOperationFailure(
            'fakturownia_cost_invoice_delete_manual_review',
            'The cost invoice delete operation requires an audited operator review.',
        );
    }
}
