<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use InvalidArgumentException;

final readonly class IssueCorrectionResponseMapper
{
    /** @param array<string, mixed> $response */
    public function map(array $response): IssuedCorrectionResult
    {
        $remoteId = $this->stringValue($response, 'id', positiveInteger: true);
        $sourceInvoiceId = $this->sourceInvoiceId($response);
        $number = $this->stringValue($response, 'number', allowEmpty: true);
        $status = $this->stringValue($response, 'status');
        $totalGross = $this->stringValue($response, 'total_price_gross');
        $currency = $this->stringValue($response, 'currency');
        $extra = array_diff_key($response, array_flip([
            'id',
            'from_invoice_id',
            'invoice_id',
            'number',
            'status',
            'total_price_gross',
            'currency',
        ]));

        return new IssuedCorrectionResult(
            $remoteId,
            $sourceInvoiceId,
            $number,
            $status,
            Money::fromDecimal($totalGross, $currency),
            $extra,
        );
    }

    /** @param array<string, mixed> $payload */
    private function stringValue(
        array $payload,
        string $key,
        ?string $fallbackKey = null,
        bool $allowEmpty = false,
        bool $positiveInteger = false,
    ): string {
        $value = $payload[$key] ?? ($fallbackKey === null ? null : ($payload[$fallbackKey] ?? null));

        if (is_int($value)) {
            if ($positiveInteger && $value < 1) {
                throw new InvalidArgumentException('The correction response contains an invalid remote identifier.');
            }

            $value = (string) $value;
        }

        if (! is_string($value) || (! $allowEmpty && trim($value) === '')) {
            throw new InvalidArgumentException('The correction response is incomplete.');
        }

        return $value;
    }

    /** @param array<string, mixed> $response */
    private function sourceInvoiceId(array $response): string
    {
        $hasFromInvoiceId = array_key_exists('from_invoice_id', $response);
        $hasInvoiceId = array_key_exists('invoice_id', $response);

        if (! $hasFromInvoiceId && ! $hasInvoiceId) {
            throw new InvalidArgumentException('The correction response is incomplete.');
        }

        $fromInvoiceId = $hasFromInvoiceId
            ? $this->stringValue($response, 'from_invoice_id', positiveInteger: true)
            : null;
        $invoiceId = $hasInvoiceId
            ? $this->stringValue($response, 'invoice_id', positiveInteger: true)
            : null;

        if ($fromInvoiceId !== null && $invoiceId !== null && ! hash_equals($fromInvoiceId, $invoiceId)) {
            throw new InvalidArgumentException('The correction response contains conflicting source invoice identifiers.');
        }

        return $fromInvoiceId ?? $invoiceId
            ?? throw new InvalidArgumentException('The correction response is incomplete.');
    }
}
