<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use InvalidArgumentException;

final readonly class ExactOidInvoiceQuery
{
    public ApiDate $issueDate;

    public function __construct(
        public string $oid,
        public string $kind,
        public bool $income,
        string $issueDate,
        public Pagination $pagination = new Pagination,
    ) {
        if (trim($oid) === ''
            || strlen($oid) > 256
            || preg_match('//u', $oid) !== 1
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $oid) === 1) {
            throw new InvalidArgumentException('The exact invoice OID must be a bounded printable value.');
        }

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $kind) !== 1) {
            throw new InvalidArgumentException('The exact invoice kind is invalid.');
        }

        $this->issueDate = new ApiDate($issueDate);
    }

    public function withPage(int $page): self
    {
        return new self(
            $this->oid,
            $this->kind,
            $this->income,
            $this->issueDate->value,
            $this->pagination->withPage($page),
        );
    }

    /** @return array<string, int|string> */
    public function toQuery(): array
    {
        return [
            'page' => $this->pagination->page,
            'per_page' => $this->pagination->perPage,
            'oid' => $this->oid,
            'kind' => $this->kind,
            'income' => $this->income ? 'yes' : 'no',
            'include_positions' => 'true',
            'period' => 'more',
            'date_from' => $this->issueDate->value,
            'date_to' => $this->issueDate->value,
            'search_date_type' => 'issue_date',
            'order' => 'id.asc',
        ];
    }
}
