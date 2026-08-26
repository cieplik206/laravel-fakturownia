<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use InvalidArgumentException;

final readonly class InvoiceLine
{
    private const int MaximumNameBytes = 1024;

    private const int MaximumUnitBytes = 32;

    public function __construct(
        public string $name,
        public string $tax,
        public Money $totalGross,
        public string $quantity,
        public string $unit = 'szt.',
    ) {
        self::assertBoundedText($name, self::MaximumNameBytes, 'name');

        if (preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D', $quantity) !== 1 || self::isZero($quantity)) {
            throw new InvalidArgumentException('Invoice line quantity must be a positive canonical decimal string.');
        }

        if (preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?\z/D', $tax) !== 1
            && ! in_array($tax, ['np', 'zw', 'disabled'], true)) {
            throw new InvalidArgumentException('Invoice line tax must be a canonical rate or a supported textual value.');
        }

        self::assertBoundedText($unit, self::MaximumUnitBytes, 'unit');
    }

    private static function isZero(string $decimal): bool
    {
        return preg_match('/\A0(?:\.0+)?\z/D', $decimal) === 1;
    }

    private static function assertBoundedText(string $value, int $maximumBytes, string $field): void
    {
        if ($value === ''
            || $value !== trim($value)
            || strlen($value) > $maximumBytes
            || preg_match('//u', $value) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            throw new InvalidArgumentException("Invoice line {$field} is invalid.");
        }
    }
}
