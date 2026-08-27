<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Proformas;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraftValidator;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceValidationProfile;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use InvalidArgumentException;
use JsonException;

final readonly class ProformaRequestPayload
{
    use RejectsNativeSerialization;

    private const int MaximumPayloadBytes = 262_144;

    /** @param array<string, mixed> $proforma */
    private function __construct(private array $proforma)
    {
        try {
            $canonical = (new CanonicalJsonV1)->encode(new CanonicalObject([
                'invoice' => $proforma,
            ]));
        } catch (JsonException) {
            throw new InvalidArgumentException('Proforma request payload cannot be canonicalized.');
        }

        if (strlen($canonical) > self::MaximumPayloadBytes) {
            throw new InvalidArgumentException('Proforma request payload exceeds the plaintext byte limit.');
        }
    }

    public static function fromDraft(
        ProformaDraft $draft,
        ?RemoteInvoiceIdentity $identity = null,
    ): self {
        $draft = new ProformaDraft(
            sellDate: $draft->sellDate,
            issueDate: $draft->issueDate,
            departmentId: $draft->departmentId,
            buyer: $draft->buyer,
            paymentType: $draft->paymentType,
            paymentDueDate: $draft->paymentDueDate,
            description: $draft->description,
            positions: $draft->positions,
            number: $draft->number,
        );
        $invoice = $draft->toInvoiceDraft();

        (new InvoiceDraftValidator)
            ->validate($invoice, InvoiceValidationProfile::Standard)
            ->throwIfInvalid();

        if ($identity !== null
            && ($identity->scope->documentKind !== 'proforma'
                || $identity->scope->departmentId !== $draft->departmentId)) {
            throw new InvalidArgumentException('Remote identity scope does not match the proforma draft.');
        }

        $proforma = [
            'kind' => 'proforma',
            'income' => '1',
            'sell_date' => $draft->sellDate,
            'issue_date' => $draft->issueDate,
            'buyer_company' => $draft->buyer->company,
            'department_id' => $draft->departmentId,
            'buyer_last_name' => $draft->buyer->lastName,
            'buyer_name' => $draft->buyer->name,
            'buyer_tax_no' => $draft->buyer->taxNumber,
            'buyer_post_code' => $draft->buyer->postCode,
            'buyer_city' => $draft->buyer->city,
            'buyer_street' => $draft->buyer->street,
            'buyer_country' => $draft->buyer->country,
            'buyer_email' => $draft->buyer->email,
            'payment_type' => $draft->payment->type,
            'status' => $draft->payment->status,
            'paid' => $draft->payment->paid->decimal(),
            'description' => $draft->description,
            'payment_to_kind' => $draft->payment->dueKind,
            'paid_date' => $draft->payment->paidDate,
            'positions' => array_map(
                static fn (InvoiceLine $position): array => [
                    'name' => $position->name,
                    'tax' => $position->tax,
                    'total_price_gross' => $position->totalGross->decimal(),
                    'quantity' => $position->quantity,
                    'quantity_unit' => $position->unit,
                ],
                $draft->positions,
            ),
            'payment_to' => $draft->payment->dueDate,
            'buyer_tax_no_kind' => $draft->buyer->taxNumberKind,
            'buyer_first_name' => $draft->buyer->firstName,
            'number' => $draft->number,
        ];
        $oid = $identity?->oid();

        if ($oid !== null) {
            $proforma['oid'] = $oid;
        }

        if ($identity?->usesOidUnique() === true) {
            $proforma['oid_unique'] = 'yes';
        }

        return new self($proforma);
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
        return ['invoice' => $this->proforma];
    }

    /** @return array{invoice: string, kind: string, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'invoice' => '[REDACTED]',
            'kind' => 'proforma',
            'credentials' => '[NOT_PRESENT]',
        ];
    }
}
