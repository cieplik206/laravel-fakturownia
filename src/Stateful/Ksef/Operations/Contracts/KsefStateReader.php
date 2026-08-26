<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts;

use Cieplik206\Fakturownia\Stateful\Ksef\InvoiceKsefState;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

interface KsefStateReader
{
    public function find(ConnectionKey $connectionKey, InvoiceResourceId $resourceId): ?InvoiceKsefState;

    public function findByOperation(ConnectionKey $connectionKey, OperationId $operationId): ?InvoiceKsefState;
}
