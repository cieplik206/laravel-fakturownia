<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;

final readonly class PaymentListQuery
{
    public function __construct(
        public Pagination $pagination = new Pagination,
        public bool $includeInvoices = false,
    ) {}

    public function withPage(int $page): self
    {
        return new self($this->pagination->withPage($page), $this->includeInvoices);
    }

    /** @return array{page: int, per_page: int, include: string|null} */
    public function toQuery(): array
    {
        return [
            'page' => $this->pagination->page,
            'per_page' => $this->pagination->perPage,
            'include' => $this->includeInvoices ? 'invoices' : null,
        ];
    }
}
