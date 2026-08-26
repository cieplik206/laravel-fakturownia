<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStatus;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ArtifactMaintenanceRecord
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $id,
        public string $connectionKey,
        public ArtifactStorageNamespace $storageNamespace,
        public ArtifactObjectDescriptor $object,
        public ArtifactStatus $status,
        public DateTimeImmutable $readyAt,
        public ?DateTimeImmutable $expiresAt,
    ) {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) !== 1) {
            throw new InvalidArgumentException('The maintenance artifact identifier must be a canonical ULID.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $connectionKey) !== 1) {
            throw new InvalidArgumentException('The maintenance artifact connection key is invalid.');
        }

        if ($status === ArtifactStatus::Deleted) {
            throw new InvalidArgumentException('Deleted artifact tombstones cannot become maintenance candidates.');
        }

        if (! hash_equals($storageNamespace->disk, $object->disk)) {
            throw new InvalidArgumentException('The maintenance artifact object is outside its persisted storage namespace.');
        }

        if ($readyAt->getOffset() !== 0 || ($expiresAt !== null && $expiresAt->getOffset() !== 0)) {
            throw new InvalidArgumentException('The maintenance artifact expiry must use UTC.');
        }

        if ($expiresAt !== null && $expiresAt <= $readyAt) {
            throw new InvalidArgumentException('The maintenance artifact expiry must be later than its ready time.');
        }
    }

    public function belongsTo(ArtifactMaintenanceScope $scope): bool
    {
        return hash_equals($scope->connectionKey, $this->connectionKey)
            && $scope->storageNamespace->equals($this->storageNamespace);
    }
}
