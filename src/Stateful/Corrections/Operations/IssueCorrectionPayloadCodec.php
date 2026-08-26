<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionDraft;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionLine;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionLineMode;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionPositionAttributes;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionPositionKind;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityPolicy;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;
use JsonException;

final readonly class IssueCorrectionPayloadCodec implements OperationPayloadCodec
{
    public const int SchemaVersion = 2;

    public const string WriteActivationSlot = 'invoice.correction.issue';

    private const int MaximumPayloadBytes = 262_144;

    /** @var list<string> */
    private const array PayloadKeys = ['schema_version', 'write_activation_slot', 'identity', 'correction'];

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
    private const array CorrectionKeys = [
        'source_invoice_id',
        'department_id',
        'reason',
        'buyer',
        'positions',
        'issue_date',
        'sell_date',
        'client_id',
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
    private const array LineKeys = [
        'name',
        'quantity',
        'total_gross',
        'tax',
        'before',
        'after',
        'unit',
        'price_net',
        'price_gross',
        'total_net',
        'mode',
    ];

    /** @var list<string> */
    private const array AttributesKeys = [
        'kind',
        'name',
        'quantity',
        'total_gross',
        'tax',
        'unit',
        'price_net',
        'price_gross',
        'total_net',
    ];

    /** @var list<string> */
    private const array MoneyKeys = ['minor_units', 'currency', 'fraction_digits'];

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(IssueCorrectionCommand $command): CanonicalObject
    {
        $payload = new CanonicalObject([
            'schema_version' => self::schemaVersion(),
            'write_activation_slot' => self::WriteActivationSlot,
            'identity' => $this->encodeIdentity($command->identity),
            'correction' => $this->encodeDraft($command->draft),
        ]);

        $this->assertWithinLimit($payload);

        return $payload;
    }

    public function decode(CanonicalObject $payload): IssueCorrectionCommand
    {
        $this->assertWithinLimit($payload);
        $this->assertExactKeys($payload->values, self::PayloadKeys, 'payload');

        if ($payload->values['schema_version'] !== self::schemaVersion()) {
            throw new InvalidArgumentException('Issue correction payload uses an unsupported schema.');
        }

        if ($payload->values['write_activation_slot'] !== self::WriteActivationSlot) {
            throw new InvalidArgumentException('Issue correction payload uses an unsupported write activation slot.');
        }

        return new IssueCorrectionCommand(
            $this->decodeDraft($payload->values['correction']),
            $this->decodeIdentity($payload->values['identity']),
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
    private function encodeDraft(CorrectionDraft $draft): array
    {
        return [
            'source_invoice_id' => $draft->sourceInvoiceId,
            'department_id' => $draft->departmentId,
            'reason' => $draft->reason,
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
            'positions' => array_map(
                fn (CorrectionLine $line): array => $this->encodeLine($line),
                $draft->positions,
            ),
            'issue_date' => $draft->issueDate,
            'sell_date' => $draft->sellDate,
            'client_id' => $draft->clientId,
        ];
    }

    /** @return array<string, mixed> */
    private function encodeLine(CorrectionLine $line): array
    {
        return [
            'name' => $line->name,
            'quantity' => $line->quantity,
            'total_gross' => $this->encodeMoney($line->totalGross),
            'tax' => $line->tax,
            'before' => $this->encodeAttributes($line->before),
            'after' => $this->encodeAttributes($line->after),
            'unit' => $line->unit,
            'price_net' => $this->encodeNullableMoney($line->priceNet),
            'price_gross' => $this->encodeNullableMoney($line->priceGross),
            'total_net' => $this->encodeNullableMoney($line->totalNet),
            'mode' => $line->mode->value,
        ];
    }

    /** @return array<string, mixed> */
    private function encodeAttributes(CorrectionPositionAttributes $attributes): array
    {
        return [
            'kind' => $attributes->kind->value,
            'name' => $attributes->name,
            'quantity' => $attributes->quantity,
            'total_gross' => $this->encodeMoney($attributes->totalGross),
            'tax' => $attributes->tax,
            'unit' => $attributes->unit,
            'price_net' => $this->encodeNullableMoney($attributes->priceNet),
            'price_gross' => $this->encodeNullableMoney($attributes->priceGross),
            'total_net' => $this->encodeNullableMoney($attributes->totalNet),
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

    /** @return array{minor_units: int, currency: string, fraction_digits: int}|null */
    private function encodeNullableMoney(?Money $money): ?array
    {
        return $money === null ? null : $this->encodeMoney($money);
    }

    private function decodeDraft(mixed $payload): CorrectionDraft
    {
        $correction = $this->object($payload, 'correction');
        $this->assertExactKeys($correction, self::CorrectionKeys, 'correction');
        $positionsPayload = $correction['positions'];

        if (! is_array($positionsPayload) || ! array_is_list($positionsPayload)) {
            throw new InvalidArgumentException('Issue correction payload positions must be a list.');
        }

        $positions = [];
        foreach ($positionsPayload as $index => $line) {
            $positions[] = $this->decodeLine($line, $index);
        }

        return new CorrectionDraft(
            sourceInvoiceId: $this->string($correction['source_invoice_id'], 'correction.source_invoice_id'),
            departmentId: $this->integer($correction['department_id'], 'correction.department_id'),
            reason: $this->string($correction['reason'], 'correction.reason'),
            buyer: $this->decodeBuyer($correction['buyer']),
            positions: $positions,
            issueDate: $this->nullableString($correction['issue_date'], 'correction.issue_date'),
            sellDate: $this->nullableString($correction['sell_date'], 'correction.sell_date'),
            clientId: $this->nullableString($correction['client_id'], 'correction.client_id'),
        );
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
        $policy = RemoteIdentityPolicy::tryFrom($this->string($identity['policy'], 'identity.policy'));
        $oid = $this->nullableString($identity['oid'], 'identity.oid');
        $localReference = $this->nullableString(
            $identity['transaction_order_reference'],
            'identity.transaction_order_reference',
        );

        return match ($policy) {
            RemoteIdentityPolicy::BusinessOid => $this->businessIdentity($scope, $oid, $localReference),
            RemoteIdentityPolicy::TechnicalOidWithTransactionOrder => $this->technicalIdentity(
                $scope,
                $oid,
                $localReference,
            ),
            RemoteIdentityPolicy::NoRemoteUniqueness => throw new InvalidArgumentException(
                'Issue correction identity must carry a stable local return reference.',
            ),
            null => throw new InvalidArgumentException('Issue correction payload identity policy is unsupported.'),
        };
    }

    private function businessIdentity(
        RemoteIdentityScope $scope,
        ?string $oid,
        ?string $localReference,
    ): RemoteInvoiceIdentity {
        if ($oid === null || $localReference !== $oid) {
            throw new InvalidArgumentException('Issue correction business identity is incomplete or inconsistent.');
        }

        return RemoteInvoiceIdentity::businessOid($scope, $oid, OidUniquenessGate::notPassed());
    }

    private function technicalIdentity(
        RemoteIdentityScope $scope,
        ?string $oid,
        ?string $localReference,
    ): RemoteInvoiceIdentity {
        if ($oid === null || $localReference === null) {
            throw new InvalidArgumentException('Issue correction technical identity is incomplete.');
        }

        return RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
            $scope,
            $oid,
            $localReference,
            OidUniquenessGate::notPassed(),
        );
    }

    private function decodeBuyer(mixed $payload): InvoiceBuyer
    {
        $buyer = $this->object($payload, 'correction.buyer');
        $this->assertExactKeys($buyer, self::BuyerKeys, 'correction.buyer');

        return new InvoiceBuyer(
            company: $this->boolean($buyer['company'], 'correction.buyer.company'),
            name: $this->string($buyer['name'], 'correction.buyer.name'),
            taxNumber: $this->nullableString($buyer['tax_number'], 'correction.buyer.tax_number'),
            postCode: $this->string($buyer['post_code'], 'correction.buyer.post_code'),
            city: $this->string($buyer['city'], 'correction.buyer.city'),
            street: $this->string($buyer['street'], 'correction.buyer.street'),
            country: $this->string($buyer['country'], 'correction.buyer.country'),
            email: $this->string($buyer['email'], 'correction.buyer.email'),
            lastName: $this->string($buyer['last_name'], 'correction.buyer.last_name'),
            firstName: $this->nullableString($buyer['first_name'], 'correction.buyer.first_name'),
            taxNumberKind: $this->nullableString($buyer['tax_number_kind'], 'correction.buyer.tax_number_kind'),
        );
    }

    private function decodeLine(mixed $payload, int $index): CorrectionLine
    {
        $path = "correction.positions.{$index}";
        $line = $this->object($payload, $path);
        $this->assertExactKeys($line, self::LineKeys, $path);
        $mode = CorrectionLineMode::tryFrom($this->string($line['mode'], "{$path}.mode"));

        if ($mode === null) {
            throw new InvalidArgumentException("Issue correction payload {$path}.mode is unsupported.");
        }

        return new CorrectionLine(
            name: $this->string($line['name'], "{$path}.name"),
            quantity: $this->string($line['quantity'], "{$path}.quantity"),
            totalGross: $this->decodeMoney($line['total_gross'], "{$path}.total_gross"),
            tax: $this->string($line['tax'], "{$path}.tax"),
            before: $this->decodeAttributes($line['before'], "{$path}.before"),
            after: $this->decodeAttributes($line['after'], "{$path}.after"),
            unit: $this->string($line['unit'], "{$path}.unit"),
            priceNet: $this->decodeNullableMoney($line['price_net'], "{$path}.price_net"),
            priceGross: $this->decodeNullableMoney($line['price_gross'], "{$path}.price_gross"),
            totalNet: $this->decodeNullableMoney($line['total_net'], "{$path}.total_net"),
            mode: $mode,
        );
    }

    private function decodeAttributes(mixed $payload, string $path): CorrectionPositionAttributes
    {
        $attributes = $this->object($payload, $path);
        $this->assertExactKeys($attributes, self::AttributesKeys, $path);
        $kind = CorrectionPositionKind::tryFrom($this->string($attributes['kind'], "{$path}.kind"));

        if ($kind === null) {
            throw new InvalidArgumentException("Issue correction payload {$path}.kind is unsupported.");
        }

        return new CorrectionPositionAttributes(
            kind: $kind,
            name: $this->string($attributes['name'], "{$path}.name"),
            quantity: $this->string($attributes['quantity'], "{$path}.quantity"),
            totalGross: $this->decodeMoney($attributes['total_gross'], "{$path}.total_gross"),
            tax: $this->string($attributes['tax'], "{$path}.tax"),
            unit: $this->string($attributes['unit'], "{$path}.unit"),
            priceNet: $this->decodeNullableMoney($attributes['price_net'], "{$path}.price_net"),
            priceGross: $this->decodeNullableMoney($attributes['price_gross'], "{$path}.price_gross"),
            totalNet: $this->decodeNullableMoney($attributes['total_net'], "{$path}.total_net"),
        );
    }

    private function decodeMoney(mixed $payload, string $path): Money
    {
        $money = $this->object($payload, $path);
        $this->assertExactKeys($money, self::MoneyKeys, $path);

        return new Money(
            $this->integer($money['minor_units'], "{$path}.minor_units"),
            $this->string($money['currency'], "{$path}.currency"),
            $this->integer($money['fraction_digits'], "{$path}.fraction_digits"),
        );
    }

    private function decodeNullableMoney(mixed $payload, string $path): ?Money
    {
        return $payload === null ? null : $this->decodeMoney($payload, $path);
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $path): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw new InvalidArgumentException("Issue correction payload {$path} must be an object.");
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
            throw new InvalidArgumentException("Issue correction payload {$path} has invalid keys.");
        }
    }

    private function assertWithinLimit(CanonicalObject $payload): void
    {
        try {
            $canonical = (new CanonicalJsonV1)->encode($payload);
        } catch (JsonException) {
            throw new InvalidArgumentException('Issue correction payload cannot be canonicalized.');
        }

        if (strlen($canonical) > self::MaximumPayloadBytes) {
            throw new InvalidArgumentException('Issue correction payload exceeds the plaintext byte limit.');
        }
    }

    private function string(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Issue correction payload {$path} must be a string.");
        }

        return $value;
    }

    private function nullableString(mixed $value, string $path): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("Issue correction payload {$path} must be a nullable string.");
        }

        return $value;
    }

    private function integer(mixed $value, string $path): int
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException("Issue correction payload {$path} must be an integer.");
        }

        return $value;
    }

    private function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Issue correction payload {$path} must be a boolean.");
        }

        return $value;
    }
}
