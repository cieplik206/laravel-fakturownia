<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\IntegrationOperations\Contracts\ReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;

final readonly class IssueCorrectionReconciliationStrategy implements ReconciliationStrategy
{
    public function reconcile(ReconciliationContext $context): ReconciliationOutcome
    {
        return ReconciliationOutcome::inconclusive('fakturownia.correction.authoritative_context_required');
    }
}
