<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

enum BrokeredEffectDisposition: string
{
    case Applied = 'applied';
    case PossiblyApplied = 'possibly_applied';
    case Denied = 'denied';
    case AlreadyConsumed = 'already_consumed';
}
