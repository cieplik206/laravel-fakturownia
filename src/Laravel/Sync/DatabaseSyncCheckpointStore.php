<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Sync;

use Cieplik206\Fakturownia\Stateful\Sync\Contracts\SyncCheckpointStore;
use Cieplik206\Fakturownia\Stateful\Sync\Exceptions\SyncCheckpointLeaseLost;
use Cieplik206\Fakturownia\Stateful\Sync\Exceptions\SyncCheckpointStorageUnavailable;
use Cieplik206\Fakturownia\Stateful\Sync\IncrementalSyncCheckpoint;
use Cieplik206\Fakturownia\Stateful\Sync\RemoteSyncCursor;
use Cieplik206\Fakturownia\Stateful\Sync\SyncCheckpointLease;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SyncIntegrityScope;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LogicException;
use stdClass;

final class DatabaseSyncCheckpointStore implements SyncCheckpointStore
{
    private const int MaximumLeaseSeconds = 3_600;

    private readonly string $expectedDatabase;

    private readonly string $expectedSchema;

    private readonly string $table;

    public function __construct(
        private readonly Connection $connection,
        string $expectedDatabase,
        string $expectedSchema = 'public',
    ) {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new SyncCheckpointStorageUnavailable(
                'The sync checkpoint store requires an authoritative PostgreSQL connection.',
            );
        }

        if ($expectedDatabase === ''
            || strlen($expectedDatabase) > 63
            || preg_match('//u', $expectedDatabase) !== 1
            || str_contains($expectedDatabase, "\0")) {
            throw new InvalidArgumentException('The expected sync checkpoint PostgreSQL database name is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $expectedSchema) !== 1) {
            throw new InvalidArgumentException('The expected sync checkpoint PostgreSQL schema name is invalid.');
        }

        $this->expectedDatabase = $expectedDatabase;
        $this->expectedSchema = $expectedSchema;
        $this->table = $expectedSchema.'.fakturownia_sync_checkpoints';
        $this->assertDatabaseAuthority();
    }

    public function checkpoint(SyncIntegrityScope $scope): IncrementalSyncCheckpoint
    {
        $this->assertDatabaseAuthority();
        $row = $this->checkpointQuery($scope)->useWritePdo()->first();

        return $row instanceof stdClass
            ? $this->checkpointFromRow($scope, $row)
            : new IncrementalSyncCheckpoint($scope, null, 0);
    }

    public function acquire(SyncIntegrityScope $scope, int $leaseSeconds): ?SyncCheckpointLease
    {
        $this->assertLeaseSeconds($leaseSeconds);

        return $this->connection->transaction(function () use ($scope, $leaseSeconds): ?SyncCheckpointLease {
            $this->assertDatabaseAuthority();
            $this->acquireScopeLock($scope);
            $row = $this->checkpointQuery($scope)->useWritePdo()->lockForUpdate()->first();
            $now = $this->databaseNow();

            if ($row instanceof stdClass && $this->rowHasActiveLease($row, $now)) {
                return null;
            }

            $currentGeneration = $row instanceof stdClass ? $this->requiredNonNegativeInt($row, 'lease_generation') : 0;

            if ($currentGeneration === 2_147_483_647) {
                throw new SyncCheckpointStorageUnavailable('The sync checkpoint lease generation is saturated.');
            }

            $lease = SyncCheckpointLease::issue(
                $scope,
                $currentGeneration + 1,
                $now,
                $now->modify("+{$leaseSeconds} seconds"),
            );
            $values = [
                'lease_generation' => $lease->generation,
                'lease_token_sha256' => $lease->tokenSha256(),
                'lease_acquired_at' => $this->timestamp($lease->acquiredAt),
                'lease_expires_at' => $this->timestamp($lease->expiresAt),
                'updated_at' => $this->timestamp($now),
            ];

            if (! $row instanceof stdClass) {
                $inserted = $this->connection->table($this->table)->insert([
                    'connection_key' => $scope->connectionKey,
                    'lane' => $scope->lane,
                    'cursor_updated_at' => null,
                    'cursor_remote_id' => null,
                    ...$values,
                ]);

                if (! $inserted) {
                    throw new SyncCheckpointStorageUnavailable('The initial sync checkpoint lease could not be persisted.');
                }

                return $lease;
            }

            $updated = $this->checkpointQuery($scope)->update($values);

            if ($updated !== 1) {
                throw new SyncCheckpointStorageUnavailable('The sync checkpoint lease could not be acquired atomically.');
            }

            return $lease;
        });
    }

