<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use InvalidArgumentException;

final readonly class InvoiceDraft
{
    private const int MaximumPositions = 1000;

    /** @var list<InvoiceLine> */
    public array $positions;

    /**
     * @param  array<mixed>  $positions
     */
    public function __construct(
        public string $kind,
        public bool $income,
        public string $sellDate,
        public string $issueDate,
        public string $departmentId,
        public InvoiceBuyer $buyer,
        public InvoicePayment $payment,
        public string $description,
        array $positions,
        public string $number = '',
    ) {
        if (! self::validText($kind, 64)
            || ! self::validText($departmentId, 191)
            || ! self::validText($sellDate, 32)
            || ! self::validText($issueDate, 32)
            || ! self::validDescription($description)
            || ($number !== '' && ! self::validText($number, 191))) {
            throw new InvalidArgumentException('Invoice draft fields exceed the bounded outbound contract.');
        }

        if ($positions === [] || count($positions) > self::MaximumPositions) {
            throw new InvalidArgumentException('Invoice draft must contain a bounded non-empty position list.');
        }

        foreach ($positions as $position) {
            if (! $position instanceof InvoiceLine) {
                throw new InvalidArgumentException('Invoice draft positions must contain only invoice lines.');
            }

            if ($position->totalGross->currency !== $payment->paid->currency
                || $position->totalGross->fractionDigits !== $payment->paid->fractionDigits) {
                throw new InvalidArgumentException('Invoice draft money values must use one currency and scale.');
            }
        }

        $this->positions = array_values($positions);
    }

    public function currency(): string
    {
        return $this->payment->paid->currency;
    }

    public function totalGross(): Money
    {
        $total = new Money(0, $this->currency(), $this->payment->paid->fractionDigits);

        foreach ($this->positions as $position) {
            $total = $total->plus($position->totalGross);
        }

        return $total;
    }

    private static function validText(string $value, int $maximumBytes): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= $maximumBytes
            && preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }

    private static function validDescription(string $description): bool
    {
        return strlen($description) <= 10_000
            && preg_match('//u', $description) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\p{Cf}]/u', $description) !== 1;
    }
}
