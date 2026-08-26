<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Resources;

use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceProjectionStore;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceReader;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceSnapshotProtector;
use Cieplik206\Fakturownia\Stateful\Resources\Exceptions\InvoiceResourceProjectionConflict;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResource;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Resources\ProtectedInvoiceResourceSnapshot;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use RuntimeException;
use stdClass;

final readonly class DatabaseInvoiceResourceStore implements InvoiceResourceProjectionStore, InvoiceResourceReader
{
    public function __construct(
        private KernelDatabase $database,
        private InvoiceResourceSnapshotProtector $protector,
    ) {}

    public function apply(InvoiceResourceProjectionPlan $plan): InvoiceResource
    {
        $connection = $this->authoritativeConnection();

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Invoice resources must be projected inside the kernel terminal transaction.');
        }

        $existing = $this->lockedProjectionCandidate($connection, $plan);

        if ($existing instanceof InvoiceResource) {
            $plan->assertIdempotentWith($existing);

            return $existing;
        }

        $protected = $this->protector->protect($plan);
        $now = $this->databaseNow($connection);
        $inserted = $connection->table('fakturownia_resources')->insertOrIgnore([
            'id' => $plan->resourceId->value,
            'connection_key' => $plan->connectionKey->value,
            'resource_type' => InvoiceResource::ResourceType,
            'local_type' => $plan->localReferenceType,
            'local_hmac_key_version' => $plan->localReferenceHmac->keyVersion,
            'local_reference_hmac' => $plan->localReferenceHmac->hex,
            'remote_id' => $plan->snapshot->remoteId,
            'remote_number' => $plan->snapshot->number,
            'created_by_operation_id' => $plan->operationId->value,
            'last_operation_id' => $plan->operationId->value,
            'snapshot_schema_version' => $protected->snapshotSchemaVersion,
            'snapshot_key_version' => $protected->encryptionKeyVersion,
            'snapshot_cipher' => $protected->cipher,
            'snapshot_nonce' => $protected->nonceBase64,
            'snapshot_ciphertext' => $protected->ciphertextBase64,
            'snapshot_ciphertext_sha256' => $protected->ciphertextSha256,
            'hmac_key_version' => $protected->fingerprint->keyVersion,
            'snapshot_fingerprint_hmac' => $protected->fingerprint->hex,
            'row_version' => 1,
            'created_at' => $this->timestamp($now),
            'remote_updated_at' => null,
            'last_seen_at' => $this->timestamp($now),
            'synced_at' => $this->timestamp($now),
            'deleted_remotely_at' => null,
        ]);

        if ($inserted === 1) {
            $lookupInserted = $connection->table('fakturownia_resource_local_lookups')->insertOrIgnore([
                'resource_id' => $plan->resourceId->value,
                'connection_key' => $plan->connectionKey->value,
                'resource_type' => InvoiceResource::ResourceType,
                'local_type' => $plan->localReferenceType,
                'hmac_key_version' => $plan->localReferenceHmac->keyVersion,
                'local_reference_hmac' => $plan->localReferenceHmac->hex,
                'created_at' => $this->timestamp($now),
            ]);

            if ($lookupInserted !== 1) {
                throw new InvoiceResourceProjectionConflict(
                    'Invoice resource local identity conflicts with a durable mapping.',
                );
            }
        }

        $resource = $this->lockedProjectionCandidate($connection, $plan);

        if (! $resource instanceof InvoiceResource) {
            throw new InvoiceResourceProjectionConflict('Invoice resource projection was not persisted atomically.');
        }

        $plan->assertIdempotentWith($resource);

        return $resource;
    }

    public function findById(ConnectionKey $connectionKey, InvoiceResourceId $resourceId): ?InvoiceResource
    {
        $row = $this->resourceQuery($this->authoritativeConnection(), $connectionKey)
            ->where('id', $resourceId->value)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function findByRemoteId(ConnectionKey $connectionKey, string $remoteId): ?InvoiceResource
    {
        $row = $this->resourceQuery($this->authoritativeConnection(), $connectionKey)
            ->where('remote_id', $remoteId)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function findByLocalReferenceDigests(
        ConnectionKey $connectionKey,
        string $localReferenceType,
        array $localReferenceDigests,
    ): ?InvoiceResource {
        if ($localReferenceDigests === []) {
            throw new LogicException('Invoice resource lookup requires at least one HMAC digest.');
        }

        $connection = $this->authoritativeConnection();
        $resourceIds = $connection->table('fakturownia_resource_local_lookups')
            ->where('connection_key', $connectionKey->value)
            ->where('resource_type', InvoiceResource::ResourceType)
            ->where('local_type', $localReferenceType)
            ->where(function (Builder $query) use ($localReferenceDigests): void {
                foreach ($localReferenceDigests as $digest) {
                    $query->orWhere(function (Builder $candidate) use ($digest): void {
                        $candidate->where('hmac_key_version', $digest->keyVersion)
                            ->where('local_reference_hmac', $digest->hex);
                    });
                }
            })
            ->limit(2)
            ->pluck('resource_id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->unique()
            ->values();

        if ($resourceIds->count() > 1) {
            throw new RuntimeException('Invoice resource lookup aliases resolve to multiple resources.');
        }

        $resourceId = $resourceIds->first();

        if (! is_string($resourceId)) {
            return null;
        }

        $row = $this->resourceQuery($connection, $connectionKey)
            ->where('id', $resourceId)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function lockedProjectionCandidate(
        Connection $connection,
        InvoiceResourceProjectionPlan $plan,
    ): ?InvoiceResource {
        $rows = $this->resourceQuery($connection, $plan->connectionKey)
            ->where(function (Builder $query) use ($plan): void {
                $query->where('id', $plan->resourceId->value)
                    ->orWhere('remote_id', $plan->snapshot->remoteId)
                    ->orWhere(function (Builder $local) use ($plan): void {
                        $local->where('local_type', $plan->localReferenceType)
                            ->where('local_hmac_key_version', $plan->localReferenceHmac->keyVersion)
                            ->where('local_reference_hmac', $plan->localReferenceHmac->hex);
                    });
            })
            ->lockForUpdate()
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $resourceIds = $rows->pluck('id')->filter(static fn (mixed $id): bool => is_string($id))->unique();

        if ($resourceIds->count() !== 1 || ! hash_equals($plan->resourceId->value, (string) $resourceIds->first())) {
            throw new InvoiceResourceProjectionConflict('Invoice resource identity conflicts with a durable mapping.');
        }

        $row = $rows->first();

        return $this->hydrate($row);
    }

    private function hydrate(stdClass $row): InvoiceResource
    {
        $id = new InvoiceResourceId($this->string($row, 'id'));
        $connectionKey = new ConnectionKey($this->string($row, 'connection_key'));
        $operationId = new OperationId($this->string($row, 'last_operation_id'));
        $fingerprint = new VersionedHmacDigest(
            $this->integer($row, 'hmac_key_version'),
            LookupHmacDomain::Payload,
            $this->string($row, 'snapshot_fingerprint_hmac'),
        );
        $protected = new ProtectedInvoiceResourceSnapshot(
            $this->integer($row, 'snapshot_schema_version'),
            $this->integer($row, 'snapshot_key_version'),
            $this->string($row, 'snapshot_cipher'),
            $this->string($row, 'snapshot_nonce'),
            $this->string($row, 'snapshot_ciphertext'),
            $this->string($row, 'snapshot_ciphertext_sha256'),
            $fingerprint,
        );

        return new InvoiceResource(
            id: $id,
            connectionKey: $connectionKey,
            localReferenceType: $this->string($row, 'local_type'),
            localReferenceHmac: new VersionedHmacDigest(
                $this->integer($row, 'local_hmac_key_version'),
                LookupHmacDomain::Intent,
                $this->string($row, 'local_reference_hmac'),
            ),
            remoteId: $this->string($row, 'remote_id'),
            remoteNumber: $this->string($row, 'remote_number'),
            createdByOperationId: new OperationId($this->string($row, 'created_by_operation_id')),
            lastOperationId: $operationId,
            snapshot: $this->protector->recover($id, $connectionKey, $operationId, $protected),
            snapshotFingerprint: $fingerprint,
            rowVersion: $this->integer($row, 'row_version'),
            createdAt: $this->utc($row, 'created_at'),
            lastSeenAt: $this->utc($row, 'last_seen_at'),
            syncedAt: $this->utc($row, 'synced_at'),
            remoteUpdatedAt: $this->nullableUtc($row, 'remote_updated_at'),
            deletedRemotelyAt: $this->nullableUtc($row, 'deleted_remotely_at'),
        );
    }

    private function authoritativeConnection(): Connection
    {
        $connection = $this->database->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Invoice resource persistence requires PostgreSQL.');
        }

        return $connection;
    }

    private function resourceQuery(Connection $connection, ConnectionKey $connectionKey): Builder
    {
        return $connection->table('fakturownia_resources')
            ->where('connection_key', $connectionKey->value)
            ->where('resource_type', InvoiceResource::ResourceType);
    }

    private function databaseNow(Connection $connection): DateTimeImmutable
    {
        $row = $connection->selectOne('SELECT CURRENT_TIMESTAMP(6) AS observed_at');

        if (! $row instanceof stdClass) {
            throw new RuntimeException('The invoice resource database clock is unavailable.');
        }

        return $this->utc($row, 'observed_at');
    }

    private function string(stdClass $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("Stored invoice resource {$field} is invalid.");
        }

        return $value;
    }

    private function integer(stdClass $row, string $field): int
    {
        $value = $row->{$field} ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            throw new RuntimeException("Stored invoice resource {$field} is invalid.");
        }

        return $value;
    }

    private function utc(stdClass $row, string $field): DateTimeImmutable
    {
        $value = $row->{$field} ?? null;

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        if (! is_string($value)) {
            throw new RuntimeException("Stored invoice resource {$field} timestamp is invalid.");
        }

        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function nullableUtc(stdClass $row, string $field): ?DateTimeImmutable
    {
        return ($row->{$field} ?? null) === null ? null : $this->utc($row, $field);
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }
}
