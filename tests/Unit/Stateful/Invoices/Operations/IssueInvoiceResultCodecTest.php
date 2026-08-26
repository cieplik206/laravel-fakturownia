<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceResponseMapper;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResultCodec;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;

it('round trips the canonical operation result without provider extras', function (): void {
    $issued = (new IssueInvoiceResponseMapper)->map(InvoiceFixtures::json('issue-vat-response.json'));
    $result = IssueInvoiceResult::fromIssuedInvoiceResult($issued);
    $codec = new IssueInvoiceResultCodec;
    $encoded = $codec->encode($result);
    $decoded = rt44DecodeIssueInvoiceResult($codec, $encoded);
    $canonicalEnvelope = rt44IssueInvoiceResultEnvelope($encoded);

    expect($codec->encode($decoded)->equals($encoded))->toBeTrue()
        ->and($encoded->resultType)->toBe(IssueInvoiceResultCodec::ResultType)
        ->and($encoded->schemaVersion)->toBe(IssueInvoiceResultCodec::SchemaVersion)
        ->and($result->resultType())->toBe(IssueInvoiceResultCodec::ResultType)
        ->and($canonicalEnvelope)->not->toContain('provider_future_field')
        ->and($canonicalEnvelope)->not->toContain('api_token')
        ->and($decoded->remoteId)->toBe('380058094')
        ->and($decoded->totalGross->decimal())->toBe('100.00')
        ->and($decoded->positions)->toHaveCount(2)
        ->and($decoded->toIssuedInvoiceResult()->extra())->toBe([]);

    expect(ReconciliationOutcome::foundExact($result, 'fixture.exact')->operationResult)
        ->toBe($result);
});

it('rejects native object serialization in favor of the bounded codec', function (): void {
    $result = rt44IssueInvoiceResult();

    expect(fn (): string => serialize($result))->toThrow(LogicException::class);
});

