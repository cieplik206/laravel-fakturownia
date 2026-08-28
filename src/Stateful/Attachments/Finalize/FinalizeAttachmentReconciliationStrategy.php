<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\IntegrationOperations\Contracts\ReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;

final readonly class FinalizeAttachmentReconciliationStrategy implements ReconciliationStrategy
{
    public function reconcile(ReconciliationContext $context): ReconciliationOutcome
    {
        return ReconciliationOutcome::inconclusive('fakturownia.attachment.finalize.authoritative_context_required');
    }
}
