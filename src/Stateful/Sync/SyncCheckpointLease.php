<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Sync;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SyncIntegrityScope;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final readonly class SyncCheckpointLease
{
    use RejectsNativeSerialization;

    private const string Protocol = 'cieplik206.fakturownia.sync-checkpoint-lease.v1';

    public function __construct(
        public SyncIntegrityScope $scope,
        public int $generation,
        public DateTimeImmutable $acquiredAt,
        public DateTimeImmutable $expiresAt,
        #[SensitiveParameter] private string $token,
    ) {
        if ($generation < 1 || $generation > 2_147_483_647) {
            throw new InvalidArgumentException('The sync checkpoint lease generation is invalid.');
        }

        if ($acquiredAt->getOffset() !== 0 || $expiresAt->getOffset() !== 0 || $expiresAt <= $acquiredAt) {
            throw new InvalidArgumentException('The sync checkpoint lease window is invalid.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('The sync checkpoint lease token is invalid.');
        }
    }

    public static function issue(
        SyncIntegrityScope $scope,
        int $generation,
        DateTimeImmutable $acquiredAt,
        DateTimeImmutable $expiresAt,
    ): self {
        return new self($scope, $generation, $acquiredAt, $expiresAt, bin2hex(random_bytes(32)));
    }

    public function authenticates(#[SensitiveParameter] string $tokenSha256): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $tokenSha256) === 1
            && hash_equals($tokenSha256, $this->tokenSha256());
    }

    public function isExpiredAt(DateTimeImmutable $instant): bool
    {
        if ($instant->getOffset() !== 0) {
            throw new InvalidArgumentException('The sync checkpoint lease comparison instant must use UTC.');
        }

        return $instant >= $this->expiresAt;
    }

    public function tokenSha256(): string
    {
        return hash('sha256', self::Protocol."\0".$this->token);
    }

    /** @return array{sync_checkpoint_lease: string} */
    public function __debugInfo(): array
    {
        return ['sync_checkpoint_lease' => '[REDACTED]'];
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Sync checkpoint leases cannot be cloned.');
    }
}
