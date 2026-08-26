<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Support;

use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;
use DateTimeImmutable;
use DateTimeZone;

/** @internal */
final class RetryAfter
{
    private const HttpDateFormat = 'D, d M Y H:i:s \\G\\M\\T';

    public static function milliseconds(ReadHeaders $headers, int $now): ?int
    {
        $values = $headers->values('retry-after');

        if (count($values) !== 1) {
            return null;
        }

        $value = trim($values[0], " \t");

        if (preg_match('/^[0-9]{1,9}$/', $value) === 1) {
            return min((int) $value * 1000, PHP_INT_MAX);
        }

        $date = DateTimeImmutable::createFromFormat(
            self::HttpDateFormat,
            $value,
            new DateTimeZone('GMT'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (! $date instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format(self::HttpDateFormat) !== $value) {
            return null;
        }

        return max(0, $date->getTimestamp() - $now) * 1000;
    }
}
