<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Proformas\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationFailure;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\Contracts\IssueProformaTransport;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaRequestPayload;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class DisabledIssueProformaTransport implements IssueProformaTransport
{
    public function issue(
        ConnectionKey $connection,
        ProformaRequestPayload $payload,
        EffectBoundary $boundary,
    ): IssuedInvoiceResult {
        throw IssueInvoiceOperationFailure::capabilityUnavailable();
    }
}
