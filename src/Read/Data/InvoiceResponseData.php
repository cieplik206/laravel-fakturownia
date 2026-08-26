<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Support\PayloadReader;
use InvalidArgumentException;

final readonly class InvoiceResponseData
{
    /** @var list<InvoicePositionData> */
    public array $positions;

    /** @var array<string, mixed> */
    private array $extraFields;

    /**
     * @param  list<InvoicePositionData>  $positions
     * @param  array<string, mixed>  $extraFields
     */
    private function __construct(
        public string $remoteId,
        public ?string $userId,
        public ?string $number,
        public ?OpenInvoiceKind $kind,
        public ?OpenInvoiceStatus $status,
        public ?ApiDate $issueDate,
        public ApiDate|ApiMonth|null $sellDate,
        public ?ApiDate $paymentTo,
        public ?ApiDate $paidDate,
        public ?string $paymentType,
        public ?DecimalValue $priceNet,
        public ?DecimalValue $priceTax,
        public ?DecimalValue $priceGross,
        public ?DecimalValue $paid,
        public ?string $currency,
        public ?string $description,
        public ?string $sellerName,
        public ?string $sellerTaxNumber,
        public ?string $buyerName,
        public ?string $buyerTaxNumber,
        public ?string $buyerEmail,
        public ?string $clientId,
        public ?string $departmentId,
        public ?string $sourceOid,
        public ?string $fromInvoiceId,
        public ?bool $income,
        public ?bool $cancelled,
        public ?string $governmentId,
        public ?string $governmentStatus,
        public ?ApiTimestamp $createdAt,
        public ?ApiTimestamp $updatedAt,
        array $positions,
        array $extraFields,
    ) {
        $this->positions = $positions;
        $this->extraFields = $extraFields;
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload, string $operation): self
    {
        $reader = new PayloadReader($payload, $operation);
        $kind = self::kind($reader->nullableString('kind'), $operation);
        $status = self::status($reader->nullableString('status'), $operation);
        $positions = array_map(
            static fn (array $position): InvoicePositionData => InvoicePositionData::fromPayload($position, $operation),
            $reader->objectList('positions'),
        );

        return new self(
            remoteId: $reader->requiredId('id'),
            userId: $reader->nullableId('user_id'),
            number: $reader->nullableString('number', false),
            kind: $kind,
            status: $status,
            issueDate: $reader->nullableDate('issue_date'),
            sellDate: $reader->nullableDateOrMonth('sell_date'),
            paymentTo: $reader->nullableDate('payment_to'),
            paidDate: $reader->nullableDate('paid_date'),
            paymentType: $reader->nullableString('payment_type'),
            priceNet: $reader->nullableExactDecimal('price_net'),
            priceTax: $reader->nullableExactDecimal('price_tax'),
            priceGross: $reader->nullableExactDecimal('price_gross'),
            paid: $reader->nullableExactDecimal('paid'),
            currency: $reader->nullableString('currency'),
            description: $reader->nullableString('description'),
            sellerName: $reader->nullableString('seller_name'),
            sellerTaxNumber: $reader->nullableString('seller_tax_no'),
            buyerName: $reader->nullableString('buyer_name'),
            buyerTaxNumber: $reader->nullableString('buyer_tax_no'),
            buyerEmail: $reader->nullableString('buyer_email'),
            clientId: $reader->nullableId('client_id'),
            departmentId: $reader->nullableId('department_id'),
            sourceOid: $reader->nullableString('oid'),
            fromInvoiceId: $reader->nullableId('from_invoice_id'),
            income: $reader->nullableBoolean('income'),
            cancelled: $reader->nullableBoolean('cancelled'),
            governmentId: $reader->nullableString('gov_id'),
            governmentStatus: $reader->nullableString('gov_status'),
            createdAt: $reader->nullableTimestamp('created_at'),
            updatedAt: $reader->nullableTimestamp('updated_at'),
            positions: $positions,
            extraFields: $reader->extra([
                'id',
                'user_id',
                'number',
                'kind',
                'status',
                'issue_date',
                'sell_date',
                'payment_to',
                'paid_date',
                'payment_type',
                'price_net',
                'price_tax',
                'price_gross',
                'paid',
                'currency',
                'description',
                'seller_name',
                'seller_tax_no',
                'buyer_name',
                'buyer_tax_no',
                'buyer_email',
                'client_id',
                'department_id',
                'oid',
                'from_invoice_id',
                'income',
                'cancelled',
                'gov_id',
                'gov_status',
                'created_at',
                'updated_at',
                'positions',
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function extra(): array
    {
        return $this->extraFields;
    }

    private static function kind(?string $value, string $operation): ?OpenInvoiceKind
    {
        if ($value === null) {
            return null;
        }

        try {
            return new OpenInvoiceKind($value);
        } catch (InvalidArgumentException) {
            throw new ProtocolViolation($operation, 'invoice kind field');
        }
    }

    private static function status(?string $value, string $operation): ?OpenInvoiceStatus
    {
        if ($value === null) {
            return null;
        }

        try {
            return new OpenInvoiceStatus($value);
        } catch (InvalidArgumentException) {
            throw new ProtocolViolation($operation, 'invoice status field');
        }
    }
}
