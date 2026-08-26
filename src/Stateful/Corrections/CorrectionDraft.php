<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CorrectionDraft
{
    use RejectsNativeSerialization;

    private const int MaximumPositions = 1000;

    /** @var non-empty-list<CorrectionLine> */
    public array $positions;

    /** @param array<mixed> $positions */
    public function __construct(
        public string $sourceInvoiceId,
        public int $departmentId,
        public string $reason,
        public InvoiceBuyer $buyer,
        array $positions,
        public ?string $issueDate = null,
        public ?string $sellDate = null,
        public ?string $clientId = null,
    ) {
        if (! self::validText($sourceInvoiceId, 191)
            || ! self::validText($reason, 256)
            || $departmentId < 1) {
            throw new InvalidArgumentException('Correction source, department, and reason are required.');
        }

        if ($positions === [] || count($positions) > self::MaximumPositions) {
            throw new InvalidArgumentException('Correction reason or positions exceed the supported contract.');
        }

        $hasEffectivePosition = false;

        foreach ($positions as $position) {
            if (! $position instanceof CorrectionLine) {
                throw new InvalidArgumentException('Correction positions must contain only correction lines.');
            }

            if ($position->mode !== CorrectionLineMode::Preserved) {
                $hasEffectivePosition = true;
            }
        }

        if (! $hasEffectivePosition) {
            throw new InvalidArgumentException('A correction draft must contain at least one effective position.');
        }

        foreach ([$issueDate, $sellDate] as $date) {
            if ($date !== null && ! self::validDate($date)) {
                throw new InvalidArgumentException('Correction dates must use the YYYY-MM-DD format.');
            }
        }

        if ($clientId !== null && ! self::validText($clientId, 191)) {
            throw new InvalidArgumentException('Correction client ID must not be empty when present.');
        }

        self::assertBoundedBuyer($buyer);

        /** @var non-empty-list<CorrectionLine> $normalized */
        $normalized = array_values($positions);
        $currency = $normalized[0]->totalGross->currency;
        $fractionDigits = $normalized[0]->totalGross->fractionDigits;

        foreach ($normalized as $position) {
            if ($position->totalGross->currency !== $currency
                || $position->totalGross->fractionDigits !== $fractionDigits) {
                throw new InvalidArgumentException('Correction positions must share one currency scale.');
            }
        }

        $this->positions = $normalized;
    }

    public function currency(): string
    {
        return $this->positions[0]->totalGross->currency;
    }

    private static function validText(string $value, int $maximumLength): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= $maximumLength
            && preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }

    private static function validDate(string $date): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private static function assertBoundedBuyer(InvoiceBuyer $buyer): void
    {
        if (! self::validText($buyer->name, 256)
            || ! self::validOptionalText($buyer->taxNumber, 64, true)
            || ! self::validOptionalText($buyer->postCode, 32, true)
            || ! self::validOptionalText($buyer->city, 128, true)
            || ! self::validOptionalText($buyer->street, 256, true)
            || ! self::validOptionalText($buyer->country, 64, true)
            || ! self::validOptionalText($buyer->lastName, 128, true)
            || ! self::validOptionalText($buyer->firstName, 128, true)
            || ! self::validOptionalText($buyer->taxNumberKind, 32, true)
            || ! self::validEmail($buyer->email)) {
            throw new InvalidArgumentException('Correction buyer fields exceed the bounded outbound contract.');
        }
    }

    private static function validOptionalText(
        ?string $value,
        int $maximumLength,
        bool $allowsEmpty = false,
    ): bool {
        if ($value === null || ($allowsEmpty && $value === '')) {
            return true;
        }

        return self::validText($value, $maximumLength);
    }

    private static function validEmail(string $email): bool
    {
        if ($email === '') {
            return true;
        }

        return self::validText($email, 254)
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
