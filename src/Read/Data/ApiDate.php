<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class ApiDate implements JsonSerializable
{
    public function __construct(public string $value)
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('The API date must use YYYY-MM-DD.');
        }
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
