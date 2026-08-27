<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Proformas\Reconciliation;

use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\Contracts\InvoiceReconciliationConfiguration;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationEngine;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\RejectsNativeReconciliationObjectTransfer;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;

final readonly class AuthoritativeIssueProformaReconciliationStrategy implements AuthoritativeReconciliationStrategy
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

    public function reconcile(
        AuthoritativeReconciliationContext $context,
    ): AuthoritativeReconciliationOutcome {
        return $this->engine->reconcileAuthoritative($context);
    }
}
