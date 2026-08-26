<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

enum ConsumptionDisposition: string
{
    case FreshDirectGrant = 'fresh_direct_grant';
    case RecoveredConsumedProof = 'recovered_consumed_proof';
}
