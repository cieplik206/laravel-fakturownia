<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\IntegrationOperations\Contracts\ReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;

final readonly class DownloadInvoicePdfReconciliationStrategy implements ReconciliationStrategy
{
    public function reconcile(ReconciliationContext $context): ReconciliationOutcome
    {
        return ReconciliationOutcome::inconclusive('fakturownia.invoice_pdf.authoritative_runtime_required');
    }
}
