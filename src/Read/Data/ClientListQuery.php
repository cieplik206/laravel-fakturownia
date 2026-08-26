<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use InvalidArgumentException;

final readonly class ClientListQuery
{
    public function __construct(
        public Pagination $pagination = new Pagination,
        public ?string $name = null,
        public ?string $taxNumber = null,
        public ?string $email = null,
        public ?string $externalId = null,
    ) {
        foreach ([$name, $taxNumber, $email, $externalId] as $filter) {
            if ($filter !== null && ($filter === '' || strlen($filter) > 512 || preg_match('//u', $filter) !== 1)) {
                throw new InvalidArgumentException('A client list filter is invalid.');
            }
        }
    }

    public function withPage(int $page): self
    {
        return new self(
            $this->pagination->withPage($page),
            $this->name,
            $this->taxNumber,
            $this->email,
            $this->externalId,
        );
    }

    /** @return array<string, int|string|null> */
    public function toQuery(): array
    {
        return [
            'page' => $this->pagination->page,
            'per_page' => $this->pagination->perPage,
            'name' => $this->name,
            'tax_no' => $this->taxNumber,
            'email' => $this->email,
            'external_id' => $this->externalId,
        ];
    }
}
