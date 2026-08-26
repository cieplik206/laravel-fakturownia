<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Testing\Read;

use Cieplik206\Fakturownia\Read\Contracts\ReadSleeper;
use InvalidArgumentException;

final class RecordingReadSleeper implements ReadSleeper
{
    /** @var list<int> */
    private array $delays = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('The recorded read delay must not be negative.');
        }

        $this->delays[] = $milliseconds;
    }

    /** @return list<int> */
    public function delays(): array
    {
        return $this->delays;
    }
}
