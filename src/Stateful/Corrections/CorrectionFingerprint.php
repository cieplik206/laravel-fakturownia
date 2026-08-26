<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

use Cieplik206\Fakturownia\Read\Data\InvoicePositionData;
use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use InvalidArgumentException;

final readonly class CorrectionFingerprint
{
    private const string Protocol = 'cieplik206.fakturownia.correction-fingerprint.v1';

    public function __construct(private HmacSha256 $hmac) {}

    public function fromDraft(CorrectionDraft $draft): VersionedHmacDigest
    {
        return $this->digest([
            'source_invoice_id' => $draft->sourceInvoiceId,
            'buyer_tax_identity' => $draft->buyer->normalizedTaxIdentity(),
            'currency' => $draft->currency(),
            'total_gross' => $draft->totalGross()->decimal(),
            'issue_date' => $draft->issueDate,
            'positions' => array_map(
                static fn (CorrectionLine $position): array => [
                    'name' => $position->name,
                    'quantity' => $position->quantity,
                    'tax' => $position->tax,
                    'total_gross' => $position->totalGross->decimal(),
                    'unit' => $position->unit,
                    'before' => self::attributes($position->before),
                    'after' => self::attributes($position->after),
                ],
                $draft->positions,
            ),
        ]);
    }

    public function fromRemote(InvoiceResponseData $invoice): VersionedHmacDigest
    {
        if ($invoice->fromInvoiceId === null
            || $invoice->priceGross === null
            || $invoice->currency === null
            || $invoice->issueDate === null) {
            throw new InvalidArgumentException('The remote correction snapshot is incomplete.');
        }

        return $this->digest([
            'source_invoice_id' => $invoice->fromInvoiceId,
            'buyer_tax_identity' => self::normalizeTaxIdentity($invoice->buyerTaxNumber),
            'currency' => $invoice->currency,
            'total_gross' => $invoice->priceGross->value,
            'issue_date' => $invoice->issueDate->value,
            'positions' => array_map(
                static fn (InvoicePositionData $position): array => self::remotePosition($position),
                $invoice->positions,
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private static function attributes(CorrectionPositionAttributes $attributes): array
    {
        return [
            'name' => $attributes->name,
            'quantity' => $attributes->quantity,
            'tax' => $attributes->tax,
            'total_gross' => $attributes->totalGross->decimal(),
            'unit' => $attributes->unit,
        ];
    }

    /** @return array<string, mixed> */
    private static function remotePosition(InvoicePositionData $position): array
    {
        $before = $position->extra()['correction_before_attributes'] ?? null;
        $after = $position->extra()['correction_after_attributes'] ?? null;

        if (! is_array($before) || ! is_array($after)) {
            throw new InvalidArgumentException('The remote correction position has no before/after attributes.');
        }

        return [
            'name' => $position->name,
            'quantity' => $position->quantity?->value,
            'tax' => $position->tax,
            'total_gross' => $position->totalPriceGross?->value,
            'unit' => $position->unit,
            'before' => self::remoteAttributes($before),
            'after' => self::remoteAttributes($after),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{name: mixed, quantity: ?string, tax: ?string, total_gross: ?string, unit: mixed}
     */
    private static function remoteAttributes(array $attributes): array
    {
        return [
            'name' => $attributes['name'] ?? null,
            'quantity' => self::decimal($attributes['quantity'] ?? null),
            'tax' => self::scalarString($attributes['tax'] ?? null),
            'total_gross' => self::decimal($attributes['total_price_gross'] ?? null),
            'unit' => $attributes['quantity_unit'] ?? ($attributes['unit'] ?? null),
        ];
    }

    private static function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) === 1
            ? $value
            : null;
    }

    private static function scalarString(mixed $value): ?string
    {
        return is_int($value) || is_string($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $value */
    private function digest(array $value): VersionedHmacDigest
    {
        return $this->hmac->digestCanonical(LookupHmacDomain::Payload, [
            'protocol' => self::Protocol,
            'correction' => $value,
        ]);
    }

    private static function normalizeTaxIdentity(?string $taxNumber): ?string
    {
        if ($taxNumber === null || trim($taxNumber) === '') {
            return null;
        }

        $normalized = preg_replace('/[\s.\-]+/u', '', strtoupper(trim($taxNumber)));

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }
}
