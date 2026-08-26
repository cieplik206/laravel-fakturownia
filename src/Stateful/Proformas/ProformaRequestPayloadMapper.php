<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Proformas;

final readonly class ProformaRequestPayloadMapper
{
    public function map(ProformaDraft $draft): ProformaRequestPayload
    {
        return ProformaRequestPayload::fromDraft($draft);
    }
}
