<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use InvalidArgumentException;
use JsonException;

final readonly class IssueCorrectionResultCodec implements OperationResultCodec
{
    public const string ResultType = 'fakturownia.invoice.correction.issue.result';

    public const int SchemaVersion = 1;

    private const int MaximumEnvelopeBytes = EncodedResult::HardMaximumCanonicalBytes;

    /** @var list<string> */
    private const array PayloadKeys = [
        'remote_id',
        'source_invoice_id',
        'number',
        'status',
        'total_gross',
    ];

    /** @var list<string> */
    private const array MoneyKeys = ['minor_units', 'currency', 'fraction_digits'];

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
        if (! $result instanceof IssueCorrectionResult) {
            throw new InvalidArgumentException('Issue correction result codec received an unsupported result.');
        }

        $encoded = new EncodedResult(
            self::resultType(),
            self::schemaVersion(),
            [
                'remote_id' => $result->remoteId,
                'source_invoice_id' => $result->sourceInvoiceId,
                'number' => $result->number,
                'status' => $result->status,
                'total_gross' => $this->encodeMoney($result->totalGross),
            ],
        );

        $this->assertEnvelopeWithinLimit($encoded);

        return $encoded;
    }

    public function decode(EncodedResult $result): OperationResult
    {
        if ($result->resultType !== self::resultType() || $result->schemaVersion !== self::schemaVersion()) {
            throw new InvalidArgumentException('Encoded issue correction result uses an unsupported type or schema.');
        }

        $this->assertEnvelopeWithinLimit($result);
        $this->assertExactKeys($result->payload, self::PayloadKeys, 'payload');

        return new IssueCorrectionResult(
            remoteId: $this->string($result->payload['remote_id'], 'remote_id'),
            sourceInvoiceId: $this->string($result->payload['source_invoice_id'], 'source_invoice_id'),
            number: $this->string($result->payload['number'], 'number'),
            status: $this->string($result->payload['status'], 'status'),
            totalGross: $this->decodeMoney($result->payload['total_gross']),
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

    private function decodeMoney(mixed $payload): Money
    {
        if (! is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Encoded issue correction result total_gross must be an object.');
        }

        $this->assertExactKeys($payload, self::MoneyKeys, 'total_gross');

        if (! is_int($payload['minor_units']) || ! is_int($payload['fraction_digits'])) {
            throw new InvalidArgumentException('Encoded issue correction result money fields must be integers.');
        }

        return new Money(
            $payload['minor_units'],
            $this->string($payload['currency'], 'total_gross.currency'),
            $payload['fraction_digits'],
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
            throw new InvalidArgumentException("Encoded issue correction result {$path} has invalid keys.");
        }
    }

    private function assertEnvelopeWithinLimit(EncodedResult $result): void
    {
        try {
            $canonical = (new CanonicalJsonV1)->encode(new CanonicalObject($result->toArray()));
        } catch (JsonException) {
            throw new InvalidArgumentException('Encoded issue correction result cannot be canonicalized.');
        }

        if (strlen($canonical) > self::MaximumEnvelopeBytes) {
            throw new InvalidArgumentException('Encoded issue correction result exceeds the plaintext envelope byte limit.');
        }
    }

    private function string(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Encoded issue correction result {$path} must be a string.");
        }

        return $value;
    }
}
