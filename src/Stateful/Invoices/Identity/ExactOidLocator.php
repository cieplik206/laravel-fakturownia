<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Identity;

use InvalidArgumentException;

final readonly class ExactOidLocator
{
    public function __construct(
        public RemoteIdentityScope $scope,
        public string $oid,
    ) {
        if (trim($oid) === '') {
            throw new InvalidArgumentException('Exact OID locator must not be empty.');
        }
    }

    /** @return array{oid: string} */
    public function query(): array
    {
        return ['oid' => $this->oid];
    }

    /** @return array{scope: string, oid: string} */
    public function __debugInfo(): array
    {
        return [
            'scope' => '[REDACTED]',
            'oid' => '[REDACTED]',
        ];
    }
}
