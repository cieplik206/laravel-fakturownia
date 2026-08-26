<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefStateReader;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use RuntimeException;

final readonly class InvoiceKsefStateQuery
{
    use RejectsNativeSerialization;

    public function __construct(
        private ConnectionKey $connectionKey,
        private KsefStateReader $reader,
    ) {}

    public function find(InvoiceResourceId $resourceId): ?InvoiceKsefState
    {
        return $this->assertScope($this->reader->find($this->connectionKey, $resourceId));
    }

    public function findByOperation(OperationId $operationId): ?InvoiceKsefState
    {
        return $this->assertScope($this->reader->findByOperation($this->connectionKey, $operationId));
    }

    private function assertScope(?InvoiceKsefState $state): ?InvoiceKsefState
    {
        if ($state instanceof InvoiceKsefState && ! $state->connectionKey->equals($this->connectionKey)) {
            throw new RuntimeException('The KSeF state reader returned a cross-connection result.');
        }

        return $state;
    }
}
