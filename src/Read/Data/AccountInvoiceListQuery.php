<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;

final readonly class AccountInvoiceListQuery
{
    public function __construct(public Pagination $pagination = new Pagination) {}

    public function withPage(int $page): self
    {
        return new self($this->pagination->withPage($page));
    }

    /** @return array{page: int, per_page: int, period: string, kinds: list<string>, order: string} */
    public function toQuery(): array
    {
        return [
            'page' => $this->pagination->page,
            'per_page' => $this->pagination->perPage,
            'period' => 'last_month',
            'kinds' => ['accounting_only'],
            'order' => 'number.desc',
        ];
    }
}
