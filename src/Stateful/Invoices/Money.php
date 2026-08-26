<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use InvalidArgumentException;
use Stringable;

final readonly class Money implements Stringable
{
    private const int MaximumFractionDigits = 6;

    public function __construct(
        public int $minorUnits,
        public string $currency,
        public int $fractionDigits = 2,
    ) {
        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new InvalidArgumentException('Money currency must be an uppercase ISO 4217 code.');
        }

        if ($fractionDigits < 0 || $fractionDigits > self::MaximumFractionDigits) {
            throw new InvalidArgumentException('Money fraction digits must be between zero and six.');
        }

        if ($minorUnits === PHP_INT_MIN) {
            throw new InvalidArgumentException('Money minor units exceed the supported integer range.');
        }
    }

    public static function fromDecimal(
        string $amount,
        string $currency = 'PLN',
        int $fractionDigits = 2,
    ): self {
        if (preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?\z/D', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Money amount must be a canonical decimal string.');
        }

        if ($fractionDigits < 0 || $fractionDigits > self::MaximumFractionDigits) {
            throw new InvalidArgumentException('Money fraction digits must be between zero and six.');
        }

        $normalizedCurrency = strtoupper(trim($currency));
        $negative = $matches[1] === '-';
        $integerDigits = $matches[2];
        $fraction = $matches[3] ?? '';
        $paddedFraction = str_pad($fraction, $fractionDigits + 1, '0');
        $keptFraction = substr($paddedFraction, 0, $fractionDigits);
        $discardedFraction = substr($paddedFraction, $fractionDigits);
        $absoluteMinorUnits = ltrim($integerDigits.$keptFraction, '0');
        $absoluteMinorUnits = $absoluteMinorUnits === '' ? '0' : $absoluteMinorUnits;

        if ($discardedFraction !== '' && $discardedFraction[0] >= '5') {
            $absoluteMinorUnits = self::incrementDecimalDigits($absoluteMinorUnits);
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($absoluteMinorUnits) > strlen($maximum)
            || (strlen($absoluteMinorUnits) === strlen($maximum) && strcmp($absoluteMinorUnits, $maximum) > 0)) {
            throw new InvalidArgumentException('Money amount exceeds the supported integer range.');
        }

        $minorUnits = (int) $absoluteMinorUnits;
        if ($negative && $minorUnits !== 0) {
            $minorUnits *= -1;
        }

        return new self($minorUnits, $normalizedCurrency, $fractionDigits);
    }

    public function decimal(): string
    {
        $negative = $this->minorUnits < 0;
        $digits = (string) abs($this->minorUnits);

        if ($this->fractionDigits === 0) {
            return ($negative ? '-' : '').$digits;
        }

        $digits = str_pad($digits, $this->fractionDigits + 1, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, -$this->fractionDigits);
        $fraction = substr($digits, -$this->fractionDigits);

        return ($negative ? '-' : '').$integer.'.'.$fraction;
    }

    public function plus(self $other): self
    {
        if ($this->currency !== $other->currency || $this->fractionDigits !== $other->fractionDigits) {
            throw new InvalidArgumentException('Money values must use the same currency and fraction digits.');
        }

        if (($other->minorUnits > 0 && $this->minorUnits > PHP_INT_MAX - $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits < PHP_INT_MIN - $other->minorUnits)) {
            throw new InvalidArgumentException('Money addition exceeds the supported integer range.');
        }

        return new self(
            $this->minorUnits + $other->minorUnits,
            $this->currency,
            $this->fractionDigits,
        );
    }

    public function multipliedBy(int $multiplier): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException('Money multiplier must not be negative.');
        }

        if ($multiplier !== 0 && $this->minorUnits > intdiv(PHP_INT_MAX, $multiplier)) {
            throw new InvalidArgumentException('Money multiplication exceeds the supported integer range.');
        }

        if ($multiplier !== 0 && $this->minorUnits < intdiv(PHP_INT_MIN, $multiplier)) {
            throw new InvalidArgumentException('Money multiplication exceeds the supported integer range.');
        }

        return new self(
            $this->minorUnits * $multiplier,
            $this->currency,
            $this->fractionDigits,
        );
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency === $other->currency
            && $this->fractionDigits === $other->fractionDigits;
    }

    public function __toString(): string
    {
        return $this->decimal().' '.$this->currency;
    }

    private static function incrementDecimalDigits(string $digits): string
    {
        $characters = str_split($digits);

        for ($index = count($characters) - 1; $index >= 0; $index--) {
            if ($characters[$index] !== '9') {
                $characters[$index] = (string) ((int) $characters[$index] + 1);

                return implode('', $characters);
            }

            $characters[$index] = '0';
        }

        return '1'.implode('', $characters);
    }
}
