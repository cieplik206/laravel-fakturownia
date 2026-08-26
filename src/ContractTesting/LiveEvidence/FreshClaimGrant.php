<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class FreshClaimGrant
{
    public function __construct(#[SensitiveParameter] public ConsumptionReceipt $receipt)
    {
        if ($receipt->envelope->disposition !== ConsumptionDisposition::FreshDirectGrant) {
            throw new InvalidArgumentException('A fresh claim grant requires the exact fresh-direct signed disposition.');
        }
    }
}
