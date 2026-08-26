<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources;

use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceReader;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;
use RuntimeException;

final readonly class InvoiceResourceQuery
{
    use RejectsNativeSerialization;

    public function __construct(
        private ConnectionKey $connectionKey,
        private InvoiceResourceReader $reader,
        private HmacSha256 $hmac,
    ) {}

    public function find(InvoiceResourceId $resourceId): ?InvoiceResource
    {
        $resource = $this->reader->findById($this->connectionKey, $resourceId);

        if ($resource === null) {
            return null;
        }

        $this->assertConnectionScope($resource);

        if (! $resource->id->equals($resourceId)) {
            throw new RuntimeException('Invoice resource reader returned a value outside the requested scope.');
        }

        return $resource;
    }

    public function findByRemoteId(string $remoteId): ?InvoiceResource
    {
        $this->assertRemoteId($remoteId);

        $resource = $this->reader->findByRemoteId($this->connectionKey, $remoteId);

        if ($resource === null) {
            return null;
        }

        $this->assertConnectionScope($resource);

        if (! hash_equals($resource->remoteId, $remoteId)) {
            throw new RuntimeException('Invoice resource reader returned a value outside the requested scope.');
        }

        return $resource;
    }

    public function findByTransactionOrder(string $reference): ?InvoiceResource
    {
        $lookup = InvoiceResourceLocalLookup::forTransactionOrder($this->hmac, $reference);

        $resource = $this->reader->findByLocalReferenceDigests(
            $this->connectionKey,
            $lookup->referenceType,
            $lookup->digests,
        );

        if ($resource === null) {
            return null;
        }

        $this->assertConnectionScope($resource);

        if ($resource->localReferenceType !== $lookup->referenceType) {
            throw new RuntimeException('Invoice resource reader returned a value outside the requested scope.');
        }

        foreach ($lookup->digests as $digest) {
            if ($resource->localReferenceHmac->equals($digest)) {
                return $resource;
            }
        }

        throw new RuntimeException('Invoice resource reader returned a value outside the requested scope.');
    }

    private function assertConnectionScope(InvoiceResource $resource): void
    {
        if (! $resource->connectionKey->equals($this->connectionKey)) {
            throw new RuntimeException('Invoice resource reader returned a value outside the requested scope.');
        }
    }

    private function assertRemoteId(string $remoteId): void
    {
        if ($remoteId === ''
            || $remoteId !== trim($remoteId)
            || strlen($remoteId) > 191
            || preg_match('//u', $remoteId) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $remoteId) === 1) {
            throw new InvalidArgumentException('Invoice resource remote ID is invalid.');
        }
    }
}
