<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ArtifactDescriptor
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $id,
        public string $connectionKey,
        public string $operationId,
        public string $resourceId,
        public ArtifactType $type,
        public string $revisionKeyHmac,
        public string $sourceSnapshotFingerprintHmac,
        public ?string $sourceKsefOperationId,
        public ArtifactObjectDescriptor $object,
        public ArtifactStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $readyAt,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $deletedAt = null,
    ) {
        $this->assertUlid($id, 'artifact');
        $this->assertUlid($operationId, 'operation');
        $this->assertUlid($resourceId, 'resource');

        if ($sourceKsefOperationId !== null) {
            $this->assertUlid($sourceKsefOperationId, 'source KSeF operation');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $connectionKey) !== 1) {
            throw new InvalidArgumentException('The artifact connection key is invalid.');
        }

        $this->assertHmac($revisionKeyHmac, 'revision key');
        $this->assertHmac($sourceSnapshotFingerprintHmac, 'source snapshot fingerprint');
        $this->assertUtc($createdAt, 'created');
        $this->assertUtc($readyAt, 'ready');

        if ($readyAt < $createdAt) {
            throw new InvalidArgumentException('The artifact cannot become ready before it is created.');
        }

        if ($expiresAt !== null) {
            $this->assertUtc($expiresAt, 'expiry');

            if ($expiresAt <= $readyAt) {
                throw new InvalidArgumentException('The artifact expiry must be later than its ready time.');
            }
        }

        if ($deletedAt !== null) {
            $this->assertUtc($deletedAt, 'deletion');

            if ($deletedAt < $readyAt) {
                throw new InvalidArgumentException('The artifact cannot be deleted before it is ready.');
            }
        }

        if (($status === ArtifactStatus::Deleted) !== ($deletedAt !== null)) {
            throw new InvalidArgumentException('The artifact deleted status and deletion time must agree.');
        }
    }

    private function assertUlid(string $value, string $field): void
    {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The {$field} identifier must be a canonical ULID.");
        }
    }

    private function assertHmac(string $value, string $field): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The artifact {$field} HMAC must use lowercase hexadecimal.");
        }
    }

    private function assertUtc(DateTimeImmutable $value, string $field): void
    {
        if ($value->getOffset() !== 0) {
            throw new InvalidArgumentException("The artifact {$field} time must use UTC.");
        }
    }
}
