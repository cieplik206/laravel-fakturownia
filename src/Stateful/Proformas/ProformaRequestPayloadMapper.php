<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Proformas;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;

final readonly class ProformaRequestPayloadMapper
{
    public function map(
        ProformaDraft $draft,
        ?RemoteInvoiceIdentity $identity = null,
    ): ProformaRequestPayload {
        return ProformaRequestPayload::fromDraft($draft, $identity);
    }
}
