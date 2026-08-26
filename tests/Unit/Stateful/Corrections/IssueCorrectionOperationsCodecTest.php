<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionDraft;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionLine;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionLineMode;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionPositionAttributes;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionPositionKind;
use Cieplik206\Fakturownia\Stateful\Corrections\IssueCorrectionRequestPayload;
use Cieplik206\Fakturownia\Stateful\Corrections\IssueCorrectionResponseMapper;
use Cieplik206\Fakturownia\Stateful\Corrections\IssuedCorrectionResult;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionCommand;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionResult;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionResultCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Tests\Support\Stateful\CorrectionFixtures;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;

it('round trips a strict correction command with negative money and before after snapshots', function (): void {
    $codec = new IssueCorrectionPayloadCodec;
    $command = new IssueCorrectionCommand(CorrectionFixtures::draft());
    $encoded = $codec->encode($command);
    $decoded = $codec->decode($encoded);
    $line = $decoded->draft->positions[0];

    expect(IssueCorrectionPayloadCodec::schemaVersion())->toBe(1)
        ->and($codec->writeActivationSlot($encoded))->toBe('invoice.correction.issue')
        ->and(s71CorrectionPayloadJson($codec->canonicalize($encoded)))
        ->toBe(s71CorrectionPayloadJson($encoded))
        ->and($decoded->draft->sourceInvoiceId)->toBe('source-123')
        ->and($decoded->draft->departmentId)->toBe(839_841)
        ->and($decoded->draft->currency())->toBe('PLN')
        ->and($line->quantity)->toBe('-1.00')
        ->and($line->totalGross->minorUnits)->toBe(-5000)
        ->and($line->priceNet?->minorUnits)->toBe(-4065)
        ->and($line->before->quantity)->toBe('2.00')
        ->and($line->before->totalGross->minorUnits)->toBe(10000)
        ->and($line->after->quantity)->toBe('1.00')
        ->and($line->after->totalGross->minorUnits)->toBe(5000);
});

it('rejects native serialization and keeps the exact provider request credentialless', function (): void {
    $draft = CorrectionFixtures::draft();
    $command = new IssueCorrectionCommand($draft);
    $request = IssueCorrectionRequestPayload::fromDraft($draft);
    $body = $request->bodyWithoutCredentials();

    expect(fn (): string => serialize($draft))->toThrow(LogicException::class)
        ->and(fn (): string => serialize($command))->toThrow(LogicException::class)
        ->and(fn (): string => serialize($request))->toThrow(LogicException::class)
        ->and($body)->not->toHaveKey('api_token')
        ->and($body['invoice']['positions'][0]['quantity'])->toBe('-1.00')
        ->and($body['invoice']['positions'][0]['total_price_gross'])->toBe('-50.00')
        ->and($body['invoice']['positions'][0]['correction_before_attributes']['quantity'])->toBe('2.00')
        ->and($body['invoice']['positions'][0]['correction_after_attributes']['quantity'])->toBe('1.00');
});

