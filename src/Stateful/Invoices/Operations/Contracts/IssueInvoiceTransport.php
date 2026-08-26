<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations\Contracts;

use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceRequestPayload;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

interface IssueInvoiceTransport
{
    /**
     * The implementation must open the kernel boundary immediately before its
     * single remote POST and must never retry that POST internally.
     */
    public function issue(
        ConnectionKey $connection,
        IssueInvoiceRequestPayload $payload,
        EffectBoundary $boundary,
    ): IssuedInvoiceResult;
}
