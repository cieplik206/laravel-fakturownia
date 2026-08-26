<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

final readonly class IssueCorrectionPayloadMapper
{
    public function map(CorrectionDraft $draft): IssueCorrectionRequestPayload
    {
        return IssueCorrectionRequestPayload::fromDraft($draft);
    }
}
