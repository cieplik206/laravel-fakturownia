<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Diagnostics\FakturowniaDiagnosticDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Diagnostics\FakturowniaDiagnosticProviderExtensions;
use Cieplik206\Fakturownia\Stateful\Diagnostics\FakturowniaDiagnosticResult;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Testing\Conformance\ProviderConformanceKit;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;

it('passes the provider conformance kit with exact self-bindings', function (): void {
    $report = (new ProviderConformanceKit)->inspect(
        FakturowniaDiagnosticDefinitionProvider::class,
        fn (string $serviceId, string $contract): string => $serviceId,
    );

    expect($report->passed())->toBeTrue();
    $report->assertPassed();
});

it('executes and round-trips the no-IO diagnostic operation without opening an effect boundary', function (): void {
    $boundary = new class implements EffectBoundary
    {
        private bool $opened = false;

        public function open(): void
        {
            $this->opened = true;
        }

        public function wasOpened(): bool
        {
            return $this->opened;
        }
    };
    $operation = new class($boundary) implements OperationExecution
    {
        public function __construct(private readonly EffectBoundary $boundary) {}

        public function operationId(): OperationId
        {
            return new OperationId('01J00000000000000000000000');
        }

        public function scope(): IntegrationScope
        {
            return IntegrationScope::of('fakturownia', 'diagnostic');
        }

        public function operationType(): OperationType
        {
            return new OperationType('fakturownia.diagnostic.echo');
        }

        public function context(): IntegrationContext
        {
            return IntegrationContext::make();
        }

        public function payload(): CanonicalObject
        {
            return new CanonicalObject(['challenge' => 'local_probe_01']);
        }

        public function effectBoundary(): EffectBoundary
        {
            return $this->boundary;
        }
    };
    $extensions = new FakturowniaDiagnosticProviderExtensions;
    $outcome = $extensions->execute($operation);
    $encoded = $extensions->encode($outcome->result);
    $decoded = $extensions->decode($encoded);

    expect($outcome->result)->toEqual(new FakturowniaDiagnosticResult('local_probe_01'))
        ->and($decoded)->toEqual($outcome->result)
        ->and($boundary->wasOpened())->toBeFalse();

    $extensions->project($operation, $outcome);
});
