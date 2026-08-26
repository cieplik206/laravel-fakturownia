<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Diagnostics;

use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use InvalidArgumentException;
use Throwable;

final readonly class FakturowniaDiagnosticProviderExtensions implements FailureClassifier, OperationHandler, OperationResultCodec, OutcomeProjector, RetryPolicy
{
    private const string OperationType = 'fakturownia.diagnostic.echo';

    private const string ResultType = 'fakturownia.diagnostic.echo_result';

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        $challenge = $this->challenge($operation);

        if ($operation->effectBoundary()->wasOpened()) {
            throw new InvalidArgumentException('Diagnostic operation cannot use an effect boundary.');
        }

        return new ExecutionOutcome(new FakturowniaDiagnosticResult($challenge));
    }

    public function classify(OperationView $operation, Throwable $failure): FailureClassification
    {
        $this->assertExpectedOperation($operation);

        return new FailureClassification(
            FailureDisposition::Permanent,
            new SafeOperationFailure('diagnostic_failed', 'The local diagnostic operation failed.'),
        );
    }

    public function decide(OperationView $operation, FailureClassification $failure): RetryInstruction
    {
        $this->assertExpectedOperation($operation);

        return RetryInstruction::fail();
    }

    public function encode(OperationResult $result): EncodedResult
    {
        if (! $result instanceof FakturowniaDiagnosticResult) {
            throw new InvalidArgumentException('Diagnostic result type is invalid.');
        }

        return new EncodedResult(self::resultType(), self::schemaVersion(), [
            'challenge' => $result->challenge,
        ]);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        $payload = $result->payload;

        if ($result->resultType !== self::resultType()
            || $result->schemaVersion !== self::schemaVersion()
            || array_keys($payload) !== ['challenge']
            || ! is_string($payload['challenge'] ?? null)) {
            throw new InvalidArgumentException('Diagnostic result envelope is invalid.');
        }

        return new FakturowniaDiagnosticResult($payload['challenge']);
    }

    public function project(OperationView $operation, ExecutionOutcome $outcome): void
    {
        $this->assertExpectedOperation($operation);

        if (! $outcome->result instanceof FakturowniaDiagnosticResult) {
            throw new InvalidArgumentException('Diagnostic projection result is invalid.');
        }
    }

    public static function resultType(): string
    {
        return self::ResultType;
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    private function challenge(OperationView $operation): string
    {
        $this->assertExpectedOperation($operation);
        $payload = $operation->payload()->values;

        if (array_keys($payload) !== ['challenge'] || ! is_string($payload['challenge'] ?? null)) {
            throw new InvalidArgumentException('Diagnostic operation payload is invalid.');
        }

        return $payload['challenge'];
    }

    private function assertExpectedOperation(OperationView $operation): void
    {
        if ($operation->operationType()->value !== self::OperationType
            || $operation->scope()->provider->value !== 'fakturownia') {
            throw new InvalidArgumentException('Diagnostic operation identity is invalid.');
        }
    }
}