    public function renew(SyncCheckpointLease $lease, int $leaseSeconds): SyncCheckpointLease
    {
        $this->assertLeaseSeconds($leaseSeconds);

        return $this->connection->transaction(function () use ($lease, $leaseSeconds): SyncCheckpointLease {
            $this->assertDatabaseAuthority();
            $row = $this->lockedRequiredRow($lease->scope);
            $now = $this->databaseNow();
            $this->assertLeaseAuthority($lease, $row, $now);

            if ($lease->generation === 2_147_483_647) {
                throw new SyncCheckpointStorageUnavailable('The sync checkpoint lease generation is saturated.');
            }

            $renewed = SyncCheckpointLease::issue(
                $lease->scope,
                $lease->generation + 1,
                $now,
                $now->modify("+{$leaseSeconds} seconds"),
            );
            $updated = $this->checkpointQuery($lease->scope)->update([
                'lease_generation' => $renewed->generation,
                'lease_token_sha256' => $renewed->tokenSha256(),
                'lease_acquired_at' => $this->timestamp($renewed->acquiredAt),
                'lease_expires_at' => $this->timestamp($renewed->expiresAt),
                'updated_at' => $this->timestamp($now),
            ]);

            if ($updated !== 1) {
                throw new SyncCheckpointStorageUnavailable('The sync checkpoint lease could not be renewed atomically.');
            }

            return $renewed;
        });
    }

    public function advance(SyncCheckpointLease $lease, RemoteSyncCursor $cursor): IncrementalSyncCheckpoint
    {
        if ($this->connection->transactionLevel() < 1) {
            throw new LogicException(
                'The sync checkpoint must advance inside the transaction that durably stores the complete work unit.',
            );
        }

        $this->assertDatabaseAuthority();
        $row = $this->lockedRequiredRow($lease->scope);
        $now = $this->databaseNow();
        $this->assertLeaseAuthority($lease, $row, $now);
        $checkpoint = $this->checkpointFromRow($lease->scope, $row);

        if ($checkpoint->cursor !== null && ! $cursor->isAfter($checkpoint->cursor)) {
            return $checkpoint;
        }

        $updated = $this->checkpointQuery($lease->scope)->update([
            'cursor_updated_at' => $cursor->timestamp(),
            'cursor_remote_id' => $cursor->remoteId(),
            'updated_at' => $this->timestamp($now),
        ]);

        if ($updated !== 1) {
            throw new SyncCheckpointStorageUnavailable('The sync checkpoint could not be advanced atomically.');
        }

        return new IncrementalSyncCheckpoint($lease->scope, $cursor, $lease->generation);
    }

    public function release(SyncCheckpointLease $lease): void
    {
        $this->connection->transaction(function () use ($lease): void {
            $this->assertDatabaseAuthority();
            $row = $this->lockedRequiredRow($lease->scope);
            $now = $this->databaseNow();
            $this->assertLeaseAuthority($lease, $row, $now);
            $updated = $this->checkpointQuery($lease->scope)->update([
                'lease_token_sha256' => null,
                'lease_acquired_at' => null,
                'lease_expires_at' => null,
                'updated_at' => $this->timestamp($now),
            ]);

            if ($updated !== 1) {
                throw new SyncCheckpointStorageUnavailable('The sync checkpoint lease could not be released atomically.');
            }
        });
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Sync checkpoint stores cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Sync checkpoint stores cannot be unserialized.');
    }

    private function assertLeaseSeconds(int $leaseSeconds): void
    {
        if ($leaseSeconds < 1 || $leaseSeconds > self::MaximumLeaseSeconds) {
            throw new InvalidArgumentException('A sync checkpoint lease must last between 1 and 3600 seconds.');
        }
    }

    private function acquireScopeLock(SyncIntegrityScope $scope): void
    {
        $identity = hash(
            'sha256',
            "cieplik206.fakturownia.sync-checkpoint.v1\0{$scope->connectionKey}\0{$scope->lane}",
        );
        $this->connection->selectOne(
            'SELECT pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(?, 0)) AS acquired',
            [$identity],
            false,
        );
    }

    private function lockedRequiredRow(SyncIntegrityScope $scope): stdClass
    {
        $row = $this->checkpointQuery($scope)->useWritePdo()->lockForUpdate()->first();

        if (! $row instanceof stdClass) {
            throw new SyncCheckpointLeaseLost('The leased sync checkpoint no longer exists.');
        }

        return $row;
    }

