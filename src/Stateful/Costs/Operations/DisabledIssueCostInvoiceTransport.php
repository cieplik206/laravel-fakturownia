<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Operations;

use Cieplik206\Fakturownia\Stateful\Costs\Operations\Contracts\IssueCostInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceRequestPayload;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationFailure;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class DisabledIssueCostInvoiceTransport implements IssueCostInvoiceTransport
{
    public function issue(
        ConnectionKey $connection,
        IssueInvoiceRequestPayload $payload,
        EffectBoundary $boundary,
    ): IssuedInvoiceResult {
        throw IssueInvoiceOperationFailure::capabilityUnavailable();
    }
}
