<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStatus;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageKey;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactType;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactDescriptorReader;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactMetadataProtector;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactProjectionStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Exceptions\ArtifactProjectionConflict;
use Cieplik206\Fakturownia\Stateful\Artifacts\ProtectedArtifactMetadata;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use RuntimeException;
use stdClass;

final readonly class DatabaseArtifactStore implements ArtifactDescriptorReader, ArtifactProjectionStore
{
    private const string StorageKeyPurpose = 'storage_key';

    private const string SourceGovernmentIdPurpose = 'source_gov_id';

    public function __construct(
        private KernelDatabase $database,
        private Repository $configuration,
        private ArtifactMetadataProtector $protector,
        private ContentAddressedArtifactStore $objects,
    ) {}

    public function apply(ArtifactProjectionPlan $plan): ArtifactDescriptor
    {
        $connection = $this->authoritativeConnection();

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Artifacts must be projected inside the kernel terminal transaction.');
        }

        $namespace = $this->storageNamespace();
        $this->assertObjectReady($namespace, $plan->object);
        $existing = $this->lockedProjectionCandidate($connection, $plan);

        if ($existing instanceof stdClass) {
            return $this->assertExisting($plan, $namespace, $existing);
        }

        $storageKey = ArtifactStorageKey::for($namespace, $plan->object->contentAddress);
        $protectedStorageKey = $this->protector->protect($plan, self::StorageKeyPurpose, $storageKey);
        $protectedGovernmentId = $plan->sourceGovernmentId === null
            ? null
            : $this->protector->protect($plan, self::SourceGovernmentIdPurpose, $plan->sourceGovernmentId);
        $now = $this->databaseNow($connection);
        $expiresAt = $now->add(new DateInterval('P'.$this->retentionDays().'D'));

        $connection->table('fakturownia_artifacts')->insertOrIgnore([
            'id' => $plan->artifactId->value,
            'connection_key' => $plan->connectionKey->value,
            'operation_id' => $plan->operationId->value,
            'resource_id' => $plan->resourceId->value,
            'artifact_type' => $plan->type->value,
            'revision_key_hmac' => $plan->revisionKeyHmac,
            'source_snapshot_fingerprint_hmac' => $plan->sourceSnapshotFingerprintHmac,
            'source_ksef_operation_id' => $plan->sourceKsefOperationId?->value,
            'source_gov_id_key_version' => $protectedGovernmentId?->keyVersion,
            'source_gov_id_cipher' => $protectedGovernmentId?->cipher,
            'source_gov_id_ciphertext' => $protectedGovernmentId?->ciphertext,
            'source_gov_id_ciphertext_sha256' => $protectedGovernmentId?->ciphertextSha256,
            'disk' => $namespace->disk,
            'storage_prefix' => $namespace->prefix,
            'content_address' => (string) $plan->object->contentAddress,
            'storage_key_version' => $protectedStorageKey->keyVersion,
            'storage_key_cipher' => $protectedStorageKey->cipher,
            'storage_key_ciphertext' => $protectedStorageKey->ciphertext,
            'storage_key_ciphertext_sha256' => $protectedStorageKey->ciphertextSha256,
            'content_sha256' => $plan->object->contentSha256(),
            'mime_type' => $plan->object->mimeType,
            'size_bytes' => $plan->object->sizeBytes,
            'status' => ArtifactStatus::Ready->value,
            'created_at' => $this->timestamp($now),
            'ready_at' => $this->timestamp($now),
            'expires_at' => $this->timestamp($expiresAt),
            'deleted_at' => null,
        ]);

        $persisted = $this->lockedProjectionCandidate($connection, $plan);

        if (! $persisted instanceof stdClass) {
            throw new ArtifactProjectionConflict('The artifact descriptor was not persisted atomically.');
        }

        return $this->assertExisting($plan, $namespace, $persisted);
    }

    public function find(ConnectionKey $connectionKey, ArtifactId $artifactId): ?ArtifactDescriptor
    {
        $row = $this->descriptorQuery($this->authoritativeConnection(), $connectionKey)
            ->where('id', $artifactId->value)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function findByOperation(ConnectionKey $connectionKey, OperationId $operationId): ?ArtifactDescriptor
    {
        $rows = $this->descriptorQuery($this->authoritativeConnection(), $connectionKey)
            ->where('operation_id', $operationId->value)
            ->limit(2)
            ->get();

        if ($rows->count() > 1) {
            throw new RuntimeException('One artifact operation resolves to multiple durable descriptors.');
        }

        $row = $rows->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function findByRevision(
        ConnectionKey $connectionKey,
        InvoiceResourceId $resourceId,
        ArtifactType $type,
        string $revisionKeyHmac,
    ): ?ArtifactDescriptor {
        if (preg_match('/^[a-f0-9]{64}$/D', $revisionKeyHmac) !== 1) {
            throw new LogicException('Artifact revision lookup requires a canonical HMAC.');
        }

        $row = $this->descriptorQuery($this->authoritativeConnection(), $connectionKey)
            ->where('resource_id', $resourceId->value)
            ->where('artifact_type', $type->value)
            ->where('revision_key_hmac', $revisionKeyHmac)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function assertExisting(
        ArtifactProjectionPlan $plan,
        ArtifactStorageNamespace $namespace,
        stdClass $row,
    ): ArtifactDescriptor {
        $artifact = $this->hydrate($row);
        $plan->assertIdempotentWith($artifact);

        if (! hash_equals($this->string($row, 'storage_prefix'), $namespace->prefix)
            || ! hash_equals(
                $this->protector->recover(
                    $plan,
                    self::StorageKeyPurpose,
                    $this->protected($row, 'storage_key'),
                ),
                ArtifactStorageKey::for($namespace, $plan->object->contentAddress),
            )
            || ! $this->governmentIdMatches($plan, $row)) {
            throw new ArtifactProjectionConflict('The encrypted artifact metadata conflicts with the projection.');
        }

        return $artifact;
    }

    private function governmentIdMatches(ArtifactProjectionPlan $plan, stdClass $row): bool
    {
        $metadata = $this->nullableProtected($row, 'source_gov_id');

        if ($plan->sourceGovernmentId === null || ! $metadata instanceof ProtectedArtifactMetadata) {
            return $plan->sourceGovernmentId === null && $metadata === null;
        }

        return hash_equals(
            $this->protector->recover($plan, self::SourceGovernmentIdPurpose, $metadata),
            $plan->sourceGovernmentId,
        );
    }

    private function assertObjectReady(
        ArtifactStorageNamespace $namespace,
        ArtifactObjectDescriptor $expected,
    ): void {
        $actual = $this->objects->inspect($expected->contentAddress);

        if (! $actual instanceof ArtifactObjectDescriptor
            || ! hash_equals($namespace->disk, $expected->disk)
            || ! hash_equals($actual->disk, $expected->disk)
            || ! $actual->contentAddress->equals($expected->contentAddress)
            || ! hash_equals($actual->mimeType, $expected->mimeType)
            || $actual->sizeBytes !== $expected->sizeBytes) {
            throw new ArtifactProjectionConflict('The artifact object is absent or conflicts with its result descriptor.');
        }
    }

    private function lockedProjectionCandidate(Connection $connection, ArtifactProjectionPlan $plan): ?stdClass
    {
        $rows = $this->descriptorQuery($connection, $plan->connectionKey)
            ->where(function (Builder $query) use ($plan): void {
                $query->where('id', $plan->artifactId->value)
                    ->orWhere(function (Builder $revision) use ($plan): void {
                        $revision->where('resource_id', $plan->resourceId->value)
                            ->where('artifact_type', $plan->type->value)
                            ->where('revision_key_hmac', $plan->revisionKeyHmac);
                    });
            })
            ->lockForUpdate()
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        if ($rows->count() !== 1) {
            throw new ArtifactProjectionConflict('The artifact identity resolves to multiple descriptors.');
        }

        return $rows->first();
    }

    private function hydrate(stdClass $row): ArtifactDescriptor
    {
        $type = ArtifactType::tryFrom($this->string($row, 'artifact_type'));
        $status = ArtifactStatus::tryFrom($this->string($row, 'status'));

        if (! $type instanceof ArtifactType || ! $status instanceof ArtifactStatus) {
            throw new RuntimeException('The artifact descriptor contains an unsupported enum value.');
        }

        return new ArtifactDescriptor(
            $this->string($row, 'id'),
            $this->string($row, 'connection_key'),
            $this->string($row, 'operation_id'),
            $this->string($row, 'resource_id'),
            $type,
            $this->string($row, 'revision_key_hmac'),
            $this->string($row, 'source_snapshot_fingerprint_hmac'),
            $this->nullableString($row, 'source_ksef_operation_id'),
            new ArtifactObjectDescriptor(
                $this->string($row, 'disk'),
                ContentAddress::parse($this->string($row, 'content_address')),
                $this->string($row, 'mime_type'),
                $this->integer($row, 'size_bytes'),
            ),
            $status,
            $this->utc($row, 'created_at'),
            $this->utc($row, 'ready_at'),
            $this->nullableUtc($row, 'expires_at'),
            $this->nullableUtc($row, 'deleted_at'),
        );
    }

    private function protected(stdClass $row, string $prefix): ProtectedArtifactMetadata
    {
        return new ProtectedArtifactMetadata(
            $this->integer($row, $prefix.'_version'),
            $this->string($row, $prefix.'_cipher'),
            $this->string($row, $prefix.'_ciphertext'),
            $this->string($row, $prefix.'_ciphertext_sha256'),
        );
    }

    private function nullableProtected(stdClass $row, string $prefix): ?ProtectedArtifactMetadata
    {
        if (($row->{$prefix.'_key_version'} ?? null) === null) {
            foreach (['_cipher', '_ciphertext', '_ciphertext_sha256'] as $suffix) {
                if (($row->{$prefix.$suffix} ?? null) !== null) {
                    throw new RuntimeException('The nullable artifact metadata envelope is incomplete.');
                }
            }

            return null;
        }

        return new ProtectedArtifactMetadata(
            $this->integer($row, $prefix.'_key_version'),
            $this->string($row, $prefix.'_cipher'),
            $this->string($row, $prefix.'_ciphertext'),
            $this->string($row, $prefix.'_ciphertext_sha256'),
        );
    }

    private function storageNamespace(): ArtifactStorageNamespace
    {
        $disk = $this->configuration->get('fakturownia.artifacts.disk');
        $prefix = $this->configuration->get('fakturownia.artifacts.prefix');

        if (! is_string($disk) || ! is_string($prefix)) {
            throw new LogicException('The artifact storage namespace is not configured.');
        }

        return new ArtifactStorageNamespace($disk, $prefix);
    }

    private function retentionDays(): int
    {
        $days = $this->configuration->get('fakturownia.artifacts.retention_days');

        if (! is_int($days) || $days < 1 || $days > 36_500) {
            throw new LogicException('The artifact retention period is invalid.');
        }

        return $days;
    }

    private function authoritativeConnection(): Connection
    {
        $connection = $this->database->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Artifact persistence requires PostgreSQL.');
        }

        return $connection;
    }

    private function descriptorQuery(Connection $connection, ConnectionKey $connectionKey): Builder
    {
        return $connection->table('fakturownia_artifacts')
            ->where('connection_key', $connectionKey->value);
    }

    private function databaseNow(Connection $connection): DateTimeImmutable
    {
        $row = $connection->selectOne('SELECT CURRENT_TIMESTAMP(6) AS observed_at');

        if (! $row instanceof stdClass) {
            throw new RuntimeException('The artifact database clock is unavailable.');
        }

        return $this->utc($row, 'observed_at');
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }

    private function string(stdClass $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The artifact descriptor [{$field}] is invalid.");
        }

        return $value;
    }

    private function nullableString(stdClass $row, string $field): ?string
    {
        $value = $row->{$field} ?? null;

        if ($value !== null && (! is_string($value) || $value === '')) {
            throw new RuntimeException("The artifact descriptor [{$field}] is invalid.");
        }

        return $value;
    }

    private function integer(stdClass $row, string $field): int
    {
        $value = $row->{$field} ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException("The artifact descriptor [{$field}] is invalid.");
        }

        return $value;
    }

    private function utc(stdClass $row, string $field): DateTimeImmutable
    {
        $value = $row->{$field} ?? null;

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The artifact descriptor [{$field}] is invalid.");
        }

        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function nullableUtc(stdClass $row, string $field): ?DateTimeImmutable
    {
        return ($row->{$field} ?? null) === null ? null : $this->utc($row, $field);
    }
}
