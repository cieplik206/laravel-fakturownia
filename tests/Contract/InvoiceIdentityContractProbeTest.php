<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Tests\Contract\Support\ProbeConfiguration;

it('denies direct local invoice identity probe execution', function (): void {
    if (! ProbeConfiguration::enabled()) {
        $this->markTestSkipped('Set FAKTUROWNIA_CONTRACT_PROBE_ENABLED=yes to verify the direct-execution denial.');
    }

    expect(fn () => ProbeConfiguration::fromEnvironment())
        ->toThrow(RuntimeException::class, ProbeConfiguration::BrokeredEffectExecutionUnavailable);
})->group('contract', 'live');
