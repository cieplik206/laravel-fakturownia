<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class ApiTimestamp implements JsonSerializable
{
    public function __construct(public string $value)
    {
        $matches = [];

        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|([+-])(\d{2}):(\d{2}))$/',
            $value,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException('The API timestamp must use RFC 3339.');
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        $hour = (int) $matches[4];
        $minute = (int) $matches[5];
        $second = (int) $matches[6];
        $offsetHour = isset($matches[8]) ? (int) $matches[8] : 0;
        $offsetMinute = isset($matches[9]) ? (int) $matches[9] : 0;

        if (! checkdate($month, $day, $year)
            || $hour > 23
            || $minute > 59
            || $second > 59
            || $offsetHour > 14
            || $offsetMinute > 59
            || ($offsetHour === 14 && $offsetMinute !== 0)) {
            throw new InvalidArgumentException('The API timestamp is invalid.');
        }

        try {
            new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new InvalidArgumentException('The API timestamp is invalid.');
        }
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
