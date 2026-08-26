<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\Support\PayloadReader;

final readonly class ClientResponseData
{
    /** @var array<string, mixed> */
    private array $extraFields;

    /** @param array<string, mixed> $extraFields */
    private function __construct(
        public string $remoteId,
        public ?string $name,
        public ?string $taxNumber,
        public ?string $email,
        public ?string $phone,
        public ?string $street,
        public ?string $city,
        public ?string $postCode,
        public ?string $country,
        public ?string $externalId,
        public ?bool $company,
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
            taxNumber: $reader->nullableString('tax_no'),
            email: $reader->nullableString('email'),
            phone: $reader->nullableString('phone'),
            street: $reader->nullableString('street'),
            city: $reader->nullableString('city'),
            postCode: $reader->nullableString('post_code'),
            country: $reader->nullableString('country'),
            externalId: $reader->nullableString('external_id'),
            company: $reader->nullableBoolean('company'),
            createdAt: $reader->nullableTimestamp('created_at'),
            updatedAt: $reader->nullableTimestamp('updated_at'),
            extraFields: $reader->extra([
                'id', 'name', 'tax_no', 'email', 'phone', 'street', 'city', 'post_code', 'country', 'external_id',
                'company', 'created_at', 'updated_at',
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function extra(): array
    {
        return $this->extraFields;
    }
}
