<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use InvalidArgumentException;

final readonly class CorrectionPositionAttributes
{
    private const int MaximumNameBytes = 256;

    private const int MaximumUnitBytes = 32;

    public function __construct(
        public CorrectionPositionKind $kind,
        public string $name,
        public string $quantity,
        public Money $totalGross,
        public string $tax,
        public string $unit = 'szt.',
        public ?Money $priceNet = null,
        public ?Money $priceGross = null,
        public ?Money $totalNet = null,
    ) {
        self::assertBoundedText($name, self::MaximumNameBytes, 'name');
        self::assertBoundedText($unit, self::MaximumUnitBytes, 'unit');

        if (preg_match('/\A-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D', $quantity) !== 1
            || (str_starts_with($quantity, '-') && preg_match('/\A-0(?:\.0+)?\z/D', $quantity) === 1)) {
            throw new InvalidArgumentException('Correction position quantity must be a canonical decimal string.');
        }

        if (preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?\z/D', $tax) !== 1
            && ! in_array($tax, ['np', 'zw', 'disabled'], true)) {
            throw new InvalidArgumentException('Correction position tax must be a canonical rate or a supported textual value.');
        }

        foreach ([$priceNet, $priceGross, $totalNet] as $amount) {
            if ($amount !== null
                && ($amount->currency !== $totalGross->currency
                    || $amount->fractionDigits !== $totalGross->fractionDigits)) {
                throw new InvalidArgumentException('Correction position amounts must share one currency scale.');
            }
        }
    }

    private static function assertBoundedText(string $value, int $maximumBytes, string $field): void
    {
        if ($value === ''
            || $value !== trim($value)
            || strlen($value) > $maximumBytes
            || preg_match('//u', $value) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            throw new InvalidArgumentException("Correction position {$field} is invalid.");
        }
    }
}
