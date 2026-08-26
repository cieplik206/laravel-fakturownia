<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Identity;

use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;

final readonly class RemoteIdentityScope
{
    public function __construct(
        public ConnectionKey $connection,
        public string $documentKind,
        public string $departmentId,
    ) {
        if (trim($documentKind) === '') {
            throw new InvalidArgumentException('Remote identity document kind must not be empty.');
        }

        if (preg_match('/\A[1-9][0-9]*\z/D', $departmentId) !== 1) {
            throw new InvalidArgumentException('Remote identity department must be a positive identifier.');
        }
    }

}