it('rejects schema slot keys scalar and before after tampering', function (): void {
    $codec = new IssueCorrectionPayloadCodec;
    $payload = $codec->encode(new IssueCorrectionCommand(CorrectionFixtures::draft()))->values;

    $wrongSchema = $payload;
    $wrongSchema['schema_version'] = 2;

    $wrongSlot = $payload;
    $wrongSlot['write_activation_slot'] = 'invoice.issue';

    $unknownRoot = $payload;
    $unknownRoot['future'] = 'value';

    $stringDepartment = $payload;
    $stringDepartmentCorrection = s71CorrectionMap($stringDepartment['correction']);
    $stringDepartmentCorrection['department_id'] = '839841';
    $stringDepartment['correction'] = $stringDepartmentCorrection;

    $swappedSnapshot = $payload;
    $swappedCorrection = s71CorrectionMap($swappedSnapshot['correction']);
    $swappedPositions = s71CorrectionList($swappedCorrection['positions']);
    $swappedLine = s71CorrectionMap($swappedPositions[0]);
    $before = s71CorrectionMap($swappedLine['before']);
    $before['kind'] = 'correction_after';
    $swappedLine['before'] = $before;
    $swappedPositions[0] = $swappedLine;
    $swappedCorrection['positions'] = $swappedPositions;
    $swappedSnapshot['correction'] = $swappedCorrection;

    $unsupportedMode = $payload;
    $modeCorrection = s71CorrectionMap($unsupportedMode['correction']);
    $modePositions = s71CorrectionList($modeCorrection['positions']);
    $modeLine = s71CorrectionMap($modePositions[0]);
    $modeLine['mode'] = 'refund_calculated_by_sdk';
    $modePositions[0] = $modeLine;
    $modeCorrection['positions'] = $modePositions;
    $unsupportedMode['correction'] = $modeCorrection;

    foreach ([$wrongSchema, $wrongSlot, $unknownRoot, $stringDepartment, $swappedSnapshot, $unsupportedMode] as $hostile) {
        expect(fn (): IssueCorrectionCommand => $codec->decode(new CanonicalObject($hostile)))
            ->toThrow(InvalidArgumentException::class);
    }

    $floatMoney = $payload;
    $floatCorrection = s71CorrectionMap($floatMoney['correction']);
    $floatPositions = s71CorrectionList($floatCorrection['positions']);
    $floatLine = s71CorrectionMap($floatPositions[0]);
    $floatTotal = s71CorrectionMap($floatLine['total_gross']);
    $floatTotal['minor_units'] = -5000.0;
    $floatLine['total_gross'] = $floatTotal;
    $floatPositions[0] = $floatLine;
    $floatCorrection['positions'] = $floatPositions;
    $floatMoney['correction'] = $floatCorrection;

    expect(fn (): CanonicalObject => new CanonicalObject($floatMoney))
        ->toThrow(InvalidArgumentException::class);
});

