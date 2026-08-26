<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;

final readonly class IssueInvoicePayloadMapper
{
    public function __construct(private InvoiceDraftValidator $validator = new InvoiceDraftValidator) {}

    public function map(
        InvoiceDraft $draft,
        InvoiceValidationProfile $profile,
        RemoteInvoiceIdentity $identity,
    ): IssueInvoiceRequestPayload {
        return IssueInvoiceRequestPayload::fromDraft(
            $draft,
            $profile,
            $identity,
            $this->validator,
        );
    }
}
