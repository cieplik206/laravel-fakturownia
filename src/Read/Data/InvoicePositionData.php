<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\Support\PayloadReader;

final readonly class InvoicePositionData
{
    /** @var array<string, mixed> */
    private array $extraFields;

    /** @param array<string, mixed> $extraFields */
    private function __construct(
        public ?string $remoteId,
        public ?string $invoiceId,
        public ?string $productId,
        public ?string $name,
        public ?string $code,
        public ?string $description,
        public ?DecimalValue $quantity,
        public ?string $unit,
        public ?string $tax,
        public ?DecimalValue $priceNet,
        public ?DecimalValue $priceGross,
        public ?DecimalValue $totalPriceNet,
        public ?DecimalValue $totalPriceGross,
        array $extraFields,
    ) {
        $this->extraFields = $extraFields;
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload, string $operation): self
    {
        $reader = new PayloadReader($payload, $operation);

        return new self(
            remoteId: $reader->nullableId('id'),
            invoiceId: $reader->nullableId('invoice_id'),
            productId: $reader->nullableId('product_id'),
            name: $reader->nullableString('name'),
            code: $reader->nullableString('code'),
            description: $reader->nullableString('description'),
            quantity: $reader->nullableExactDecimal('quantity'),
            unit: $reader->nullableString('quantity_unit') ?? $reader->nullableString('unit'),
            tax: $reader->nullableExactScalarString('tax'),
            priceNet: $reader->nullableExactDecimal('price_net'),
            priceGross: $reader->nullableExactDecimal('price_gross'),
            totalPriceNet: $reader->nullableExactDecimal('total_price_net'),
            totalPriceGross: $reader->nullableExactDecimal('total_price_gross'),
            extraFields: $reader->extra([
                'id',
                'invoice_id',
                'product_id',
                'name',
                'code',
                'description',
                'quantity',
                'quantity_unit',
                'unit',
                'tax',
                'price_net',
                'price_gross',
                'total_price_net',
                'total_price_gross',
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function extra(): array
    {
        return $this->extraFields;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            ...$this->extraFields,
            'id' => $this->remoteId,
            'invoice_id' => $this->invoiceId,
            'product_id' => $this->productId,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'quantity' => $this->quantity?->value,
            'quantity_unit' => $this->unit,
            'tax' => $this->tax,
            'price_net' => $this->priceNet?->value,
            'price_gross' => $this->priceGross?->value,
            'total_price_net' => $this->totalPriceNet?->value,
            'total_price_gross' => $this->totalPriceGross?->value,
        ];
    }
}
