<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Support;

use InvalidArgumentException;
use ReflectionReference;

/** @internal */
final class PayloadSanitizer
{
    private const MaximumDepth = 32;

    private const MaximumNodes = 20_000;

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function sanitizeArray(array $value): array
    {
        $nodes = 0;
        $sanitized = self::sanitizeValue($value, 0, $nodes);

        if (! is_array($sanitized)) {
            throw new InvalidArgumentException('The payload must be an array.');
        }

        return $sanitized;
    }

    private static function sanitizeValue(mixed $value, int $depth, int &$nodes): mixed
    {
        $nodes++;

        if ($nodes > self::MaximumNodes) {
            throw new InvalidArgumentException('The payload exceeds the maximum node count.');
        }

        if ($depth > self::MaximumDepth) {
            throw new InvalidArgumentException('The payload exceeds the maximum nesting depth.');
        }

        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException('The payload contains invalid UTF-8.');
            }

            return $value;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('The payload contains a non-finite number.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('The payload contains an unsupported value.');
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if (ReflectionReference::fromArrayElement($value, $key) !== null) {
                throw new InvalidArgumentException('The payload must not contain references.');
            }

            if (is_string($key) && preg_match('//u', $key) !== 1) {
                throw new InvalidArgumentException('The payload contains an invalid UTF-8 map key.');
            }

            $sanitized[$key] = self::sanitizeValue($item, $depth + 1, $nodes);
        }

        return $sanitized;
    }
}