    private function assertLeaseAuthority(
        SyncCheckpointLease $lease,
        stdClass $row,
        DateTimeImmutable $now,
    ): void {
        $tokenSha256 = $row->lease_token_sha256 ?? null;
        $generation = $this->requiredNonNegativeInt($row, 'lease_generation');
        $acquiredAt = $this->nullableUtc($row, 'lease_acquired_at');
        $expiresAt = $this->nullableUtc($row, 'lease_expires_at');

        if (! is_string($tokenSha256)
            || ! $lease->authenticates($tokenSha256)
            || $generation !== $lease->generation
            || $acquiredAt === null
            || $expiresAt === null
            || $acquiredAt != $lease->acquiredAt
            || $expiresAt != $lease->expiresAt
            || $now >= $expiresAt) {
            throw new SyncCheckpointLeaseLost('The sync checkpoint lease is stale, expired, or no longer authoritative.');
        }
    }

    private function rowHasActiveLease(stdClass $row, DateTimeImmutable $now): bool
    {
        $tokenSha256 = $row->lease_token_sha256 ?? null;
        $acquiredAt = $this->nullableUtc($row, 'lease_acquired_at');
        $expiresAt = $this->nullableUtc($row, 'lease_expires_at');

        if ($tokenSha256 === null && $acquiredAt === null && $expiresAt === null) {
            return false;
        }

        if (! is_string($tokenSha256)
            || preg_match('/^[a-f0-9]{64}$/D', $tokenSha256) !== 1
            || $acquiredAt === null
            || $expiresAt === null) {
            throw new SyncCheckpointStorageUnavailable('The stored sync checkpoint lease envelope is invalid.');
        }

        return $expiresAt > $now;
    }

    private function checkpointFromRow(
        SyncIntegrityScope $scope,
        stdClass $row,
    ): IncrementalSyncCheckpoint {
        $cursorUpdatedAt = $this->nullableUtc($row, 'cursor_updated_at');
        $cursorRemoteId = $row->cursor_remote_id ?? null;

        if (($cursorUpdatedAt === null) !== ($cursorRemoteId === null)) {
            throw new SyncCheckpointStorageUnavailable('The stored sync checkpoint cursor is incomplete.');
        }

        if ($cursorRemoteId !== null && ! is_string($cursorRemoteId)) {
            throw new SyncCheckpointStorageUnavailable('The stored sync checkpoint remote ID is invalid.');
        }

        $cursor = $cursorUpdatedAt !== null && is_string($cursorRemoteId)
            ? new RemoteSyncCursor($cursorUpdatedAt, $cursorRemoteId)
            : null;

        return new IncrementalSyncCheckpoint(
            $scope,
            $cursor,
            $this->requiredNonNegativeInt($row, 'lease_generation'),
        );
    }

    private function checkpointQuery(SyncIntegrityScope $scope): Builder
    {
        return $this->connection->table($this->table)
            ->where('connection_key', $scope->connectionKey)
            ->where('lane', $scope->lane);
    }

    private function requiredNonNegativeInt(stdClass $row, string $field): int
    {
        $value = $row->{$field} ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 0) {
            throw new SyncCheckpointStorageUnavailable("The stored sync checkpoint {$field} value is invalid.");
        }

        return $value;
    }

    private function nullableUtc(stdClass $row, string $field): ?DateTimeImmutable
    {
        $value = $row->{$field} ?? null;

        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        if (! is_string($value) || $value === '') {
            throw new SyncCheckpointStorageUnavailable("The stored sync checkpoint {$field} value is invalid.");
        }

        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function databaseNow(): DateTimeImmutable
    {
        $row = $this->connection->selectOne('SELECT pg_catalog.clock_timestamp() AS now_at', [], false);

        if (! $row instanceof stdClass) {
            throw new SyncCheckpointStorageUnavailable('The authoritative PostgreSQL clock is unavailable.');
        }

        $now = $this->nullableUtc($row, 'now_at');

        if ($now === null) {
            throw new SyncCheckpointStorageUnavailable('The authoritative PostgreSQL clock returned no timestamp.');
        }

        return $now;
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        if ($value->getOffset() !== 0) {
            throw new InvalidArgumentException('Sync checkpoint database timestamps must use UTC.');
        }

        return $value->format('Y-m-d H:i:s.uP');
    }

    private function assertDatabaseAuthority(): void
    {
        $authority = $this->connection->selectOne(
            'SELECT current_database() AS database_name, current_schema() AS schema_name',
            [],
            false,
        );

        if (! $authority instanceof stdClass
            || ! is_string($authority->database_name ?? null)
            || ! is_string($authority->schema_name ?? null)
            || ! hash_equals($this->expectedDatabase, $authority->database_name)
            || ! hash_equals($this->expectedSchema, $authority->schema_name)) {
            throw new SyncCheckpointStorageUnavailable(
                'The sync checkpoint connection does not match its pinned PostgreSQL database and schema.',
            );
        }
    }
}
