<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Identity;

final readonly class OidUniquenessGate
{
    private function __construct() {}

    public static function notPassed(): self
    {
        return new self;
    }

    public function allows(): bool
    {
        return false;
    }

    /** @return array{state: string, scope: string, evidence: string} */
    public function __debugInfo(): array
    {
        return [
            'state' => 'not_passed',
            'scope' => '[REDACTED]',
            'evidence' => '[REDACTED]',
        ];
    }
}
