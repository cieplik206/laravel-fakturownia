<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use InvalidArgumentException;

final readonly class CorrectionLine
{
    private const int MaximumNameBytes = 256;

    private const int MaximumUnitBytes = 32;

    public function __construct(
        public string $name,
        public string $quantity,
        public Money $totalGross,
        public string $tax,
        public CorrectionPositionAttributes $before,
        public CorrectionPositionAttributes $after,
        public string $unit = 'szt.',
        public ?Money $priceNet = null,
        public ?Money $priceGross = null,
        public ?Money $totalNet = null,
        public CorrectionLineMode $mode = CorrectionLineMode::Quantity,
    ) {
        self::assertBoundedText($name, self::MaximumNameBytes, 'name');
        self::assertBoundedText($unit, self::MaximumUnitBytes, 'unit');

        if (preg_match('/\A-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D', $quantity) !== 1
            || (str_starts_with($quantity, '-') && self::isZeroQuantity($quantity))) {
            throw new InvalidArgumentException('Correction line quantity must be a canonical decimal string.');
        }

        if ($before->kind !== CorrectionPositionKind::Before || $after->kind !== CorrectionPositionKind::After) {
            throw new InvalidArgumentException('Correction line requires ordered before and after snapshots.');
        }

        foreach ([$before->totalGross, $after->totalGross, $priceNet, $priceGross, $totalNet] as $amount) {
            if ($amount !== null
                && ($amount->currency !== $totalGross->currency
                    || $amount->fractionDigits !== $totalGross->fractionDigits)) {
                throw new InvalidArgumentException('Correction line amounts must share one currency scale.');
            }
        }

        if (! hash_equals($before->name, $name)
            || ! hash_equals($after->name, $name)
            || ! hash_equals($before->tax, $tax)
            || ! hash_equals($after->tax, $tax)
            || ! hash_equals($before->unit, $unit)
            || ! hash_equals($after->unit, $unit)) {
            throw new InvalidArgumentException('Correction line identity must match both snapshots.');
        }

        if (! $before->totalGross->plus($totalGross)->equals($after->totalGross)
            || ! self::quantityDeltaMatches($before->quantity, $quantity, $after->quantity)) {
            throw new InvalidArgumentException('Correction line delta must exactly reconcile its before and after snapshots.');
        }

        $this->assertModeInvariant();
    }

    private function assertModeInvariant(): void
    {
        $quantityIsZero = self::isZeroQuantity($this->quantity);
        $grossIsZero = $this->totalGross->minorUnits === 0;

        if ($this->mode === CorrectionLineMode::Quantity && $quantityIsZero) {
            throw new InvalidArgumentException('A quantity correction must change the quantity.');
        }

        if ($this->mode === CorrectionLineMode::Value && (! $quantityIsZero || $grossIsZero)) {
            throw new InvalidArgumentException('A value correction must preserve quantity and change gross value.');
        }

        if ($this->mode === CorrectionLineMode::Preserved
            && (! $quantityIsZero
                || ! $grossIsZero
                || ! $this->snapshotsAreIdentical()
                || self::hasNonZeroMoney($this->priceNet)
                || self::hasNonZeroMoney($this->priceGross)
                || ($this->totalNet !== null && $this->totalNet->minorUnits !== 0))) {
            throw new InvalidArgumentException('A preserved correction line must be an exact zero-delta snapshot.');
        }
    }

    private function snapshotsAreIdentical(): bool
    {
        return hash_equals($this->before->name, $this->after->name)
            && hash_equals($this->before->quantity, $this->after->quantity)
            && $this->before->totalGross->equals($this->after->totalGross)
            && hash_equals($this->before->tax, $this->after->tax)
            && hash_equals($this->before->unit, $this->after->unit)
            && self::optionalMoneyEquals($this->before->priceNet, $this->after->priceNet)
            && self::optionalMoneyEquals($this->before->priceGross, $this->after->priceGross)
            && self::optionalMoneyEquals($this->before->totalNet, $this->after->totalNet);
    }

    private static function optionalMoneyEquals(?Money $left, ?Money $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return $left->equals($right);
    }

    private static function hasNonZeroMoney(?Money $money): bool
    {
        return $money !== null && $money->minorUnits !== 0;
    }

    private static function isZeroQuantity(string $quantity): bool
    {
        return preg_match('/\A-?0(?:\.0+)?\z/D', $quantity) === 1;
    }

    private static function quantityDeltaMatches(string $before, string $delta, string $after): bool
    {
        $scale = max(self::fractionDigits($before), self::fractionDigits($delta), self::fractionDigits($after));

        return self::scaledQuantity($before, $scale) + self::scaledQuantity($delta, $scale)
            === self::scaledQuantity($after, $scale);
    }

    private static function assertBoundedText(string $value, int $maximumBytes, string $field): void
    {
        if ($value === ''
            || $value !== trim($value)
            || strlen($value) > $maximumBytes
            || preg_match('//u', $value) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            throw new InvalidArgumentException("Correction line {$field} is invalid.");
        }
    }

    private static function fractionDigits(string $quantity): int
    {
        $separator = strpos($quantity, '.');

        return $separator === false ? 0 : strlen($quantity) - $separator - 1;
    }

    private static function scaledQuantity(string $quantity, int $scale): int
    {
        $negative = str_starts_with($quantity, '-');
        $absolute = $negative ? substr($quantity, 1) : $quantity;
        [$integer, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $scaled = (int) ($integer.str_pad($fraction, $scale, '0'));

        return $negative ? -$scaled : $scaled;
    }
}
