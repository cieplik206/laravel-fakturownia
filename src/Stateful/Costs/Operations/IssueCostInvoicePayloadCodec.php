<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoicePayloadCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use InvalidArgumentException;
use JsonException;

final readonly class IssueCostInvoicePayloadCodec implements OperationPayloadCodec
{
    public const int SchemaVersion = 1;

    public const string WriteActivationSlot = 'invoice.cost.issue';

    private const int MaximumPayloadBytes = 262_144;

    public function __construct(private IssueInvoicePayloadCodec $invoiceCodec = new IssueInvoicePayloadCodec) {}

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(IssueCostInvoiceCommand $command): CanonicalObject
    {
        $payload = $this->invoiceCodec->encode(new IssueInvoiceCommand(
            $this->incomeDraft($command->draft),
            $command->identity,
        ));

        return $this->assertWithinLimit($this->replaceIncomeAndSlot(
            $payload,
            true,
            false,
            IssueInvoicePayloadCodec::WriteActivationSlot,
            self::WriteActivationSlot,
        ));
    }

    public function decode(CanonicalObject $payload): IssueCostInvoiceCommand
    {
        $this->assertWithinLimit($payload);
        $incomePayload = $this->replaceIncomeAndSlot(
            $payload,
            false,
            true,
            self::WriteActivationSlot,
            IssueInvoicePayloadCodec::WriteActivationSlot,
        );
        $decoded = $this->invoiceCodec->decode($incomePayload);

        return new IssueCostInvoiceCommand(
            $this->costDraft($decoded->draft),
            $decoded->identity,
        );
    }

    public function canonicalize(CanonicalObject $payload): CanonicalObject
    {
        return $this->encode($this->decode($payload));
    }

    public function writeActivationSlot(CanonicalObject $payload): string
    {
        $this->decode($payload);

        return self::WriteActivationSlot;
    }

    private function incomeDraft(InvoiceDraft $draft): InvoiceDraft
    {
        return $this->withIncome($draft, true);
    }

    private function costDraft(InvoiceDraft $draft): InvoiceDraft
    {
        return $this->withIncome($draft, false);
    }

    private function withIncome(InvoiceDraft $draft, bool $income): InvoiceDraft
    {
        return new InvoiceDraft(
            kind: $draft->kind,
            income: $income,
            sellDate: $draft->sellDate,
            issueDate: $draft->issueDate,
            departmentId: $draft->departmentId,
            buyer: $draft->buyer,
            payment: $draft->payment,
            description: $draft->description,
            positions: $draft->positions,
            number: $draft->number,
        );
    }

    private function replaceIncomeAndSlot(
        CanonicalObject $payload,
        bool $expectedIncome,
        bool $replacementIncome,
        string $expectedSlot,
        string $replacementSlot,
    ): CanonicalObject {
        $values = $payload->values;
        $invoice = $this->map($values['invoice'] ?? null);

        if (($values['write_activation_slot'] ?? null) !== $expectedSlot
            || ($invoice['kind'] ?? null) !== 'vat'
            || ($invoice['income'] ?? null) !== $expectedIncome) {
            throw new InvalidArgumentException('Issue cost invoice payload income or activation slot is invalid.');
        }

        $values['write_activation_slot'] = $replacementSlot;
        $invoice['income'] = $replacementIncome;
        $values['invoice'] = $invoice;

        return new CanonicalObject($values);
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Issue cost invoice payload invoice must be an object.');
        }

        return $value;
    }

    private function assertWithinLimit(CanonicalObject $payload): CanonicalObject
    {
        try {
            $canonical = (new CanonicalJsonV1)->encode($payload);
        } catch (JsonException) {
            throw new InvalidArgumentException('Issue cost invoice payload cannot be canonicalized.');
        }

        if (strlen($canonical) > self::MaximumPayloadBytes) {
            throw new InvalidArgumentException('Issue cost invoice payload exceeds the plaintext byte limit.');
        }

        return $payload;
    }
}
