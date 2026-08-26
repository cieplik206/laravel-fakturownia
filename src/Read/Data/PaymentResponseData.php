<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\Support\PayloadReader;

final readonly class PaymentResponseData
{
    /** @var list<InvoiceResponseData> */
    public array $invoices;

    /** @var array<string, mixed> */
    private array $extraFields;

    /**
     * @param  list<InvoiceResponseData>  $invoices
     * @param  array<string, mixed>  $extraFields
     */
    private function __construct(
        public string $remoteId,
        public ?string $name,
        public ?DecimalValue $price,
        public ?string $currency,
        public ?bool $paid,
        public ?string $kind,
        public ?string $invoiceId,
        public ?string $clientId,
        public ?string $description,
        public ApiDate|ApiTimestamp|null $paidAt,
        public ?ApiTimestamp $createdAt,
        public ?ApiTimestamp $updatedAt,
        array $invoices,
        array $extraFields,
    ) {
        $this->invoices = $invoices;
        $this->extraFields = $extraFields;
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload, string $operation): self
    {
        $reader = new PayloadReader($payload, $operation);
        $invoices = array_map(
            static fn (array $invoice): InvoiceResponseData => InvoiceResponseData::fromPayload($invoice, $operation),
            $reader->objectList('invoices'),
        );

        return new self(
            remoteId: $reader->requiredId('id'),
            name: $reader->nullableString('name'),
            price: $reader->nullableDecimal('price'),
            currency: $reader->nullableString('currency'),
            paid: $reader->nullableBoolean('paid'),
            kind: $reader->nullableString('kind'),
            invoiceId: $reader->nullableId('invoice_id'),
            clientId: $reader->nullableId('client_id'),
            description: $reader->nullableString('description'),
            paidAt: $reader->nullableDateOrTimestamp('paid_date'),
            createdAt: $reader->nullableTimestamp('created_at'),
            updatedAt: $reader->nullableTimestamp('updated_at'),
            invoices: $invoices,
            extraFields: $reader->extra([
                'id', 'name', 'price', 'currency', 'paid', 'kind', 'invoice_id', 'client_id', 'description', 'paid_date',
                'created_at', 'updated_at', 'invoices',
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function extra(): array
    {
        return $this->extraFields;
    }
}
