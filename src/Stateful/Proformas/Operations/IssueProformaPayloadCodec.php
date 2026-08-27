<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Proformas\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityPolicy;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaDraft;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use InvalidArgumentException;
use JsonException;

final readonly class IssueProformaPayloadCodec implements OperationPayloadCodec
{
    public const int SchemaVersion = 1;

    public const string WriteActivationSlot = 'invoice.proforma.issue';

    private const int MaximumPayloadBytes = 262_144;

    public function __construct(private IssueInvoicePayloadCodec $invoiceCodec = new IssueInvoicePayloadCodec) {}

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(IssueProformaCommand $command): CanonicalObject
    {
        $payload = $this->invoiceCodec->encode(new IssueInvoiceCommand(
            $this->vatDraft($command->draft),
            $this->identityForKind($command->identity, 'vat'),
        ));

        return $this->assertWithinLimit($this->replaceDocumentKind(
            $payload,
            'vat',
            'proforma',
            IssueInvoicePayloadCodec::WriteActivationSlot,
            self::WriteActivationSlot,
        ));
    }

    public function decode(CanonicalObject $payload): IssueProformaCommand
    {
        $this->assertWithinLimit($payload);
        $vatPayload = $this->replaceDocumentKind(
            $payload,
            'proforma',
            'vat',
            self::WriteActivationSlot,
            IssueInvoicePayloadCodec::WriteActivationSlot,
        );
        $decoded = $this->invoiceCodec->decode($vatPayload);
        $draft = $this->proformaDraft($decoded->draft);

        return new IssueProformaCommand(
            $draft,
            $this->identityForKind($decoded->identity, 'proforma'),
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

    private function vatDraft(ProformaDraft $draft): InvoiceDraft
    {
        $proforma = $draft->toInvoiceDraft();

        return new InvoiceDraft(
            kind: 'vat',
            income: true,
            sellDate: $proforma->sellDate,
            issueDate: $proforma->issueDate,
            departmentId: $proforma->departmentId,
            buyer: $proforma->buyer,
            payment: $proforma->payment,
            description: $proforma->description,
            positions: $proforma->positions,
            number: $proforma->number,
        );
    }

    private function proformaDraft(InvoiceDraft $draft): ProformaDraft
    {
        if ($draft->kind !== 'vat'
            || ! $draft->income
            || $draft->payment->status !== 'issued'
            || $draft->payment->paid->minorUnits !== 0
            || $draft->payment->dueKind !== '14'
            || $draft->payment->paidDate !== null
            || $draft->payment->dueDate === null) {
            throw new InvalidArgumentException('Issue proforma payload violates the fixed payment policy.');
        }

        return new ProformaDraft(
            sellDate: $draft->sellDate,
            issueDate: $draft->issueDate,
            departmentId: $draft->departmentId,
            buyer: $draft->buyer,
            paymentType: $draft->payment->type,
            paymentDueDate: $draft->payment->dueDate,
            description: $draft->description,
            positions: $draft->positions,
            number: $draft->number,
        );
    }

    private function identityForKind(RemoteInvoiceIdentity $identity, string $documentKind): RemoteInvoiceIdentity
    {
        $scope = new RemoteIdentityScope(
            $identity->scope->connection,
            $documentKind,
            $identity->scope->departmentId,
        );
        $oid = $identity->oid();
        $transactionOrderReference = $identity->transactionOrderReference();

        return match ($identity->policy) {
            RemoteIdentityPolicy::BusinessOid => $oid !== null
                ? RemoteInvoiceIdentity::businessOid($scope, $oid, OidUniquenessGate::notPassed())
                : throw new InvalidArgumentException('Issue proforma business identity is incomplete.'),
            RemoteIdentityPolicy::TechnicalOidWithTransactionOrder => $oid !== null && $transactionOrderReference !== null
                ? RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
                    $scope,
                    $oid,
                    $transactionOrderReference,
                    OidUniquenessGate::notPassed(),
                )
                : throw new InvalidArgumentException('Issue proforma technical identity is incomplete.'),
            RemoteIdentityPolicy::NoRemoteUniqueness => RemoteInvoiceIdentity::withoutRemoteUniqueness($scope),
        };
    }

    private function replaceDocumentKind(
        CanonicalObject $payload,
        string $expectedKind,
        string $replacementKind,
        string $expectedSlot,
        string $replacementSlot,
    ): CanonicalObject {
        $values = $payload->values;
        $identity = $this->map($values['identity'] ?? null, 'identity');
        $invoice = $this->map($values['invoice'] ?? null, 'invoice');

        if (($values['write_activation_slot'] ?? null) !== $expectedSlot
            || ($identity['document_kind'] ?? null) !== $expectedKind
            || ($invoice['kind'] ?? null) !== $expectedKind) {
            throw new InvalidArgumentException('Issue proforma payload document kind or activation slot is invalid.');
        }

        $values['write_activation_slot'] = $replacementSlot;
        $identity['document_kind'] = $replacementKind;
        $invoice['kind'] = $replacementKind;
        $values['identity'] = $identity;
        $values['invoice'] = $invoice;

        return new CanonicalObject($values);
    }

    /** @return array<string, mixed> */
    private function map(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("Issue proforma payload {$path} must be an object.");
        }

        return $value;
    }

    private function assertWithinLimit(CanonicalObject $payload): CanonicalObject
    {
        try {
            $canonical = (new CanonicalJsonV1)->encode($payload);
        } catch (JsonException) {
            throw new InvalidArgumentException('Issue proforma payload cannot be canonicalized.');
        }

        if (strlen($canonical) > self::MaximumPayloadBytes) {
            throw new InvalidArgumentException('Issue proforma payload exceeds the plaintext byte limit.');
        }

        return $payload;
    }
}
