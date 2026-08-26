<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Sync;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class RemoteSyncCursor
{
    use RejectsNativeSerialization;

    public function __construct(
        public DateTimeImmutable $updatedAt,
        #[SensitiveParameter] private string $remoteId,
    ) {
        if ($updatedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The remote sync cursor timestamp must use UTC.');
        }

        if ($remoteId === ''
            || strlen($remoteId) > 191
            || preg_match('//u', $remoteId) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $remoteId) === 1) {
            throw new InvalidArgumentException('The remote sync cursor ID is invalid.');
        }
    }

    public static function fromStored(string $updatedAt, #[SensitiveParameter] string $remoteId): self
    {
        $timestamp = new DateTimeImmutable($updatedAt);

        return new self($timestamp->setTimezone(new DateTimeZone('UTC')), $remoteId);
    }

    public function compare(self $other): int
    {
        $instantComparison = $this->updatedAt <=> $other->updatedAt;

        return $instantComparison !== 0 ? $instantComparison : strcmp($this->remoteId, $other->remoteId);
    }

    public function isAfter(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function timestamp(): string
    {
        return $this->updatedAt->format('Y-m-d H:i:s.uP');
    }

    public function remoteId(): string
    {
        return $this->remoteId;
    }

    /** @return array{updated_at: string, remote_id: string} */
    public function __debugInfo(): array
    {
        return [
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s.u\Z'),
            'remote_id' => '[REDACTED]',
        ];
    }
}
