<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use SensitiveParameter;

/**
 * Offline protocol seam for authority response verification tests. Implementing
 * this interface never grants a PHP process permission to perform a live effect.
 */
interface ConsumptionAuthority
{
    /** @param list<array<string, mixed>> $signedAuthorizations */
    public function claim(
        #[SensitiveParameter] array $signedAuthorizations,
        #[SensitiveParameter] ConsumptionClaimRequest $request,
    ): FreshClaimGrant|RecoveredConsumedProof;
}
