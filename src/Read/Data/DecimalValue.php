<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use InvalidArgumentException;
use JsonSerializable;

final readonly class DecimalValue implements JsonSerializable
{
    private function __construct(public string $value) {}

    public static function from(int|float|string $value): self
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('A decimal value must be finite.');
            }

            $value = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
        }

        $value = (string) $value;

        if (preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('A decimal value has an invalid representation.');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '+-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $canonical = $fraction === '' ? $integer : "{$integer}.{$fraction}";

        if ($negative && $canonical !== '0') {
            $canonical = "-{$canonical}";
        }

        return new self($canonical);
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
