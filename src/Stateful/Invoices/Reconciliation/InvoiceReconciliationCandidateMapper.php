<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation;

use Cieplik206\Fakturownia\Read\Data\DecimalValue;
use Cieplik206\Fakturownia\Read\Data\InvoicePositionData;
use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\ExactOidLocator;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class InvoiceReconciliationCandidateMapper
{
    private const int MaximumPositions = 1000;

    public function map(
        ExactOidLocator $locator,
        InvoiceDraft $draft,
        InvoiceResponseData $response,
    ): ?InvoiceReconciliationCandidate {
        try {
            return $this->mapComplete($locator, $draft, $response);
        } catch (Throwable) {
            return null;
        }
    }

    private function mapComplete(
        ExactOidLocator $locator,
        InvoiceDraft $draft,
        InvoiceResponseData $response,
    ): ?InvoiceReconciliationCandidate {
        $kind = $response->kind?->raw;
        $status = $response->status?->raw;
        $issueDate = $response->issueDate?->value;
        $totalGross = $response->priceGross;
        $currency = $response->currency;
        $sourceOid = $response->sourceOid;
        $income = $response->income;
        $departmentId = $response->departmentId;
        $createdAt = $response->createdAt?->value;

        if (! self::isBoundedText($kind, 64)
            || ! self::isBoundedText($status, 128)
            || $issueDate === null
            || ! $totalGross instanceof DecimalValue
            || ! is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || ! self::isBoundedText($sourceOid, 256)
            || ! is_bool($income)
            || ! is_string($departmentId)
            || ! is_string($createdAt)
            || count($response->positions) > self::MaximumPositions
            || ! self::isOptionalBoundedText($response->number, 191, true)
            || ! self::isOptionalBoundedText($response->buyerTaxNumber, 256)) {
            return null;
        }

        $scale = $draft->payment->paid->fractionDigits;
        $mappedTotalGross = self::money($totalGross, $currency, $scale);

        if (! $mappedTotalGross instanceof Money) {
            return null;
        }

        $positions = [];

        foreach ($response->positions as $position) {
            $mapped = self::position($position, $currency, $scale);

            if (! $mapped instanceof InvoiceLine) {
                return null;
            }

            $positions[] = $mapped;
        }

        $invoice = new IssuedInvoiceResult(
            remoteId: $response->remoteId,
            number: $response->number ?? '',
            kind: $kind,
            status: $status,
            issueDate: $issueDate,
            buyerTaxNumber: $response->buyerTaxNumber,
            totalGross: $mappedTotalGross,
            oid: $sourceOid,
            positions: $positions,
        );

        return new InvoiceReconciliationCandidate(
            new RemoteIdentityScope(
                $locator->scope->connection,
                $kind,
                $departmentId,
            ),
            $income,
            (new DateTimeImmutable($createdAt))->setTimezone(new DateTimeZone('UTC')),
            $invoice,
        );
    }

    private static function position(
        InvoicePositionData $position,
        string $currency,
        int $scale,
    ): ?InvoiceLine {
        $totalGross = $position->totalPriceGross;

        if (! self::isBoundedText($position->name, 1024)
            || ! self::isBoundedText($position->tax, 16)
            || ! $position->quantity instanceof DecimalValue
            || ! self::isBoundedText($position->unit, 32)
            || ! $totalGross instanceof DecimalValue) {
            return null;
        }

        $money = self::money($totalGross, $currency, $scale);

        if (! $money instanceof Money) {
            return null;
        }

        return new InvoiceLine(
            $position->name,
            $position->tax,
            $money,
            $position->quantity->value,
            $position->unit,
        );
    }

    private static function money(
        DecimalValue $amount,
        string $currency,
        int $scale,
    ): ?Money {
        $separator = strpos($amount->value, '.');

        if ($separator !== false && strlen($amount->value) - $separator - 1 > $scale) {
            return null;
        }

        return Money::fromDecimal($amount->value, $currency, $scale);
    }

    private static function isBoundedText(?string $value, int $maximumBytes): bool
    {
        return is_string($value)
            && $value !== ''
            && $value === trim($value)
            && strlen($value) <= $maximumBytes
            && preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }

    private static function isOptionalBoundedText(
        ?string $value,
        int $maximumBytes,
        bool $allowEmpty = false,
    ): bool {
        if ($value === null || ($allowEmpty && $value === '')) {
            return true;
        }

        return self::isBoundedText($value, $maximumBytes);
    }
}
