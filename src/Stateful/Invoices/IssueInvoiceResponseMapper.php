<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use InvalidArgumentException;

final readonly class IssueInvoiceResponseMapper
{
    /** @param array<string, mixed> $response */
    public function map(array $response): IssuedInvoiceResult
    {
        $currency = $this->requiredString($response, 'currency');
        $positionsValue = $response['positions'] ?? null;

        if (! is_array($positionsValue) || ! array_is_list($positionsValue)) {
            throw new InvalidArgumentException('Issued invoice response positions must be a list.');
        }

        if ($positionsValue === [] || count($positionsValue) > 1000) {
            throw new InvalidArgumentException('Issued invoice response positions must be a bounded non-empty list.');
        }

        $positions = [];
        foreach ($positionsValue as $position) {
            if (! is_array($position)) {
                throw new InvalidArgumentException('Issued invoice response contains an invalid position.');
            }

            $positions[] = new InvoiceLine(
                name: $this->requiredString($position, 'name'),
                tax: $this->requiredDecimalLikeString($position, 'tax', allowTextualTax: true),
                totalGross: Money::fromDecimal(
                    $this->requiredDecimalLikeString($position, 'total_price_gross'),
                    $currency,
                ),
                quantity: $this->requiredDecimalLikeString($position, 'quantity'),
                unit: $this->optionalString($position, 'quantity_unit')
                    ?? $this->optionalString($position, 'unit')
                    ?? 'szt.',
            );
        }

        $knownKeys = [
            'id', 'number', 'kind', 'status', 'issue_date', 'buyer_tax_no',
            'price_gross', 'currency', 'oid', 'positions',
        ];

        return new IssuedInvoiceResult(
            remoteId: $this->requiredIdentifier($response, 'id'),
            number: $this->requiredString($response, 'number', allowEmpty: true),
            kind: $this->requiredString($response, 'kind'),
            status: $this->requiredString($response, 'status'),
            issueDate: $this->requiredString($response, 'issue_date'),
            buyerTaxNumber: $this->optionalString($response, 'buyer_tax_no'),
            totalGross: Money::fromDecimal(
                $this->requiredDecimalLikeString($response, 'price_gross'),
                $currency,
            ),
            oid: $this->optionalString($response, 'oid'),
            positions: $positions,
            extra: array_diff_key($response, array_flip($knownKeys)),
        );
    }

    /** @param array<string, mixed> $value */
    private function requiredIdentifier(array $value, string $key): string
    {
        $identifier = $value[$key] ?? null;

        if (is_int($identifier) && $identifier > 0) {
            return (string) $identifier;
        }

        if (is_string($identifier) && preg_match('/\A[1-9][0-9]*\z/D', $identifier) === 1) {
            return $identifier;
        }

        throw new InvalidArgumentException("Issued invoice response field {$key} must be a positive identifier.");
    }

    /** @param array<string, mixed> $value */
    private function requiredString(array $value, string $key, bool $allowEmpty = false): string
    {
        $field = $value[$key] ?? null;

        if (! is_string($field) || (! $allowEmpty && trim($field) === '')) {
            throw new InvalidArgumentException("Issued invoice response field {$key} must be a string.");
        }

        return $field;
    }

    /** @param array<string, mixed> $value */
    private function optionalString(array $value, string $key): ?string
    {
        $field = $value[$key] ?? null;

        if ($field === null || $field === '') {
            return null;
        }

        if (! is_string($field)) {
            throw new InvalidArgumentException("Issued invoice response field {$key} must be a nullable string.");
        }

        return $field;
    }

    /** @param array<string, mixed> $value */
    private function requiredDecimalLikeString(
        array $value,
        string $key,
        bool $allowTextualTax = false,
    ): string {
        $field = $value[$key] ?? null;

        if (is_int($field)) {
            return (string) $field;
        }

        if (! is_string($field)) {
            throw new InvalidArgumentException("Issued invoice response field {$key} must not be a float.");
        }

        if ($allowTextualTax && in_array($field, ['np', 'zw', 'disabled'], true)) {
            return $field;
        }

        if (preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $field) !== 1) {
            throw new InvalidArgumentException("Issued invoice response field {$key} must be a canonical decimal string.");
        }

        return $field;
    }
}
