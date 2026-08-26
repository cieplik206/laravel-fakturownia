<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use InvalidArgumentException;

final readonly class InvoiceListQuery
{
    /** @var list<string> */
    public array $kinds;

    /** @param list<string> $kinds */
    public function __construct(
        public Pagination $pagination = new Pagination,
        public ?string $period = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $clientId = null,
        public ?string $number = null,
        public ?string $kind = null,
        array $kinds = [],
        public ?bool $income = null,
        public bool $includePositions = false,
        public string $order = 'id.asc',
        public ?string $searchDateType = null,
        public ?string $warehouseId = null,
        public ?string $fromInvoiceId = null,
    ) {
        foreach ([$dateFrom, $dateTo] as $date) {
            if ($date === null) {
                continue;
            }

            try {
                new ApiDate($date);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('Invoice date filters must use a valid YYYY-MM-DD date.');
            }
        }

        if ($clientId !== null) {
            RemoteIdentifier::assert($clientId);
        }

        if ($warehouseId !== null) {
            RemoteIdentifier::assert($warehouseId);
        }

        if ($fromInvoiceId !== null) {
            RemoteIdentifier::assert($fromInvoiceId);
        }

        if ($number !== null && ($number === '' || strlen($number) > 200 || preg_match('//u', $number) !== 1)) {
            throw new InvalidArgumentException('The invoice number filter is invalid.');
        }

        foreach ([$period, $kind, $searchDateType] as $filter) {
            if ($filter !== null && preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $filter) !== 1) {
                throw new InvalidArgumentException('An invoice list filter is invalid.');
            }
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,63}\.(?:asc|desc)$/', $order) !== 1) {
            throw new InvalidArgumentException('The invoice order must be a stable field.asc or field.desc value.');
        }

        if ($kind !== null && $kinds !== []) {
            throw new InvalidArgumentException('Use either kind or kinds, not both.');
        }

        $normalizedKinds = [];

        foreach ($kinds as $listedKind) {
            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $listedKind) !== 1) {
                throw new InvalidArgumentException('An invoice kind filter is invalid.');
            }

            $normalizedKinds[] = $listedKind;
        }

        $this->kinds = array_values(array_unique($normalizedKinds));
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
            kind: $this->kind,
            kinds: $this->kinds,
            income: $this->income,
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
        $period = $this->period;

        if ($period === null && ($this->dateFrom !== null || $this->dateTo !== null)) {
            $period = 'more';
        }

        return [
            'page' => $this->pagination->page,
            'per_page' => $this->pagination->perPage,
            'period' => $period,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'client_id' => $this->clientId,
            'number' => $this->number,
            'kind' => $this->kind,
            'kinds' => $this->kinds === [] ? null : $this->kinds,
            'income' => $this->income === null ? null : ($this->income ? 'yes' : 'no'),
            'include_positions' => $this->includePositions ? 'true' : null,
            'order' => $this->order,
            'search_date_type' => $this->searchDateType,
            'warehouse_id' => $this->warehouseId,
            'from_invoice_id' => $this->fromInvoiceId,
        ];
    }
}
