<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;

final readonly class IssueInvoiceResult implements OperationResult
{
    use RejectsNativeSerialization;

    /** @var non-empty-list<InvoiceLine> */
    public array $positions;

    /** @param array<mixed> $positions */
    public function __construct(
        public string $remoteId,
        public string $number,
        public string $kind,
        public string $status,
        public string $issueDate,
        public ?string $buyerTaxNumber,
        public Money $totalGross,
        public ?string $oid,
        array $positions,
    ) {
        $validated = new IssuedInvoiceResult(
            remoteId: $remoteId,
            number: $number,
            kind: $kind,
            status: $status,
            issueDate: $issueDate,
            buyerTaxNumber: $buyerTaxNumber,
            totalGross: $totalGross,
            oid: $oid,
            positions: $positions,
        );

        /** @var non-empty-list<InvoiceLine> $validatedPositions */
        $validatedPositions = $validated->positions;
        $this->positions = $validatedPositions;
    }

    public static function fromIssuedInvoiceResult(IssuedInvoiceResult $invoice): self
    {
        return new self(
            remoteId: $invoice->remoteId,
            number: $invoice->number,
            kind: $invoice->kind,
            status: $invoice->status,
            issueDate: $invoice->issueDate,
            buyerTaxNumber: $invoice->buyerTaxNumber,
            totalGross: $invoice->totalGross,
            oid: $invoice->oid,
            positions: $invoice->positions,
        );
    }

    public function resultType(): string
    {
        return IssueInvoiceResultCodec::resultType();
    }

    public function toIssuedInvoiceResult(): IssuedInvoiceResult
    {
        return new IssuedInvoiceResult(
            remoteId: $this->remoteId,
            number: $this->number,
            kind: $this->kind,
            status: $this->status,
            issueDate: $this->issueDate,
            buyerTaxNumber: $this->buyerTaxNumber,
            totalGross: $this->totalGross,
            oid: $this->oid,
            positions: $this->positions,
        );
    }
}
