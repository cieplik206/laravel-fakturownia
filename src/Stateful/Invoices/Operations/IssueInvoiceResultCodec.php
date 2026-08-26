<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use InvalidArgumentException;
use JsonException;

final readonly class IssueInvoiceResultCodec implements OperationResultCodec
{
    public const string ResultType = 'fakturownia.invoice.issue.result';

    public const int SchemaVersion = 1;

    private const int MaximumEnvelopeBytes = EncodedResult::HardMaximumCanonicalBytes;

    /** @var list<string> */
    private const array PayloadKeys = [
        'remote_id',
        'number',
        'kind',
        'status',
        'issue_date',
        'buyer_tax_number',
        'total_gross',
        'oid',
        'positions',
    ];

    /** @var list<string> */
    private const array MoneyKeys = ['minor_units', 'currency', 'fraction_digits'];

    /** @var list<string> */
    private const array PositionKeys = ['name', 'tax', 'total_gross', 'quantity', 'unit'];

    public static function resultType(): string
    {
        return self::ResultType;
    }

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        if (! $result instanceof IssueInvoiceResult) {
            throw new InvalidArgumentException('Issue invoice result codec received an unsupported result.');
        }

        $encoded = new EncodedResult(
            self::resultType(),
            self::schemaVersion(),
            [
                'remote_id' => $result->remoteId,
                'number' => $result->number,
                'kind' => $result->kind,
                'status' => $result->status,
                'issue_date' => $result->issueDate,
                'buyer_tax_number' => $result->buyerTaxNumber,
                'total_gross' => $this->encodeMoney($result->totalGross),
                'oid' => $result->oid,
                'positions' => array_map(
                    fn (InvoiceLine $position): array => [
                        'name' => $position->name,
                        'tax' => $position->tax,
                        'total_gross' => $this->encodeMoney($position->totalGross),
                        'quantity' => $position->quantity,
                        'unit' => $position->unit,
                    ],
                    $result->positions,
                ),
            ],
        );

        $this->assertEnvelopeWithinLimit($encoded);

        return $encoded;
    }

    public function decode(EncodedResult $result): OperationResult
    {
        if ($result->resultType !== self::resultType() || $result->schemaVersion !== self::schemaVersion()) {
            throw new InvalidArgumentException('Encoded issue invoice result uses an unsupported type or schema.');
        }

        $this->assertEnvelopeWithinLimit($result);
        $this->assertExactKeys($result->payload, self::PayloadKeys, 'payload');

        $positionsPayload = $result->payload['positions'];
        if (! is_array($positionsPayload) || ! array_is_list($positionsPayload)) {
            throw new InvalidArgumentException('Encoded issue invoice result positions must be a list.');
        }

        if ($positionsPayload === [] || count($positionsPayload) > 1000) {
            throw new InvalidArgumentException('Encoded issue invoice result positions must be a bounded non-empty list.');
        }

        $positions = [];
        foreach ($positionsPayload as $positionPayload) {
            if (! is_array($positionPayload) || array_is_list($positionPayload)) {
                throw new InvalidArgumentException('Encoded issue invoice result contains an invalid position.');
            }

            $this->assertExactKeys($positionPayload, self::PositionKeys, 'position');
            $positions[] = new InvoiceLine(
                name: $this->string($positionPayload['name'], 'position.name'),
                tax: $this->string($positionPayload['tax'], 'position.tax'),
                totalGross: $this->decodeMoney($positionPayload['total_gross'], 'position.total_gross'),
                quantity: $this->string($positionPayload['quantity'], 'position.quantity'),
                unit: $this->string($positionPayload['unit'], 'position.unit'),
            );
        }

        return new IssueInvoiceResult(
            remoteId: $this->string($result->payload['remote_id'], 'remote_id'),
            number: $this->string($result->payload['number'], 'number'),
            kind: $this->string($result->payload['kind'], 'kind'),
            status: $this->string($result->payload['status'], 'status'),
            issueDate: $this->string($result->payload['issue_date'], 'issue_date'),
            buyerTaxNumber: $this->nullableString($result->payload['buyer_tax_number'], 'buyer_tax_number'),
            totalGross: $this->decodeMoney($result->payload['total_gross'], 'total_gross'),
            oid: $this->nullableString($result->payload['oid'], 'oid'),
            positions: $positions,
        );
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

    private function decodeMoney(mixed $payload, string $path): Money
    {
        if (! is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException("Encoded issue invoice result {$path} must be an object.");
        }

        $this->assertExactKeys($payload, self::MoneyKeys, $path);

        if (! is_int($payload['minor_units']) || ! is_int($payload['fraction_digits'])) {
            throw new InvalidArgumentException("Encoded issue invoice result {$path} must use integer money fields.");
        }

        return new Money(
            minorUnits: $payload['minor_units'],
            currency: $this->string($payload['currency'], "{$path}.currency"),
            fractionDigits: $payload['fraction_digits'],
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $expectedKeys
     */
    private function assertExactKeys(array $payload, array $expectedKeys, string $path): void
    {
        $actualKeys = array_keys($payload);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException("Encoded issue invoice result {$path} has invalid keys.");
        }
    }

    private function assertEnvelopeWithinLimit(EncodedResult $result): void
    {
        try {
            $canonical = (new CanonicalJsonV1)->encode(new CanonicalObject($result->toArray()));
        } catch (JsonException) {
            throw new InvalidArgumentException('Encoded issue invoice result cannot be canonicalized.');
        }

        if (strlen($canonical) > self::MaximumEnvelopeBytes) {
            throw new InvalidArgumentException('Encoded issue invoice result exceeds the plaintext envelope byte limit.');
        }
    }

    private function string(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Encoded issue invoice result {$path} must be a string.");
        }

        return $value;
    }

    private function nullableString(mixed $value, string $path): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("Encoded issue invoice result {$path} must be a nullable string.");
        }

        return $value;
    }
}
