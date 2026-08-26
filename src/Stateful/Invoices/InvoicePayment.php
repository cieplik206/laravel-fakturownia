<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use InvalidArgumentException;

final readonly class InvoicePayment
{
    public function __construct(
        public string $type,
        public string $status,
        public Money $paid,
        public string $dueKind,
        public ?string $paidDate = null,
        public ?string $dueDate = null,
    ) {
        if (! self::validText($type, 128)
            || ! self::validText($status, 128)
            || ! self::validText($dueKind, 128)
            || ! self::validOptionalText($paidDate, 32)
            || ! self::validOptionalText($dueDate, 32)) {
            throw new InvalidArgumentException('Invoice payment fields exceed the bounded outbound contract.');
        }
    }

    private static function validOptionalText(?string $value, int $maximumBytes): bool
    {
        return $value === null || self::validText($value, $maximumBytes);
    }

    private static function validText(string $value, int $maximumBytes): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= $maximumBytes
            && preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }
}
