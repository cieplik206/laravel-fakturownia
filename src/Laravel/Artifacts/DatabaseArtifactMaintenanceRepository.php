<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStatus;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceRecord;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceRecordPage;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceScope;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceRepository;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class DatabaseArtifactMaintenanceRepository implements ArtifactMaintenanceRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'fakturownia_artifacts',
    ) {
        if (preg_match('/^(?:[a-z][a-z0-9_]{0,62}\.)?[a-z][a-z0-9_]{0,62}$/D', $table) !== 1) {
            throw new InvalidArgumentException('The artifact descriptor table name is invalid.');
        }
    }

    public function auditPage(
        ArtifactMaintenanceScope $scope,
        ?string $afterArtifactId,
        int $limit,
    ): ArtifactMaintenanceRecordPage {
        return $this->page(
            $this->activeQuery($scope),
            $afterArtifactId,
            $limit,
        );
    }

    public function expiredPage(
        ArtifactMaintenanceScope $scope,
        DateTimeImmutable $now,
        ?string $afterArtifactId,
        int $limit,
    ): ArtifactMaintenanceRecordPage {
        $this->assertUtc($now);

        return $this->page(
            $this->activeQuery($scope)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $this->utcTimestamp($now)),
            $afterArtifactId,
            $limit,
        );
    }

    public function hasAnyActiveReference(
        ArtifactMaintenanceScope $scope,
        ContentAddress $contentAddress,
    ): bool {
        return $this->connection->table($this->table)
            ->where('disk', $scope->storageNamespace->disk)
            ->where('storage_prefix', $scope->storageNamespace->prefix)
            ->where('content_address', (string) $contentAddress)
            ->where('status', '!=', ArtifactStatus::Deleted->value)
            ->useWritePdo()
            ->exists();
    }

    public function hasOtherActiveReference(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
    ): bool {
        $this->assertRecordScope($scope, $record);

        return $this->connection->table($this->table)
            ->where('disk', $scope->storageNamespace->disk)
            ->where('storage_prefix', $scope->storageNamespace->prefix)
            ->where('content_address', (string) $record->object->contentAddress)
            ->where('id', '!=', $record->id)
            ->where('status', '!=', ArtifactStatus::Deleted->value)
            ->useWritePdo()
            ->exists();
    }

    public function quarantine(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
    ): bool {
        $this->assertRecordScope($scope, $record);

        if ($record->status === ArtifactStatus::Quarantined) {
            return $this->recordQuery($scope, $record)
                ->where('status', ArtifactStatus::Quarantined->value)
                ->exists();
        }

        return $this->recordQuery($scope, $record)
            ->where('status', ArtifactStatus::Ready->value)
            ->update(['status' => ArtifactStatus::Quarantined->value]) === 1;
    }

    public function tombstone(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
        DateTimeImmutable $deletedAt,
    ): bool {
        $this->assertRecordScope($scope, $record);
        $this->assertUtc($deletedAt);

        if ($record->status !== ArtifactStatus::Quarantined) {
            return false;
        }

        return $this->recordQuery($scope, $record)
            ->where('status', ArtifactStatus::Quarantined->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $this->utcTimestamp($deletedAt))
            ->update([
                'status' => ArtifactStatus::Deleted->value,
                'deleted_at' => $this->utcTimestamp($deletedAt),
            ]) === 1;
    }

    private function activeQuery(ArtifactMaintenanceScope $scope): Builder
    {
        return $this->connection->table($this->table)
            ->select([
                'id',
                'connection_key',
                'disk',
                'storage_prefix',
                'content_address',
                'mime_type',
                'size_bytes',
                'status',
                'ready_at',
                'expires_at',
            ])
            ->where('connection_key', $scope->connectionKey)
            ->where('disk', $scope->storageNamespace->disk)
            ->where('storage_prefix', $scope->storageNamespace->prefix)
            ->whereIn('status', [ArtifactStatus::Ready->value, ArtifactStatus::Quarantined->value])
            ->useWritePdo();
    }

    private function recordQuery(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
    ): Builder {
        return $this->connection->table($this->table)
            ->where('id', $record->id)
            ->where('connection_key', $scope->connectionKey)
            ->where('disk', $scope->storageNamespace->disk)
            ->where('storage_prefix', $scope->storageNamespace->prefix)
            ->where('content_address', (string) $record->object->contentAddress)
            ->useWritePdo();
    }

    private function page(Builder $query, ?string $afterArtifactId, int $limit): ArtifactMaintenanceRecordPage
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('The artifact maintenance page limit must be between 1 and 1000.');
        }

        if ($afterArtifactId !== null && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $afterArtifactId) !== 1) {
            throw new InvalidArgumentException('The artifact maintenance cursor must be a canonical ULID.');
        }

        if ($afterArtifactId !== null) {
            $query->where('id', '>', $afterArtifactId);
        }

        $rows = $query->orderBy('id')->limit($limit + 1)->get()->all();
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $records = [];

        foreach ($rows as $row) {
            $records[] = $this->hydrate($row);
        }

        $last = $records === [] ? null : $records[array_key_last($records)];

        return new ArtifactMaintenanceRecordPage(
            $records,
            $hasMore && $last instanceof ArtifactMaintenanceRecord ? $last->id : null,
        );
    }

    private function hydrate(mixed $row): ArtifactMaintenanceRecord
    {
        if (! $row instanceof stdClass) {
            throw new RuntimeException('The artifact maintenance query returned an invalid row.');
        }

        $id = $this->requiredString($row, 'id');
        $connectionKey = $this->requiredString($row, 'connection_key');
        $disk = $this->requiredString($row, 'disk');
        $storagePrefix = $this->requiredString($row, 'storage_prefix');
        $contentAddress = ContentAddress::parse($this->requiredString($row, 'content_address'));
        $mimeType = $this->requiredString($row, 'mime_type');
        $sizeBytes = $this->requiredPositiveInt($row, 'size_bytes');
        $status = ArtifactStatus::tryFrom($this->requiredString($row, 'status'));

        if ($status === null) {
            throw new RuntimeException('The artifact maintenance row has an unsupported status.');
        }

        return new ArtifactMaintenanceRecord(
            $id,
            $connectionKey,
            new ArtifactStorageNamespace($disk, $storagePrefix),
            new ArtifactObjectDescriptor($disk, $contentAddress, $mimeType, $sizeBytes),
            $status,
            $this->requiredUtc($row->ready_at ?? null, 'ready_at'),
            $this->nullableUtc($row->expires_at ?? null),
        );
    }

    private function requiredString(stdClass $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The artifact maintenance row has an invalid {$field} value.");
        }

        return $value;
    }

    private function requiredPositiveInt(stdClass $row, string $field): int
    {
        $value = $row->{$field} ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException("The artifact maintenance row has an invalid {$field} value.");
        }

        return $value;
    }

    private function nullableUtc(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('The artifact maintenance row has an invalid expiry value.');
        }

        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function requiredUtc(mixed $value, string $field): DateTimeImmutable
    {
        $time = $this->nullableUtc($value);

        if ($time === null) {
            throw new RuntimeException("The artifact maintenance row has an invalid {$field} value.");
        }

        return $time;
    }

    private function assertRecordScope(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
    ): void {
        if (! $record->belongsTo($scope)) {
            throw new InvalidArgumentException('The artifact maintenance record is outside the requested scope.');
        }
    }

    private function assertUtc(DateTimeImmutable $time): void
    {
        if ($time->getOffset() !== 0) {
            throw new InvalidArgumentException('Artifact maintenance time must use UTC.');
        }
    }

    private function utcTimestamp(DateTimeImmutable $time): string
    {
        $this->assertUtc($time);

        return $time->format('Y-m-d H:i:s.uP');
    }
}
