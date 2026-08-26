<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceRequestPayload;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\Contracts\IssueInvoiceTransport;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class DisabledIssueInvoiceTransport implements IssueInvoiceTransport
{
    public function issue(
        ConnectionKey $connection,
        IssueInvoiceRequestPayload $payload,
        EffectBoundary $boundary,
    ): IssuedInvoiceResult {
        throw IssueInvoiceOperationFailure::capabilityUnavailable();
    }
}
