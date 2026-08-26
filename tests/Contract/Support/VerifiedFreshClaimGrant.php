<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Contract\Support;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\FreshClaimGrant;

/**
 * Internal probe token. Every mutating boundary must still call the common
 * cryptographic revalidator; the type alone never authorizes a write.
 */
final readonly class VerifiedFreshClaimGrant
{
    private function __construct(public FreshClaimGrant $grant) {}

    /** @return array{envelope: array<string, mixed>, signature: string} */
    public function toArray(): array
    {
        return $this->grant->receipt->toArray();
    }
}
