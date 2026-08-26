<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Testing\Read;

use Cieplik206\Fakturownia\Read\Contracts\ReadJitter;
use InvalidArgumentException;
use LogicException;
use ReflectionReference;

final class LiteralReadJitter implements ReadJitter
{
    /** @var list<int> */
    private array $values;

    /** @param array<array-key, mixed> $values */
    public function __construct(array $values)
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (ReflectionReference::fromArrayElement($values, $key) !== null || ! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('The literal jitter sequence is invalid.');
            }

            $normalized[] = $value;
        }

        $this->values = $normalized;
    }

    public function milliseconds(int $maximumMilliseconds): int
    {
        $value = array_shift($this->values);

        if (! is_int($value)) {
            throw new LogicException('The literal jitter sequence is exhausted.');
        }

        if ($value > $maximumMilliseconds) {
            throw new LogicException('The literal jitter value exceeds the requested bound.');
        }

        return $value;
    }

    public function remainingValues(): int
    {
        return count($this->values);
    }
}
