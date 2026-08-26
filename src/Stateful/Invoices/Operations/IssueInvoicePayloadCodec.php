<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityPolicy;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoicePayment;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;
use JsonException;

final readonly class IssueInvoicePayloadCodec implements OperationPayloadCodec
{
    public const int SchemaVersion = 1;

    public const string WriteActivationSlot = 'invoice.vat.issue';

    private const int MaximumPayloadBytes = 262_144;

    /** @var list<string> */
    private const array PayloadKeys = [
        'schema_version',
        'write_activation_slot',
        'identity',
        'invoice',
    ];

    /** @var list<string> */
    private const array IdentityKeys = [
        'connection_key',
        'document_kind',
        'department_id',
        'policy',
        'oid',
        'transaction_order_reference',
    ];

    /** @var list<string> */
    private const array InvoiceKeys = [
        'kind',
        'income',
        'sell_date',
        'issue_date',
        'department_id',
        'buyer',
        'payment',
        'description',
        'positions',
        'number',
    ];

    /** @var list<string> */
    private const array BuyerKeys = [
        'company',
        'name',
        'tax_number',
        'post_code',
        'city',
        'street',
        'country',
        'email',
        'last_name',
        'first_name',
        'tax_number_kind',
    ];

    /** @var list<string> */
    private const array PaymentKeys = [
        'type',
        'status',
        'paid',
        'due_kind',
        'paid_date',
        'due_date',
    ];

    /** @var list<string> */
    private const array PositionKeys = ['name', 'tax', 'total_gross', 'quantity', 'unit'];

    /** @var list<string> */
    private const array MoneyKeys = ['minor_units', 'currency', 'fraction_digits'];

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(IssueInvoiceCommand $command): CanonicalObject
    {
        $payload = new CanonicalObject([
            'schema_version' => self::schemaVersion(),
            'write_activation_slot' => self::WriteActivationSlot,
            'identity' => $this->encodeIdentity($command->identity),
            'invoice' => $this->encodeInvoice($command->draft),
        ]);

        $this->assertWithinLimit($payload);

        return $payload;
    }

    public function decode(CanonicalObject $payload): IssueInvoiceCommand
    {
        $this->assertWithinLimit($payload);
        $this->assertExactKeys($payload->values, self::PayloadKeys, 'payload');

        if ($payload->values['schema_version'] !== self::schemaVersion()) {
            throw new InvalidArgumentException('Issue invoice payload uses an unsupported schema.');
        }

        if ($payload->values['write_activation_slot'] !== self::WriteActivationSlot) {
            throw new InvalidArgumentException('Issue invoice payload uses an unsupported write activation slot.');
        }

        $draft = $this->decodeInvoice($payload->values['invoice']);
        $identity = $this->decodeIdentity($payload->values['identity']);

        return new IssueInvoiceCommand($draft, $identity);
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

    /** @return array<string, mixed> */
    private function encodeIdentity(RemoteInvoiceIdentity $identity): array
    {
        return [
            'connection_key' => $identity->scope->connection->value,
            'document_kind' => $identity->scope->documentKind,
            'department_id' => $identity->scope->departmentId,
            'policy' => $identity->policy->value,
            'oid' => $identity->oid(),
            'transaction_order_reference' => $identity->transactionOrderReference(),
        ];
    }

    /** @return array<string, mixed> */
    private function encodeInvoice(InvoiceDraft $draft): array
    {
        return [
            'kind' => $draft->kind,
            'income' => $draft->income,
            'sell_date' => $draft->sellDate,
            'issue_date' => $draft->issueDate,
            'department_id' => $draft->departmentId,
            'buyer' => [
                'company' => $draft->buyer->company,
                'name' => $draft->buyer->name,
                'tax_number' => $draft->buyer->taxNumber,
                'post_code' => $draft->buyer->postCode,
                'city' => $draft->buyer->city,
                'street' => $draft->buyer->street,
                'country' => $draft->buyer->country,
                'email' => $draft->buyer->email,
                'last_name' => $draft->buyer->lastName,
                'first_name' => $draft->buyer->firstName,
                'tax_number_kind' => $draft->buyer->taxNumberKind,
            ],
            'payment' => [
                'type' => $draft->payment->type,
                'status' => $draft->payment->status,
                'paid' => $this->encodeMoney($draft->payment->paid),
                'due_kind' => $draft->payment->dueKind,
                'paid_date' => $draft->payment->paidDate,
                'due_date' => $draft->payment->dueDate,
            ],
            'description' => $draft->description,
            'positions' => array_map(
                fn (InvoiceLine $position): array => [
                    'name' => $position->name,
                    'tax' => $position->tax,
                    'total_gross' => $this->encodeMoney($position->totalGross),
                    'quantity' => $position->quantity,
                    'unit' => $position->unit,
                ],
                $draft->positions,
            ),
            'number' => $draft->number,
        ];
    }

    /** @return array{minor_units: int, currency: string, fraction_digits: int} */
    private function encodeMoney(Money $money): array
    {
        return [
            'minor_units' => $money->minorUnits,
            'currency' => $money->currency,
            'fraction_digits' => $money->fractionDigits,
        ];
    }

    private function decodeIdentity(mixed $payload): RemoteInvoiceIdentity
    {
        $identity = $this->object($payload, 'identity');
        $this->assertExactKeys($identity, self::IdentityKeys, 'identity');

        $scope = new RemoteIdentityScope(
            new ConnectionKey($this->string($identity['connection_key'], 'identity.connection_key')),
            $this->string($identity['document_kind'], 'identity.document_kind'),
            $this->string($identity['department_id'], 'identity.department_id'),
        );
        $policyValue = $this->string($identity['policy'], 'identity.policy');
        $policy = RemoteIdentityPolicy::tryFrom($policyValue);
        $oid = $this->nullableString($identity['oid'], 'identity.oid');
        $transactionOrderReference = $this->nullableString(
            $identity['transaction_order_reference'],
            'identity.transaction_order_reference',
        );

        return match ($policy) {
            RemoteIdentityPolicy::BusinessOid => $this->businessIdentity(
                $scope,
                $oid,
                $transactionOrderReference,
            ),
            RemoteIdentityPolicy::TechnicalOidWithTransactionOrder => $this->technicalIdentity(
                $scope,
                $oid,
                $transactionOrderReference,
            ),
            RemoteIdentityPolicy::NoRemoteUniqueness => $this->identityWithoutRemoteUniqueness(
                $scope,
                $oid,
                $transactionOrderReference,
            ),
            null => throw new InvalidArgumentException('Issue invoice payload identity policy is unsupported.'),
        };
    }

    private function decodeInvoice(mixed $payload): InvoiceDraft
    {
        $invoice = $this->object($payload, 'invoice');
        $this->assertExactKeys($invoice, self::InvoiceKeys, 'invoice');

        $positionsPayload = $invoice['positions'];
        if (! is_array($positionsPayload) || ! array_is_list($positionsPayload)) {
            throw new InvalidArgumentException('Issue invoice payload positions must be a list.');
        }

        $positions = [];
        foreach ($positionsPayload as $index => $positionPayload) {
            $position = $this->object($positionPayload, "invoice.positions.{$index}");
            $this->assertExactKeys($position, self::PositionKeys, "invoice.positions.{$index}");
            $positions[] = new InvoiceLine(
                name: $this->string($position['name'], "invoice.positions.{$index}.name"),
                tax: $this->string($position['tax'], "invoice.positions.{$index}.tax"),
                totalGross: $this->decodeMoney(
                    $position['total_gross'],
                    "invoice.positions.{$index}.total_gross",
                ),
                quantity: $this->string($position['quantity'], "invoice.positions.{$index}.quantity"),
                unit: $this->string($position['unit'], "invoice.positions.{$index}.unit"),
            );
        }

        return new InvoiceDraft(
            kind: $this->string($invoice['kind'], 'invoice.kind'),
            income: $this->boolean($invoice['income'], 'invoice.income'),
            sellDate: $this->string($invoice['sell_date'], 'invoice.sell_date'),
            issueDate: $this->string($invoice['issue_date'], 'invoice.issue_date'),
            departmentId: $this->string($invoice['department_id'], 'invoice.department_id'),
            buyer: $this->decodeBuyer($invoice['buyer']),
            payment: $this->decodePayment($invoice['payment']),
            description: $this->string($invoice['description'], 'invoice.description'),
            positions: $positions,
            number: $this->string($invoice['number'], 'invoice.number'),
        );
    }

    private function decodeBuyer(mixed $payload): InvoiceBuyer
    {
        $buyer = $this->object($payload, 'invoice.buyer');
        $this->assertExactKeys($buyer, self::BuyerKeys, 'invoice.buyer');

        return new InvoiceBuyer(
            company: $this->boolean($buyer['company'], 'invoice.buyer.company'),
            name: $this->string($buyer['name'], 'invoice.buyer.name'),
            taxNumber: $this->nullableString($buyer['tax_number'], 'invoice.buyer.tax_number'),
            postCode: $this->string($buyer['post_code'], 'invoice.buyer.post_code'),
            city: $this->string($buyer['city'], 'invoice.buyer.city'),
            street: $this->string($buyer['street'], 'invoice.buyer.street'),
            country: $this->string($buyer['country'], 'invoice.buyer.country'),
            email: $this->string($buyer['email'], 'invoice.buyer.email'),
            lastName: $this->string($buyer['last_name'], 'invoice.buyer.last_name'),
            firstName: $this->nullableString($buyer['first_name'], 'invoice.buyer.first_name'),
            taxNumberKind: $this->nullableString($buyer['tax_number_kind'], 'invoice.buyer.tax_number_kind'),
        );
    }

    private function decodePayment(mixed $payload): InvoicePayment
    {
        $payment = $this->object($payload, 'invoice.payment');
        $this->assertExactKeys($payment, self::PaymentKeys, 'invoice.payment');

        return new InvoicePayment(
            type: $this->string($payment['type'], 'invoice.payment.type'),
            status: $this->string($payment['status'], 'invoice.payment.status'),
            paid: $this->decodeMoney($payment['paid'], 'invoice.payment.paid'),
            dueKind: $this->string($payment['due_kind'], 'invoice.payment.due_kind'),
            paidDate: $this->nullableString($payment['paid_date'], 'invoice.payment.paid_date'),
            dueDate: $this->nullableString($payment['due_date'], 'invoice.payment.due_date'),
        );
    }

    private function decodeMoney(mixed $payload, string $path): Money
    {
        $money = $this->object($payload, $path);
        $this->assertExactKeys($money, self::MoneyKeys, $path);

        return new Money(
            minorUnits: $this->integer($money['minor_units'], "{$path}.minor_units"),
            currency: $this->string($money['currency'], "{$path}.currency"),
            fractionDigits: $this->integer($money['fraction_digits'], "{$path}.fraction_digits"),
        );
    }

    private function businessIdentity(
        RemoteIdentityScope $scope,
        ?string $oid,
        ?string $transactionOrderReference,
    ): RemoteInvoiceIdentity {
        if ($oid === null || $transactionOrderReference !== $oid) {
            throw new InvalidArgumentException('Issue invoice business identity is incomplete or inconsistent.');
        }

        return RemoteInvoiceIdentity::businessOid($scope, $oid, OidUniquenessGate::notPassed());
    }

    private function technicalIdentity(
        RemoteIdentityScope $scope,
        ?string $oid,
        ?string $transactionOrderReference,
    ): RemoteInvoiceIdentity {
        if ($oid === null || $transactionOrderReference === null) {
            throw new InvalidArgumentException('Issue invoice technical identity is incomplete.');
        }

        return RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
            $scope,
            $oid,
            $transactionOrderReference,
            OidUniquenessGate::notPassed(),
        );
    }

    private function identityWithoutRemoteUniqueness(
        RemoteIdentityScope $scope,
        ?string $oid,
        ?string $transactionOrderReference,
    ): RemoteInvoiceIdentity {
        if ($oid !== null || $transactionOrderReference !== null) {
            throw new InvalidArgumentException('Issue invoice identity without remote uniqueness contains references.');
        }

        return RemoteInvoiceIdentity::withoutRemoteUniqueness($scope);
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $path): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw new InvalidArgumentException("Issue invoice payload {$path} must be an object.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $expectedKeys
     */
    private function assertExactKeys(array $payload, array $expectedKeys, string $path): void
    {
        $actualKeys = array_keys($payload);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException("Issue invoice payload {$path} has invalid keys.");
        }
    }

    private function assertWithinLimit(CanonicalObject $payload): void
    {
        try {
            $canonical = (new CanonicalJsonV1)->encode($payload);
        } catch (JsonException) {
            throw new InvalidArgumentException('Issue invoice payload cannot be canonicalized.');
        }

        if (strlen($canonical) > self::MaximumPayloadBytes) {
            throw new InvalidArgumentException('Issue invoice payload exceeds the plaintext byte limit.');
        }
    }

    private function string(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Issue invoice payload {$path} must be a string.");
        }

        return $value;
    }

    private function nullableString(mixed $value, string $path): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("Issue invoice payload {$path} must be a nullable string.");
        }

        return $value;
    }

    private function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Issue invoice payload {$path} must be a boolean.");
        }

        return $value;
    }

    private function integer(mixed $value, string $path): int
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException("Issue invoice payload {$path} must be an integer.");
        }

        return $value;
    }
}
