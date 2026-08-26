<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Identity;

use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;

final readonly class InvoiceFingerprint
{
    private const string Protocol = 'cieplik206.fakturownia.invoice-fingerprint.v1';

    public function __construct(private HmacSha256 $hmac) {}

    public function fromDraft(InvoiceDraft $draft): VersionedHmacDigest
    {
        return $this->digest([
            'buyer_tax_identity' => $draft->buyer->normalizedTaxIdentity(),
            'currency' => $draft->currency(),
            'total_gross' => $draft->totalGross()->decimal(),
            'issue_date' => $draft->issueDate,
            'positions' => $this->positions($draft->positions),
        ]);
    }

    public function fromResult(IssuedInvoiceResult $result): VersionedHmacDigest
    {
        return $this->digest([
            'buyer_tax_identity' => self::normalizeTaxIdentity($result->buyerTaxNumber),
            'currency' => $result->totalGross->currency,
            'total_gross' => $result->totalGross->decimal(),
            'issue_date' => $result->issueDate,
            'positions' => $this->positions($result->positions),
        ]);
    }

    /**
     * @param  list<InvoiceLine>  $positions
     * @return list<array{name: string, quantity: string, tax: string, total_gross: string, unit: string}>
     */
    private function positions(array $positions): array
    {
        return array_map(
            static fn (InvoiceLine $position): array => [
                'name' => $position->name,
                'quantity' => $position->quantity,
                'tax' => $position->tax,
                'total_gross' => $position->totalGross->decimal(),
                'unit' => $position->unit,
            ],
            $positions,
        );
    }

    /** @param array<string, mixed> $value */
    private function digest(array $value): VersionedHmacDigest
    {
        return $this->hmac->digestCanonical(LookupHmacDomain::Payload, [
            'protocol' => self::Protocol,
            'invoice' => $value,
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
