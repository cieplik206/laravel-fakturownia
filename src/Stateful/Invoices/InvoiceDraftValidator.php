<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use DateTimeImmutable;

final readonly class InvoiceDraftValidator
{
    private const int KsefDescriptionLimit = 3500;

    private const int KsefPositionNameLimit = 256;

    public function validate(
        InvoiceDraft $draft,
        InvoiceValidationProfile $profile,
    ): InvoiceDraftValidationResult {
        $issues = [];

        if (! self::isDate($draft->sellDate)) {
            $issues[] = new InvoiceDraftValidationIssue('sell_date', 'invalid_date');
        }

        if (! self::isDate($draft->issueDate)) {
            $issues[] = new InvoiceDraftValidationIssue('issue_date', 'invalid_date');
        }

        if (preg_match('/\A[1-9][0-9]*\z/D', $draft->departmentId) !== 1) {
            $issues[] = new InvoiceDraftValidationIssue('department_id', 'invalid_remote_id');
        }

        if ($draft->buyer->company && $draft->buyer->normalizedTaxIdentity() === null) {
            $issues[] = new InvoiceDraftValidationIssue('buyer.tax_number', 'required_for_company');
        }

        if (trim($draft->buyer->country) === '') {
            $issues[] = new InvoiceDraftValidationIssue('buyer.country', 'required');
        }

        if (! $profile->usesKsefConstraints()) {
            return new InvoiceDraftValidationResult($issues);
        }

        if (self::characterLength($draft->description) > self::KsefDescriptionLimit) {
            $issues[] = new InvoiceDraftValidationIssue('description', 'ksef_max_length');
        }

        if (! $draft->buyer->company && $draft->buyer->taxNumberKind !== 'empty') {
            $issues[] = new InvoiceDraftValidationIssue('buyer.tax_number_kind', 'ksef_private_buyer_requires_empty_kind');
        }

        if (preg_match('/\A[A-Z]{2}\z/D', $draft->buyer->country) !== 1) {
            $issues[] = new InvoiceDraftValidationIssue('buyer.country', 'ksef_requires_iso_alpha2');
        }

        foreach ($draft->positions as $index => $position) {
            if (self::characterLength($position->name) > self::KsefPositionNameLimit) {
                $issues[] = new InvoiceDraftValidationIssue("positions.{$index}.name", 'ksef_max_length');
            }
        }

        return new InvoiceDraftValidationResult($issues);
    }

    private static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private static function characterLength(string $value): int
    {
        $matches = [];
        $characterCount = preg_match_all('/./us', $value, $matches);

        return is_int($characterCount) ? $characterCount : strlen($value);
    }
}
