<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;

final readonly class IssueCorrectionPayloadMapper
{
    public function map(
        CorrectionDraft $draft,
        RemoteInvoiceIdentity $identity,
    ): IssueCorrectionRequestPayload {
        return IssueCorrectionRequestPayload::fromDraft($draft, $identity);
    }
}
