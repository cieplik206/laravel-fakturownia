<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\Support\PayloadReader;

final readonly class ProductResponseData
{
    /** @var array<string, mixed> */
    private array $extraFields;

    /** @param array<string, mixed> $extraFields */
    private function __construct(
        public string $remoteId,
        public ?string $name,
        public ?string $code,
        public ?string $description,
        public ?DecimalValue $priceNet,
        public ?DecimalValue $priceGross,
        public ?string $tax,
        public ?string $currency,
        public ?DecimalValue $quantity,
        public ?ApiTimestamp $createdAt,
        public ?ApiTimestamp $updatedAt,
        array $extraFields,
    ) {
        $this->extraFields = $extraFields;
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload, string $operation): self
    {
        $reader = new PayloadReader($payload, $operation);

        return new self(
            remoteId: $reader->requiredId('id'),
            name: $reader->nullableString('name'),
            code: $reader->nullableString('code'),
            description: $reader->nullableString('description'),
            priceNet: $reader->nullableDecimal('price_net'),
            priceGross: $reader->nullableDecimal('price_gross'),
            tax: $reader->nullableScalarString('tax'),
            currency: $reader->nullableString('currency'),
            quantity: $reader->nullableDecimal('quantity'),
            createdAt: $reader->nullableTimestamp('created_at'),
            updatedAt: $reader->nullableTimestamp('updated_at'),
            extraFields: $reader->extra([
                'id', 'name', 'code', 'description', 'price_net', 'price_gross', 'tax', 'currency', 'quantity',
                'created_at', 'updated_at',
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function extra(): array
    {
        return $this->extraFields;
    }
}
