<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoContractProbe;

it('records the complete fail-closed KSeF DEMO capability matrix', function (): void {
    $probe = KsefDemoContractProbe::runConfigured();

    expect($probe['path'])->toBeFile()
        ->and($probe['result']['capability_0_2']['matrix_complete'])->toBeTrue()
        ->and($probe['result']['capability_0_2']['supported_profile'])->toBe('explicit_sdk+block_invalid');
})->group('contract', 'live');
