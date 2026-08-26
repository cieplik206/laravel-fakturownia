<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Retry;

use Cieplik206\Fakturownia\Read\Contracts\ReadJitter;
use Cieplik206\Fakturownia\Read\Support\RetryAfter;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;
use InvalidArgumentException;
use LogicException;

final readonly class ReadRetryPolicy
{
    /** @var list<int> */
    private array $retryableStatuses;

    /** @param list<int> $retryableStatuses */
    public function __construct(
        public int $maximumAttempts = 4,
        public int $baseDelayMilliseconds = 250,
        public int $maximumDelayMilliseconds = 8_000,
        public int $maximumTotalDelayMilliseconds = 30_000,
        array $retryableStatuses = [408, 429, 500, 502, 503, 504],
    ) {
        if ($maximumAttempts < 1 || $maximumAttempts > 10) {
            throw new InvalidArgumentException('The read retry attempt limit must be between one and ten.');
        }

        if ($baseDelayMilliseconds < 1
            || $maximumDelayMilliseconds < $baseDelayMilliseconds
            || $maximumDelayMilliseconds > 120_000) {
            throw new InvalidArgumentException('The read retry delay range is invalid.');
        }

        if ($maximumTotalDelayMilliseconds < 0 || $maximumTotalDelayMilliseconds > 600_000) {
            throw new InvalidArgumentException('The total read retry delay limit is invalid.');
        }

        $statuses = array_values(array_unique($retryableStatuses));

        foreach ($statuses as $status) {
            if ($status < 400 || $status > 599) {
                throw new InvalidArgumentException('A read retry status is invalid.');
            }
        }

        sort($statuses, SORT_NUMERIC);
        $this->retryableStatuses = $statuses;
    }

    public function shouldRetryStatus(int $statusCode): bool
    {
        return in_array($statusCode, $this->retryableStatuses, true);
    }

    public function delayMilliseconds(
        int $completedAttempts,
        ReadHeaders $headers,
        int $now,
        ReadJitter $jitter,
    ): int {
        $exponentialMaximum = min(
            $this->maximumDelayMilliseconds,
            $this->baseDelayMilliseconds * (2 ** max(0, $completedAttempts - 1)),
        );
        $jitterDelay = $jitter->milliseconds($exponentialMaximum);

        if ($jitterDelay < 0 || $jitterDelay > $exponentialMaximum) {
            throw new LogicException('The read jitter source returned a value outside its requested bound.');
        }

        return max($jitterDelay, RetryAfter::milliseconds($headers, $now) ?? 0);
    }
}
