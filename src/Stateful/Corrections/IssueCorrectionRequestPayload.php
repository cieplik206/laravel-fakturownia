<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use InvalidArgumentException;
use JsonException;

final readonly class IssueCorrectionRequestPayload
{
    use RejectsNativeSerialization;

    private const int MaximumPayloadBytes = 262_144;

    /** @param array<string, mixed> $invoice */
    private function __construct(private array $invoice)
    {
        try {
            $canonical = (new CanonicalJsonV1)->encode(new CanonicalObject(['invoice' => $invoice]));
        } catch (JsonException) {
            throw new InvalidArgumentException('Correction request payload cannot be canonicalized.');
        }

        if (strlen($canonical) > self::MaximumPayloadBytes) {
            throw new InvalidArgumentException('Correction request payload exceeds the plaintext byte limit.');
        }
    }

    public static function fromDraft(
        CorrectionDraft $draft,
        RemoteInvoiceIdentity $identity,
    ): self {
        if ($identity->scope->documentKind !== 'correction'
            || $identity->scope->departmentId !== (string) $draft->departmentId) {
            throw new InvalidArgumentException('Correction request identity does not match the draft scope.');
        }

        $invoice = [
            'kind' => 'correction',
            'correction_reason' => $draft->reason,
            'invoice_id' => $draft->sourceInvoiceId,
            'from_invoice_id' => $draft->sourceInvoiceId,
            'department_id' => $draft->departmentId,
            'buyer_company' => $draft->buyer->company,
            'buyer_tax_no' => $draft->buyer->taxNumber,
            'buyer_tax_no_kind' => $draft->buyer->taxNumberKind ?? '',
            'buyer_name' => $draft->buyer->name,
            'buyer_first_name' => $draft->buyer->firstName,
            'buyer_last_name' => $draft->buyer->lastName,
            'buyer_country' => $draft->buyer->country,
            'buyer_street' => $draft->buyer->street,
            'buyer_post_code' => $draft->buyer->postCode,
            'buyer_city' => $draft->buyer->city,
            'buyer_email' => $draft->buyer->email,
            'client_id' => $draft->clientId,
            'issue_date' => $draft->issueDate,
            'sell_date' => $draft->sellDate,
            'currency' => $draft->currency(),
            'positions' => array_map(
                static fn (CorrectionLine $line): array => self::mapLine($line),
                $draft->positions,
            ),
        ];

        if ($identity->oid() !== null) {
            $invoice['oid'] = $identity->oid();
        }

        if ($identity->usesOidUnique()) {
            $invoice['oid_unique'] = 'yes';
        }

        return new self($invoice);
    }

    /** @return array{placement: string, field: string} */
    public function authenticationContract(): array
    {
        return ['placement' => 'json_body_top_level', 'field' => 'api_token'];
    }

    /** @return array{Accept: string, Content-Type: string} */
    public function headers(): array
    {
        return ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
    }

    /** @return array<string, never> */
    public function query(): array
    {
        return [];
    }

    /** @return array{invoice: array<string, mixed>} */
    public function bodyWithoutCredentials(): array
    {
        return ['invoice' => $this->invoice];
    }

    /** @return array{invoice: string, credentials: string} */
    public function __debugInfo(): array
    {
        return ['invoice' => '[REDACTED]', 'credentials' => '[NOT_PRESENT]'];
    }

    /** @return array<string, mixed> */
    private static function mapLine(CorrectionLine $line): array
    {
        return array_filter([
            'name' => $line->name,
            'quantity' => $line->quantity,
            'total_price_gross' => $line->totalGross->decimal(),
            'tax' => $line->tax,
            'kind' => 'correction',
            'quantity_unit' => $line->unit,
            'price_net' => $line->priceNet?->decimal(),
            'price_gross' => $line->priceGross?->decimal(),
            'total_price_net' => $line->totalNet?->decimal(),
            'correction_before_attributes' => self::mapAttributes($line->before),
            'correction_after_attributes' => self::mapAttributes($line->after),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private static function mapAttributes(CorrectionPositionAttributes $attributes): array
    {
        return array_filter([
            'name' => $attributes->name,
            'quantity' => $attributes->quantity,
            'total_price_gross' => $attributes->totalGross->decimal(),
            'tax' => $attributes->tax,
            'kind' => $attributes->kind->value,
            'quantity_unit' => $attributes->unit,
            'price_net' => $attributes->priceNet?->decimal(),
            'price_gross' => $attributes->priceGross?->decimal(),
            'total_price_net' => $attributes->totalNet?->decimal(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
