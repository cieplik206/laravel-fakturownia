<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Proformas;

use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraftValidator;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoicePayment;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceValidationProfile;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProformaDraft
{
    use RejectsNativeSerialization;

    private const int MaximumPositions = 1000;

    public InvoiceBuyer $buyer;

    public InvoicePayment $payment;

    /** @var non-empty-list<InvoiceLine> */
    public array $positions;

    /** @param array<mixed> $positions */
    public function __construct(
        public string $sellDate,
        public string $issueDate,
        public string $departmentId,
        InvoiceBuyer $buyer,
        public string $paymentType,
        public string $paymentDueDate,
        public string $description,
        array $positions,
        public string $number = '',
    ) {
        $validatedBuyer = self::copyBuyer($buyer);
        $validatedPositions = self::copyPositions($positions);
        $firstPosition = $validatedPositions[0];
        $validatedPayment = new InvoicePayment(
            type: $paymentType,
            status: 'issued',
            paid: new Money(
                0,
                $firstPosition->totalGross->currency,
                $firstPosition->totalGross->fractionDigits,
            ),
            dueKind: '14',
            paidDate: null,
            dueDate: $paymentDueDate,
        );

        if (! self::validDate($paymentDueDate)) {
            throw new InvalidArgumentException('The proforma payment due date must use the YYYY-MM-DD format.');
        }

        $invoice = new InvoiceDraft(
            kind: 'proforma',
            income: true,
            sellDate: $sellDate,
            issueDate: $issueDate,
            departmentId: $departmentId,
            buyer: $validatedBuyer,
            payment: $validatedPayment,
            description: $description,
            positions: $validatedPositions,
            number: $number,
        );

        (new InvoiceDraftValidator)
            ->validate($invoice, InvoiceValidationProfile::Standard)
            ->throwIfInvalid();

        $this->buyer = $validatedBuyer;
        $this->payment = $validatedPayment;
        $this->positions = $validatedPositions;
    }

    /** @return array{kind: string, buyer: string, positions: int, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'kind' => 'proforma',
            'buyer' => '[REDACTED]',
            'positions' => count($this->positions),
            'credentials' => '[NOT_PRESENT]',
        ];
    }

    public function toInvoiceDraft(): InvoiceDraft
    {
        return new InvoiceDraft(
            kind: 'proforma',
            income: true,
            sellDate: $this->sellDate,
            issueDate: $this->issueDate,
            departmentId: $this->departmentId,
            buyer: $this->buyer,
            payment: $this->payment,
            description: $this->description,
            positions: $this->positions,
            number: $this->number,
        );
    }

    private static function copyBuyer(InvoiceBuyer $buyer): InvoiceBuyer
    {
        return new InvoiceBuyer(
            company: $buyer->company,
            name: $buyer->name,
            taxNumber: $buyer->taxNumber,
            postCode: $buyer->postCode,
            city: $buyer->city,
            street: $buyer->street,
            country: $buyer->country,
            email: $buyer->email,
            lastName: $buyer->lastName,
            firstName: $buyer->firstName,
            taxNumberKind: $buyer->taxNumberKind,
        );
    }

    /**
     * @param  array<mixed>  $positions
     * @return non-empty-list<InvoiceLine>
     */
    private static function copyPositions(array $positions): array
    {
        if ($positions === [] || count($positions) > self::MaximumPositions) {
            throw new InvalidArgumentException('A proforma must contain a bounded non-empty position list.');
        }

        $validated = [];

        foreach ($positions as $position) {
            if (! $position instanceof InvoiceLine) {
                throw new InvalidArgumentException('Proforma positions must contain only invoice lines.');
            }

            $validated[] = new InvoiceLine(
                name: $position->name,
                tax: $position->tax,
                totalGross: new Money(
                    $position->totalGross->minorUnits,
                    $position->totalGross->currency,
                    $position->totalGross->fractionDigits,
                ),
                quantity: $position->quantity,
                unit: $position->unit,
            );
        }

        return $validated;
    }

    private static function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
