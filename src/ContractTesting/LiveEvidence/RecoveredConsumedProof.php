<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class RecoveredConsumedProof
{
    public function __construct(#[SensitiveParameter] public ConsumptionReceipt $receipt)
    {
        if ($receipt->envelope->disposition !== ConsumptionDisposition::RecoveredConsumedProof) {
            throw new InvalidArgumentException('A recovered proof requires the exact recovered-consumed signed disposition.');
        }
    }
}