it('rejects foreign results plus mismatched envelope type schema and keys', function (): void {
    $codec = new IssueInvoiceResultCodec;
    $encoded = $codec->encode(rt44IssueInvoiceResult());

    /** @var array<string, mixed> $extended */
    $extended = $encoded->payload;
    $extended['future'] = 'value';

    expect(fn (): EncodedResult => $codec->encode(new Rt44ForeignOperationResult))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OperationResult => $codec->decode(new EncodedResult(
            'foreign.operation.result',
            IssueInvoiceResultCodec::SchemaVersion,
            $encoded->payload,
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn (): OperationResult => $codec->decode(new EncodedResult(
            IssueInvoiceResultCodec::ResultType,
            2,
            $encoded->payload,
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn (): OperationResult => $codec->decode(new EncodedResult(
            IssueInvoiceResultCodec::ResultType,
            IssueInvoiceResultCodec::SchemaVersion,
            $extended,
        )))->toThrow(InvalidArgumentException::class);
});

it('rejects floats ambiguous money and malformed nested shapes', function (): void {
    $codec = new IssueInvoiceResultCodec;
    $payload = $codec->encode(rt44IssueInvoiceResult())->payload;

    /** @var array<string, mixed> $floatMoney */
    $floatMoney = $payload;
    $floatMoney['total_gross']['minor_units'] = 10000.0;

    /** @var array<string, mixed> $stringFraction */
    $stringFraction = $payload;
    $stringFraction['total_gross']['fraction_digits'] = '2';

    /** @var array<string, mixed> $positionExtra */
    $positionExtra = $payload;
    $positionExtra['positions'][0]['secret'] = 'must be rejected';

    /** @var array<string, mixed> $emptyPositions */
    $emptyPositions = $payload;
    $emptyPositions['positions'] = [];

    /** @var array<string, mixed> $tooManyPositions */
    $tooManyPositions = $payload;
    $tooManyPositions['positions'] = array_fill(0, 1001, $payload['positions'][0]);

    expect(fn (): EncodedResult => new EncodedResult(
        IssueInvoiceResultCodec::ResultType,
        IssueInvoiceResultCodec::SchemaVersion,
        $floatMoney,
    ))->toThrow(InvalidArgumentException::class);

    foreach ([$stringFraction, $positionExtra, $emptyPositions, $tooManyPositions] as $hostile) {
        $encoded = new EncodedResult(
            IssueInvoiceResultCodec::ResultType,
            IssueInvoiceResultCodec::SchemaVersion,
            $hostile,
        );

        expect(fn (): OperationResult => $codec->decode($encoded))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('accepts the exact kernel plaintext ceiling and rejects one byte more', function (): void {
    $codec = new IssueInvoiceResultCodec;
    $maximumEnvelope = rt44IssueInvoiceResultEnvelopeAtBytes(262_144);
    $oversizedPayload = $maximumEnvelope->payload;
    $number = $oversizedPayload['number'] ?? null;

    if (! is_string($number)) {
        throw new RuntimeException('The issue result boundary fixture has no canonical number.');
    }

    $oversizedPayload['number'] = $number.'x';
    $oversizedCanonicalEnvelope = (new CanonicalJsonV1)->encode(new CanonicalObject([
        'result_type' => IssueInvoiceResultCodec::ResultType,
        'schema_version' => IssueInvoiceResultCodec::SchemaVersion,
        'payload' => $oversizedPayload,
    ]));

    expect(strlen(rt44IssueInvoiceResultEnvelope($maximumEnvelope)))->toBe(262_144)
        ->and($codec->encode($codec->decode($maximumEnvelope))->equals($maximumEnvelope))->toBeTrue()
        ->and(strlen($oversizedCanonicalEnvelope))->toBe(262_145)
        ->and(fn (): EncodedResult => new EncodedResult(
            IssueInvoiceResultCodec::ResultType,
            IssueInvoiceResultCodec::SchemaVersion,
            $oversizedPayload,
        ))
        ->toThrow(InvalidArgumentException::class);
});

function rt44IssueInvoiceResult(): IssueInvoiceResult
{
    $issued = (new IssueInvoiceResponseMapper)->map(InvoiceFixtures::json('issue-vat-response.json'));

    return IssueInvoiceResult::fromIssuedInvoiceResult($issued);
}

function rt44IssueInvoiceResultEnvelopeAtBytes(int $targetBytes): EncodedResult
{
    $codec = new IssueInvoiceResultCodec;
    $positions = array_fill(
        0,
        300,
        new InvoiceLine('P', '23', Money::fromDecimal('1.00', 'PLN'), '1'),
    );
    $base = rt44IssueInvoiceResultWithPositions($positions);
    $remainingBytes = $targetBytes - strlen(rt44IssueInvoiceResultEnvelope($codec->encode($base)));

    foreach ($positions as $index => $position) {
        if ($remainingBytes === 0) {
            break;
        }

        $addedBytes = min(1023, $remainingBytes);
        $positions[$index] = new InvoiceLine(
            'P'.str_repeat('x', $addedBytes),
            $position->tax,
            $position->totalGross,
            $position->quantity,
            $position->unit,
        );
        $remainingBytes -= $addedBytes;
    }

    if ($remainingBytes !== 0) {
        throw new RuntimeException('Unable to construct the exact issue result codec boundary fixture.');
    }

    return $codec->encode(rt44IssueInvoiceResultWithPositions($positions));
}

/** @param non-empty-list<InvoiceLine> $positions */
function rt44IssueInvoiceResultWithPositions(array $positions): IssueInvoiceResult
{
    $base = rt44IssueInvoiceResult();

    return new IssueInvoiceResult(
        remoteId: $base->remoteId,
        number: $base->number,
        kind: $base->kind,
        status: $base->status,
        issueDate: $base->issueDate,
        buyerTaxNumber: $base->buyerTaxNumber,
        totalGross: $base->totalGross,
        oid: $base->oid,
        positions: $positions,
    );
}

function rt44DecodeIssueInvoiceResult(
    IssueInvoiceResultCodec $codec,
    EncodedResult $encoded,
): IssueInvoiceResult {
    $decoded = $codec->decode($encoded);

    if (! $decoded instanceof IssueInvoiceResult) {
        throw new RuntimeException('The issue invoice codec returned a foreign operation result.');
    }

    return $decoded;
}

function rt44IssueInvoiceResultEnvelope(EncodedResult $encoded): string
{
    return (new CanonicalJsonV1)->encode(new CanonicalObject($encoded->toArray()));
}

final readonly class Rt44ForeignOperationResult implements OperationResult
{
    public function resultType(): string
    {
        return IssueInvoiceResultCodec::ResultType;
    }
}
