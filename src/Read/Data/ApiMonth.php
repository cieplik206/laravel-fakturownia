<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class ApiMonth implements JsonSerializable
{
    public function __construct(public string $value)
    {
        $month = DateTimeImmutable::createFromFormat('!Y-m', $value);

        if (! $month instanceof DateTimeImmutable || $month->format('Y-m') !== $value) {
            throw new InvalidArgumentException('The API month must use YYYY-MM.');
        }
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
