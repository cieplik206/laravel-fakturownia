<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;

final readonly class ProformaListQuery
{
    public function __construct(
        public Pagination $pagination = new Pagination,
        public ?string $period = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $clientId = null,
        public ?string $number = null,
        public bool $includePositions = false,
        public string $order = 'id.asc',
        public ?string $searchDateType = null,
        public ?string $warehouseId = null,
        public ?string $fromInvoiceId = null,
    ) {
        $this->invoiceQuery();
    }

    public function withPage(int $page): self
    {
        return new self(
            pagination: $this->pagination->withPage($page),
            period: $this->period,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            clientId: $this->clientId,
            number: $this->number,
            includePositions: $this->includePositions,
            order: $this->order,
            searchDateType: $this->searchDateType,
            warehouseId: $this->warehouseId,
            fromInvoiceId: $this->fromInvoiceId,
        );
    }

    /** @return array<string, bool|int|string|list<string>|null> */
    public function toQuery(): array
    {
        return $this->invoiceQuery()->toQuery();
    }

    private function invoiceQuery(): InvoiceListQuery
    {
        return new InvoiceListQuery(
            pagination: $this->pagination,
            period: $this->period,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            clientId: $this->clientId,
            number: $this->number,
            kind: 'proforma',
            income: true,
            includePositions: $this->includePositions,
            order: $this->order,
            searchDateType: $this->searchDateType,
            warehouseId: $this->warehouseId,
            fromInvoiceId: $this->fromInvoiceId,
        );
    }
}
