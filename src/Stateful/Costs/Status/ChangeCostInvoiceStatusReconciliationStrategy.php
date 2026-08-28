<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status;

use Cieplik206\IntegrationOperations\Contracts\ReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;

final readonly class ChangeCostInvoiceStatusReconciliationStrategy implements ReconciliationStrategy
{
    public function reconcile(ReconciliationContext $context): ReconciliationOutcome
    {
        return ReconciliationOutcome::inconclusive(
            'fakturownia.cost_invoice.status.authoritative_context_required',
        );
    }
}
