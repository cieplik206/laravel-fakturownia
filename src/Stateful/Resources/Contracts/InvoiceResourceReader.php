<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources\Contracts;

use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResource;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;

interface InvoiceResourceReader
{
    public function findById(ConnectionKey $connectionKey, InvoiceResourceId $resourceId): ?InvoiceResource;

    public function findByRemoteId(ConnectionKey $connectionKey, string $remoteId): ?InvoiceResource;

    /** @param list<VersionedHmacDigest> $localReferenceDigests */
    public function findByLocalReferenceDigests(
        ConnectionKey $connectionKey,
        string $localReferenceType,
        array $localReferenceDigests,
    ): ?InvoiceResource;
}
