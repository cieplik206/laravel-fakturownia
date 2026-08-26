<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation;

use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\Contracts\InvoiceReconciliationConfiguration;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;

final readonly class IssueInvoiceReconciliationStrategy implements ReconciliationStrategy
{
    use RejectsNativeReconciliationObjectTransfer;

    private InvoiceReconciliationEngine $engine;

    public function __construct(
        FakturowniaManager $manager,
        HmacSha256 $hmac,
        InvoiceReconciliationConfiguration $configuration,
    ) {
        $this->engine = new InvoiceReconciliationEngine($manager, $hmac, $configuration);
    }

    public function reconcile(ReconciliationContext $context): ReconciliationOutcome
    {
        return $this->engine->reconcile($context);
    }
}