it('bounds correction position count and both canonical and provider payloads', function (): void {
    $line = CorrectionFixtures::line();

    expect(fn (): CorrectionDraft => CorrectionFixtures::draft(array_fill(0, 1001, $line)))
        ->toThrow(InvalidArgumentException::class);

    $largeDraft = CorrectionFixtures::draft(array_fill(
        0,
        1000,
        CorrectionFixtures::line(str_repeat('x', 256)),
    ));

    expect(fn (): CanonicalObject => (new IssueCorrectionPayloadCodec)->encode(
        new IssueCorrectionCommand($largeDraft),
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): IssueCorrectionRequestPayload => IssueCorrectionRequestPayload::fromDraft($largeDraft))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects conflicting provider links invisible text nonzero preserved deltas and unbounded extras', function (): void {
    $mapper = new IssueCorrectionResponseMapper;
    $response = [
        'id' => 'correction-1',
        'from_invoice_id' => 'source-1',
        'invoice_id' => 'source-2',
        'number' => 'KOR/1',
        'status' => 'issued',
        'total_price_gross' => '-1.00',
        'currency' => 'PLN',
    ];
    $snapshot = new CorrectionPositionAttributes(
        CorrectionPositionKind::Before,
        'Pozycja',
        '1',
        Money::fromDecimal('10.00', 'PLN'),
        '23',
        priceGross: Money::fromDecimal('10.00', 'PLN'),
    );
    $after = new CorrectionPositionAttributes(
        CorrectionPositionKind::After,
        'Pozycja',
        '1',
        Money::fromDecimal('10.00', 'PLN'),
        '23',
        priceGross: Money::fromDecimal('10.00', 'PLN'),
    );

    expect(fn () => $mapper->map($response))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mapper->map(['id' => 0] + array_diff_key($response, ['invoice_id' => true])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionPositionAttributes => new CorrectionPositionAttributes(
            CorrectionPositionKind::Before,
            "Pozycja\u{200D}",
            '1',
            Money::fromDecimal('10.00', 'PLN'),
            '23',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionLine => new CorrectionLine(
            name: 'Pozycja',
            quantity: '0',
            totalGross: Money::fromDecimal('0.00', 'PLN'),
            tax: '23',
            before: $snapshot,
            after: $after,
            priceGross: Money::fromDecimal('0.01', 'PLN'),
            mode: CorrectionLineMode::Preserved,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): IssuedCorrectionResult => new IssuedCorrectionResult(
            'correction-1',
            'source-1',
            'KOR/1',
            'issued',
            Money::fromDecimal('-1.00', 'PLN'),
            array_fill(0, 17, str_repeat('x', 65_536)),
        ))->toThrow(InvalidArgumentException::class);

    $issued = $mapper->map(array_diff_key($response, ['invoice_id' => true]));
    expect(fn (): string => serialize($issued))->toThrow(LogicException::class);
});

it('accepts the exact canonical payload ceiling and rejects one byte more', function (): void {
    $codec = new IssueCorrectionPayloadCodec;
    $maximumPayload = s71CorrectionPayloadAtBytes(262_144);
    $oversized = $maximumPayload->values;
    $correction = s71CorrectionMap($oversized['correction']);
    $clientId = $correction['client_id'] ?? null;

    if (! is_string($clientId)) {
        throw new RuntimeException('The correction boundary fixture has no client ID.');
    }

    $correction['client_id'] = $clientId.'x';
    $oversized['correction'] = $correction;
    $oversizedPayload = new CanonicalObject($oversized);

    expect(strlen(s71CorrectionPayloadJson($maximumPayload)))->toBe(262_144)
        ->and(s71CorrectionPayloadJson($codec->encode($codec->decode($maximumPayload))))
        ->toBe(s71CorrectionPayloadJson($maximumPayload))
        ->and(strlen(s71CorrectionPayloadJson($oversizedPayload)))->toBe(262_145)
        ->and(fn (): IssueCorrectionCommand => $codec->decode($oversizedPayload))
        ->toThrow(InvalidArgumentException::class);
});

it('round trips the exact operation result without provider extras', function (): void {
    $issued = (new IssueCorrectionResponseMapper)->map([
        'id' => 'correction-987654',
        'from_invoice_id' => 'source-123',
        'number' => 'KOR/1/08/2026',
        'status' => 'issued',
        'total_price_gross' => '-50.00',
        'currency' => 'PLN',
        'future_provider_field' => 'must-not-be-persisted',
    ]);
    $result = IssueCorrectionResult::fromIssuedCorrectionResult($issued);
    $codec = new IssueCorrectionResultCodec;
    $encoded = $codec->encode($result);
    $decoded = s71CorrectionResult($codec->decode($encoded));

    expect($encoded->resultType)->toBe('fakturownia.invoice.correction.issue.result')
        ->and($encoded->schemaVersion)->toBe(1)
        ->and($decoded->remoteId)->toBe('correction-987654')
        ->and($decoded->sourceInvoiceId)->toBe('source-123')
        ->and($decoded->totalGross->minorUnits)->toBe(-5000)
        ->and($decoded->toIssuedCorrectionResult()->extra())->toBe([])
        ->and(s71CorrectionResultJson($encoded))->not->toContain('future_provider_field')
        ->and(ReconciliationOutcome::foundExact($result, 'fixture.exact')->operationResult)
        ->toBe($result);
});

it('rejects foreign malformed floating and oversized operation results', function (): void {
    $codec = new IssueCorrectionResultCodec;
    $encoded = $codec->encode(s71IssueCorrectionResult());
    $unknown = $encoded->payload;
    $unknown['future'] = 'value';
    $floatMoney = $encoded->payload;
    $floatMoney['total_gross']['minor_units'] = -5000.0;
    $oversized = $encoded->payload;
    $oversized['number'] = str_repeat('x', 262_144);

    expect(fn (): EncodedResult => $codec->encode(new S71ForeignOperationResult))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OperationResult => $codec->decode(new EncodedResult(
            'foreign.operation.result',
            1,
            $encoded->payload,
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn (): OperationResult => $codec->decode(new EncodedResult(
            IssueCorrectionResultCodec::ResultType,
            2,
            $encoded->payload,
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn (): OperationResult => $codec->decode(new EncodedResult(
            IssueCorrectionResultCodec::ResultType,
            1,
            $unknown,
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn (): EncodedResult => new EncodedResult(
            IssueCorrectionResultCodec::ResultType,
            1,
            $floatMoney,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): OperationResult => $codec->decode(new EncodedResult(
            IssueCorrectionResultCodec::ResultType,
            1,
            $oversized,
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => serialize(s71IssueCorrectionResult()))
        ->toThrow(LogicException::class);
});

function s71CorrectionPayloadAtBytes(int $targetBytes): CanonicalObject
{
    $codec = new IssueCorrectionPayloadCodec;
    $positions = array_fill(0, 240, s71MinimalCorrectionLine('P'));
    $reason = 'R';
    $clientId = 'C';
    $sourceInvoiceId = 'S';
    $base = $codec->encode(new IssueCorrectionCommand(s71CorrectionBoundaryDraft(
        $positions,
        $reason,
        $clientId,
        $sourceInvoiceId,
    )));
    $remainingBytes = $targetBytes - strlen(s71CorrectionPayloadJson($base));

    foreach ($positions as $index => $_line) {
        if ($remainingBytes <= 635) {
            break;
        }

        $addedNameBytes = min(255, intdiv($remainingBytes - 635, 3));
        if ($addedNameBytes === 0) {
            break;
        }

        $positions[$index] = s71MinimalCorrectionLine('P'.str_repeat('x', $addedNameBytes));
        $remainingBytes -= $addedNameBytes * 3;
    }

    if ($remainingBytes === 636) {
        $positions[0] = s71MinimalCorrectionLine($positions[0]->name, '-1.00');
        $remainingBytes -= 3;
    } elseif ($remainingBytes === 637) {
        $positions[0] = s71MinimalCorrectionLine($positions[0]->name, '-1.0');
        $remainingBytes -= 2;
    }

    if ($remainingBytes < 0 || $remainingBytes > 635) {
        throw new RuntimeException("Unable to construct the exact correction payload boundary fixture: {$remainingBytes} bytes remain.");
    }

    $reasonBytes = min(255, $remainingBytes);
    $reason .= str_repeat('r', $reasonBytes);
    $remainingBytes -= $reasonBytes;
    $clientIdBytes = min(190, $remainingBytes);
    $clientId .= str_repeat('c', $clientIdBytes);
    $remainingBytes -= $clientIdBytes;
    $sourceInvoiceId .= str_repeat('s', $remainingBytes);

    return $codec->encode(new IssueCorrectionCommand(s71CorrectionBoundaryDraft(
        $positions,
        $reason,
        $clientId,
        $sourceInvoiceId,
    )));
}

/** @param non-empty-list<CorrectionLine> $positions */
function s71CorrectionBoundaryDraft(
    array $positions,
    string $reason,
    string $clientId,
    string $sourceInvoiceId,
): CorrectionDraft {
    return new CorrectionDraft(
        sourceInvoiceId: $sourceInvoiceId,
        departmentId: 839_841,
        reason: $reason,
        buyer: CorrectionFixtures::buyer(),
        positions: $positions,
        issueDate: '2026-08-26',
        sellDate: '2026-08-25',
        clientId: $clientId,
    );
}

function s71MinimalCorrectionLine(string $name, string $quantity = '-1'): CorrectionLine
{
    return new CorrectionLine(
        name: $name,
        quantity: $quantity,
        totalGross: Money::fromDecimal('-1.00', 'PLN'),
        tax: '23',
        before: new CorrectionPositionAttributes(
            CorrectionPositionKind::Before,
            $name,
            '1',
            Money::fromDecimal('1.00', 'PLN'),
            '23',
        ),
        after: new CorrectionPositionAttributes(
            CorrectionPositionKind::After,
            $name,
            '0',
            Money::fromDecimal('0.00', 'PLN'),
            '23',
        ),
    );
}

/** @return array<string, mixed> */
function s71CorrectionMap(mixed $value): array
{
    if (! is_array($value) || array_is_list($value)) {
        throw new RuntimeException('The correction fixture value must be a map.');
    }

    return $value;
}

/** @return list<mixed> */
function s71CorrectionList(mixed $value): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException('The correction fixture value must be a list.');
    }

    return $value;
}

function s71CorrectionPayloadJson(CanonicalObject $payload): string
{
    return (new CanonicalJsonV1)->encode($payload);
}

function s71IssueCorrectionResult(): IssueCorrectionResult
{
    return new IssueCorrectionResult(
        remoteId: 'correction-987654',
        sourceInvoiceId: 'source-123',
        number: 'KOR/1/08/2026',
        status: 'issued',
        totalGross: CorrectionFixtures::line()->totalGross,
    );
}

function s71CorrectionResult(OperationResult $result): IssueCorrectionResult
{
    if (! $result instanceof IssueCorrectionResult) {
        throw new RuntimeException('The correction result codec returned a foreign type.');
    }

    return $result;
}

function s71CorrectionResultJson(EncodedResult $result): string
{
    return (new CanonicalJsonV1)->encode(new CanonicalObject($result->toArray()));
}

final readonly class S71ForeignOperationResult implements OperationResult
{
    public function resultType(): string
    {
        return 'foreign.operation.result';
    }
}
