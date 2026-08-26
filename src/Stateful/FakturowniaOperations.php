<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful;

use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshot;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshotBatch;

final readonly class FakturowniaOperations
{
    public function __construct(
        private ConnectionKey $connectionKey,
        private OperationQuery $operations,
    ) {}

    public function find(OperationId $operationId): ?OperationSnapshot
    {
        return $this->operations
            ->within($this->scope())
            ->find($operationId);
    }

    /** @param iterable<OperationId> $operationIds */
    public function findMany(iterable $operationIds): OperationSnapshotBatch
    {
        return $this->operations
            ->within($this->scope())
            ->findMany($operationIds);
    }

    private function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', $this->connectionKey->value);
    }
}
