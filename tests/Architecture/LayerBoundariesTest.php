<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\Attributes\RequiresCapability;
use Cieplik206\Fakturownia\Tests\Support\Architecture\RemoteApiArchitectureInspector;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

it('keeps the Client layer independent from Laravel kernel and Stateful code', function (): void {
    foreach (phpFiles(packageRoot().'/src/Client') as $file) {
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse()
            ->and($contents)->not->toContain('Cieplik206\\IntegrationOperations')
            ->and($contents)->not->toContain('Illuminate\\')
            ->and($contents)->not->toContain('Cieplik206\\Fakturownia\\Stateful')
            ->and($contents)->not->toContain('Cieplik206\\Fakturownia\\Laravel');
    }
});

it('keeps Stateful independent from Laravel adapters', function (): void {
    foreach (phpFiles(packageRoot().'/src/Stateful') as $file) {
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse()
            ->and($contents)->not->toContain('Illuminate\\')
            ->and($contents)->not->toContain('Cieplik206\\Fakturownia\\Laravel');
    }
});

it('does not import PMS models or application namespaces', function (): void {
    foreach (phpFiles(packageRoot().'/src') as $file) {
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse()
            ->and($contents)->not->toContain('namespace App\\')
            ->and($contents)->not->toContain('use App\\')
            ->and($contents)->not->toContain('App\\Models\\');
    }
});

it('owns the exact package migrations without ConnectionKey duplication or boot side effects', function (): void {
    $provider = file_get_contents(packageRoot().'/src/Laravel/FakturowniaServiceProvider.php');
    $connectionKeyDeclarations = [];
    $migrations = glob(packageRoot().'/database/migrations/*.php');

    foreach (phpFiles(packageRoot().'/src') as $file) {
        $contents = file_get_contents($file);

        if (is_string($contents)
            && preg_match('/\b(?:class|enum|interface|trait)\s+ConnectionKey\b/', $contents) === 1) {
            $connectionKeyDeclarations[] = $file;
        }
    }

    expect($connectionKeyDeclarations)->toBe([])
        ->and($migrations)->toBe([
            packageRoot().'/database/migrations/2026_08_26_000001_create_fakturownia_artifacts_table.php',
            packageRoot().'/database/migrations/2026_08_26_000002_create_fakturownia_webhook_receipts_table.php',
            packageRoot().'/database/migrations/2026_08_26_000003_create_fakturownia_resources_table.php',
            packageRoot().'/database/migrations/2026_08_26_000004_create_fakturownia_sync_checkpoints_table.php',
        ])
        ->and(hash_file('sha256', packageRoot().'/database/migrations/2026_08_26_000001_create_fakturownia_artifacts_table.php'))
        ->toBe('b757e6005b4c30bb1e53c5bb6130bdd280cc4367b0d9f07c64b89ff56a9f12ad')
        ->and(hash_file('sha256', packageRoot().'/database/migrations/2026_08_26_000002_create_fakturownia_webhook_receipts_table.php'))
        ->toBe('2c5190e04525bab7f796128cbd562419a19613c861426ec3ffb7cc5309f28749')
        ->and(hash_file('sha256', packageRoot().'/database/migrations/2026_08_26_000003_create_fakturownia_resources_table.php'))
        ->toBe('6cb9fb9ec2ea7adc21016f6007fe1f44d15b0958cfbe26871071dbacc0dfcee8')
        ->and(hash_file('sha256', packageRoot().'/database/migrations/2026_08_26_000004_create_fakturownia_sync_checkpoints_table.php'))
        ->toBe('8879741084aae47b441d4a735bf5a2a0d5b764ce8e824ea8dbea945e7ee40481')
        ->and($provider)->not->toBeFalse()
        ->and($provider)->toContain("\$this->loadMigrationsFrom(__DIR__.'/../../database/migrations');")
        ->and($provider)->not->toContain('Http::')
        ->and($provider)->not->toContain('DB::')
        ->and($provider)->not->toContain('Queue::');
});

it('declares the renamed package discovery and kernel dependency', function (): void {
    $contents = file_get_contents(packageRoot().'/composer.json');
    $composer = is_string($contents) ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : null;

    expect($composer)->toBeArray()
        ->and($composer['name'] ?? null)->toBe('cieplik206/laravel-fakturownia')
        ->and($composer['replace']['cieplik206/laravel-fakturownia-client'] ?? null)->toBe('self.version')
        ->and($composer['require']['cieplik206/laravel-integration-operations'] ?? null)->toBe('^0.3')
        ->and($composer['extra']['laravel']['providers'] ?? [])->toContain(
            'Cieplik206\\Fakturownia\\Laravel\\FakturowniaServiceProvider',
        );
});

it('keeps the complete public and Saloon dispatch surface closed behind the RT-3 gates', function (): void {
    $matrix = capabilityMatrix();
    $defaultPolicy = $matrix['default_policy'] ?? null;
    $publicApiGate = is_array($defaultPolicy) ? ($defaultPolicy['public_api_gate'] ?? null) : null;
    $methodInventory = publicMethodInventory();
    $completeNonRemoteInventory = [
        ...array_fill_keys(localOnlyPublicMethods(), 'local_kernel'),
        ...$methodInventory,
    ];
    $violations = (new RemoteApiArchitectureInspector)->inspect(
        phpFiles(packageRoot().'/src'),
        capabilityStatuses($matrix),
        $completeNonRemoteInventory,
        allowedExternalParents(),
    );
    $normalizedViolations = normalizedRemoteApiViolations($violations);
    $classificationCounts = array_count_values($methodInventory);
    ksort($classificationCounts);

    expect(RequiresCapability::class)->toBeClass()
        ->and($publicApiGate)->toBe('required_live_evidence_must_be_passed')
        ->and($normalizedViolations)->toBe(expectedRemoteApiViolations())
        ->and(publicMethodInventoryErrors($completeNonRemoteInventory))->toBe([])
        ->and(dynamicToolingCallSiteErrors($methodInventory))->toBe([])
        ->and($classificationCounts)->toBe([
            'capability_contract:invoice.correction.issue' => 29,
            'capability_contract:invoice.ksef.ensure_accepted' => 39,
            'capability_contract:invoice.pdf.download' => 123,
            'capability_contract:invoice.vat.issue' => 151,
            'contract_tooling' => 116,
            'deferred_capability_contract:invoice.proforma.issue' => 9,
            'deferred_capability_contract:webhook.invoice.receive' => 56,
            'internal_read_boundary' => 20,
            'local_kernel' => 6,
            'local_shadow' => 57,
            'read_contract' => 200,
            'read_facade' => 18,
            'testing_no_io' => 48,
        ])
        ->and(hash_file('sha256', publicMethodInventoryPath()))
        ->toBe('d291004dd5c5d29cfff5adc96bac6469a4eac4b393c6f707251fcb0224e0df71');

    $statuses = capabilityStatuses($matrix);

    foreach (capabilitiesReferencedByPublicMethodInventory($methodInventory) as $capability) {
        expect($statuses[$capability] ?? null)->toBe('pending_implementation');
    }

    foreach (deferredCapabilitiesReferencedByPublicMethodInventory($methodInventory) as $capability) {
        expect($statuses[$capability] ?? null)->toBe('deferred');
    }

    foreach (expectedPendingRemoteCapabilities() as $capability) {
        expect($statuses[$capability] ?? null)->toBe('pending_implementation');
    }

    foreach (expectedDeferredRemoteCapabilities() as $capability) {
        expect($statuses[$capability] ?? null)->toBe('deferred');
    }
});

it('rejects future resource inherited request magic callable and dispatch graph bypasses', function (): void {
    $fixture = packageRoot().'/tests/Fixtures/Architecture/remote-api-bypass.php.fixture';
    $violations = (new RemoteApiArchitectureInspector)->inspect(
        [$fixture],
        ['invoice.read.list' => 'passed'],
        [],
        [],
    );
    $report = implode("\n", $violations);

    expect($report)
        ->toContain('rt3_remote_request_security_boundary_not_implemented')
        ->toContain('public_method_not_classified Cieplik206\\Fakturownia\\Stateful\\Resources\\Invoices::list')
        ->toContain('request_not_final Cieplik206\\Fakturownia\\Client\\Requests\\MutableRequest')
        ->toContain('request_overrides_secure_endpoint Cieplik206\\Fakturownia\\Client\\Requests\\MutableRequest')
        ->toContain('request_not_direct_secure_child Cieplik206\\Fakturownia\\Client\\Requests\\StandaloneRequest')
        ->toContain('dispatch_request_gate_mismatch Cieplik206\\Fakturownia\\Stateful\\Resources\\Invoices::dispatch')
        ->toContain('unreviewable_magic_method Cieplik206\\Fakturownia\\Stateful\\Resources\\Invoices::__call')
        ->toContain('dynamic_method_call Cieplik206\\Fakturownia\\Stateful\\Resources\\Invoices::dynamicDispatch')
        ->toContain('dispatch_callable Cieplik206\\Fakturownia\\Stateful\\Resources\\Invoices::transportCallable')
        ->toContain('unsafe_public_transport_surface Cieplik206\\Fakturownia\\Stateful\\Resources\\Invoices::connector return Saloon\\Http\\Connector');
});

function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return list<string> */
function phpFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

/** @return array<string, mixed> */
function capabilityMatrix(): array
{
    $contents = file_get_contents(packageRoot().'/docs/capability-matrix.json');

    if (! is_string($contents)) {
        return [];
    }

    $matrix = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    return is_array($matrix) ? $matrix : [];
}

/**
 * @param  array<string, mixed>  $matrix
 * @return array<string, string>
 */
function capabilityStatuses(array $matrix): array
{
    $statuses = [];
    $capabilities = $matrix['capabilities'] ?? null;

    if (! is_array($capabilities)) {
        return $statuses;
    }

    foreach ($capabilities as $capability) {
        if (! is_array($capability) || ! is_string($capability['id'] ?? null)) {
            continue;
        }

        $liveEvidence = $capability['live_evidence'] ?? null;
        $status = is_array($liveEvidence) ? ($liveEvidence['status'] ?? null) : null;

        if (is_string($status)) {
            $statuses[$capability['id']] = $status;
        }
    }

    return $statuses;
}

function publicMethodInventoryPath(): string
{
    return packageRoot().'/tests/Fixtures/Architecture/public-method-inventory.json';
}

/** @return array<string, string> */
function publicMethodInventory(): array
{
    $contents = file_get_contents(publicMethodInventoryPath());

    if (! is_string($contents)) {
        return [];
    }

    $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($document)
        || array_keys($document) !== ['contract', 'version', 'methods']
        || $document['contract'] !== 'cieplik206.fakturownia.public-method-inventory'
        || $document['version'] !== '0.1'
        || ! is_array($document['methods'])) {
        return [];
    }

    $methods = [];

    foreach ($document['methods'] as $method => $classification) {
        if (! is_string($method) || ! is_string($classification)) {
            return [];
        }

        $methods[$method] = $classification;
    }

    return $methods;
}

/**
 * @param  array<string, string>  $inventory
 * @return list<string>
 */
function publicMethodInventoryErrors(array $inventory): array
{
    $allowedClassifications = [
        'capability_contract:invoice.correction.issue',
        'capability_contract:invoice.ksef.ensure_accepted',
        'capability_contract:invoice.pdf.download',
        'capability_contract:invoice.vat.issue',
        'contract_tooling',
        'deferred_capability_contract:invoice.proforma.issue',
        'deferred_capability_contract:webhook.invoice.receive',
        'internal_read_boundary',
        'local_kernel',
        'local_shadow',
        'read_contract',
        'read_facade',
        'testing_no_io',
    ];
    $errors = [];

    foreach ($inventory as $methodKey => $classification) {
        if (! in_array($classification, $allowedClassifications, true)) {
            $errors[] = "unknown_classification {$methodKey} {$classification}";
        }

        if (preg_match('/^(?<class>[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::(?<method>[A-Za-z_][A-Za-z0-9_]*)$/D', $methodKey, $matches) !== 1) {
            $errors[] = "invalid_method_key {$methodKey}";

            continue;
        }

        $class = $matches['class'];
        $method = $matches['method'];

        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
            $errors[] = "missing_type {$methodKey}";

            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->hasMethod($method)) {
            $errors[] = "missing_method {$methodKey}";

            continue;
        }

        $reflectionMethod = $reflection->getMethod($method);

        if (! $reflectionMethod->isPublic()) {
            $errors[] = "method_not_public {$methodKey}";
        }

        if ($reflectionMethod->getDeclaringClass()->getName() !== $class) {
            $errors[] = "method_not_declared_by_inventory_type {$methodKey}";
        }
    }

    return $errors;
}

/**
 * @param  array<string, string>  $inventory
 * @return list<string>
 */
function dynamicToolingCallSiteErrors(array $inventory): array
{
    $errors = [];

    foreach (expectedDynamicToolingCallSites() as $method => $callSite) {
        if (($inventory[$method] ?? null) !== 'contract_tooling') {
            $errors[] = "dynamic_call_not_contract_tooling {$method}";
        }

        $path = packageRoot().'/'.$callSite['path'];

        if (! is_file($path) || is_link($path)) {
            $errors[] = "dynamic_call_file_not_regular {$method}";

            continue;
        }

        if (hash_file('sha256', $path) !== $callSite['sha256']) {
            $errors[] = "dynamic_call_source_hash_mismatch {$method}";
        }
    }

    return $errors;
}

/**
 * @return array<string, array{path: string, sha256: string}>
 */
function expectedDynamicToolingCallSites(): array
{
    return [
        'Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\PendingLiteralRemoteConsumptionClaim::completeLiteralNow' => [
            'path' => 'src/ContractTesting/LiveEvidence/PendingLiteralRemoteConsumptionClaim.php',
            'sha256' => '20551507850567048cd3e35985d37a552d62f45b402ba254f4d9c492d17fbb73',
        ],
        'Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\RemoteConsumptionCoordinator::beginLiteralInMemoryClaimNow' => [
            'path' => 'src/ContractTesting/LiveEvidence/RemoteConsumptionCoordinator.php',
            'sha256' => 'cfef60d85594181ec88051b14e55bb277a016555d7e32843291386ddbdd757e2',
        ],
    ];
}

/**
 * @param  array<string, string>  $inventory
 * @return list<string>
 */
function capabilitiesReferencedByPublicMethodInventory(array $inventory): array
{
    $capabilities = [];

    foreach ($inventory as $classification) {
        if (! str_starts_with($classification, 'capability_contract:')) {
            continue;
        }

        $capabilities[] = substr($classification, strlen('capability_contract:'));
    }

    $capabilities = array_values(array_unique($capabilities));
    sort($capabilities);

    return $capabilities;
}

/**
 * @param  array<string, string>  $inventory
 * @return list<string>
 */
function deferredCapabilitiesReferencedByPublicMethodInventory(array $inventory): array
{
    $capabilities = [];
    $prefix = 'deferred_capability_contract:';

    foreach ($inventory as $classification) {
        if (! str_starts_with($classification, $prefix)) {
            continue;
        }

        $capabilities[] = substr($classification, strlen($prefix));
    }

    $capabilities = array_values(array_unique($capabilities));
    sort($capabilities);

    return $capabilities;
}

/** @return array<string, string> */
function expectedPendingRemoteCapabilities(): array
{
    return [
        'Cieplik206\\Fakturownia\\Read\\Resources\\ClientsResource::get' => 'client.read.get',
        'Cieplik206\\Fakturownia\\Read\\Resources\\ClientsResource::list' => 'client.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\ClientsResource::stream' => 'client.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\InvoicesResource::attachmentsZip' => 'invoice.attachments.zip.stream',
        'Cieplik206\\Fakturownia\\Read\\Resources\\InvoicesResource::get' => 'invoice.read.get',
        'Cieplik206\\Fakturownia\\Read\\Resources\\InvoicesResource::ksefUpo' => 'invoice.ksef.upo.stream',
        'Cieplik206\\Fakturownia\\Read\\Resources\\InvoicesResource::ksefXml' => 'invoice.ksef.xml.stream',
        'Cieplik206\\Fakturownia\\Read\\Resources\\InvoicesResource::list' => 'invoice.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\InvoicesResource::listByExactOid' => 'invoice.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\InvoicesResource::pdf' => 'invoice.pdf.stream',
        'Cieplik206\\Fakturownia\\Read\\Resources\\InvoicesResource::stream' => 'invoice.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\InvoicesResource::streamByExactOid' => 'invoice.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\PaymentsResource::get' => 'payment.read.get',
        'Cieplik206\\Fakturownia\\Read\\Resources\\PaymentsResource::list' => 'payment.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\PaymentsResource::stream' => 'payment.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\ProductsResource::get' => 'product.read.get',
        'Cieplik206\\Fakturownia\\Read\\Resources\\ProductsResource::list' => 'product.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\ProductsResource::stream' => 'product.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\ProformasResource::get' => 'invoice.read.get',
        'Cieplik206\\Fakturownia\\Read\\Resources\\ProformasResource::list' => 'invoice.read.list',
        'Cieplik206\\Fakturownia\\Read\\Resources\\ProformasResource::stream' => 'invoice.read.list',
    ];
}

/** @return array<string, string> */
function expectedDeferredRemoteCapabilities(): array
{
    return [
        'Cieplik206\\Fakturownia\\Read\\Resources\\AccountInvoicesResource::list' => 'account.invoice.read',
    ];
}

/** @return list<string> */
function expectedRemoteApiViolations(): array
{
    $violations = [];

    foreach (expectedDynamicToolingCallSites() as $method => $_callSite) {
        $violations[] = "dynamic_function_call {$method}";
    }

    foreach (expectedPendingRemoteCapabilities() as $method => $capability) {
        $violations[] = "capability_not_passed {$method} {$capability}";
    }

    foreach (expectedDeferredRemoteCapabilities() as $method => $capability) {
        $violations[] = "capability_not_passed {$method} {$capability}";
    }

    sort($violations);

    return $violations;
}

/**
 * @param  list<string>  $violations
 * @return list<string>
 */
function normalizedRemoteApiViolations(array $violations): array
{
    $normalized = [];

    foreach ($violations as $violation) {
        if (preg_match('/^(capability_not_passed \\S+ \\S+) \\S+:\\d+$/D', $violation, $matches) === 1
            || preg_match('/^(dynamic_function_call \\S+) \\S+:\\d+$/D', $violation, $matches) === 1) {
            $normalized[] = $matches[1];

            continue;
        }

        $normalized[] = $violation;
    }

    sort($normalized);

    return $normalized;
}

/** @return list<class-string> */
function allowedExternalParents(): array
{
    return [
        Command::class,
        DatabaseLock::class,
        Facade::class,
        ServiceProvider::class,
        RuntimeException::class,
    ];
}

/** @return list<string> */
function localOnlyPublicMethods(): array
{
    return [
        'Cieplik206\\Fakturownia\\Client\\Attributes\\RequiresCapability::__construct',
        'Cieplik206\\Fakturownia\\Client\\ConnectionConfig::__clone',
        'Cieplik206\\Fakturownia\\Client\\ConnectionConfig::__construct',
        'Cieplik206\\Fakturownia\\Client\\ConnectionConfig::__debugInfo',
        'Cieplik206\\Fakturownia\\Client\\ConnectionConfig::__serialize',
        'Cieplik206\\Fakturownia\\Client\\ConnectionConfig::__unserialize',
        'Cieplik206\\Fakturownia\\Client\\ConnectionConfig::baseUrl',
        'Cieplik206\\Fakturownia\\Client\\ConnectionConfig::createClient',
        'Cieplik206\\Fakturownia\\Client\\ConnectionConfig::jsonSerialize',
        'Cieplik206\\Fakturownia\\Client\\Contracts\\ClientFactory::make',
        'Cieplik206\\Fakturownia\\Client\\DefaultClientFactory::make',
        'Cieplik206\\Fakturownia\\Client\\FakturowniaClient::__clone',
        'Cieplik206\\Fakturownia\\Client\\FakturowniaClient::__construct',
        'Cieplik206\\Fakturownia\\Client\\FakturowniaClient::__debugInfo',
        'Cieplik206\\Fakturownia\\Client\\FakturowniaClient::__serialize',
        'Cieplik206\\Fakturownia\\Client\\FakturowniaClient::__unserialize',
        'Cieplik206\\Fakturownia\\Client\\FakturowniaClient::jsonSerialize',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\BaseUrl::__toString',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\BaseUrl::equals',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\BaseUrl::fromString',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\BaseUrl::host',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\SecretValue::__clone',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\SecretValue::__debugInfo',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\SecretValue::__serialize',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\SecretValue::__unserialize',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\SecretValue::createClient',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\SecretValue::fromPlaintext',
        'Cieplik206\\Fakturownia\\Client\\ValueObjects\\SecretValue::jsonSerialize',
        'Cieplik206\\Fakturownia\\Laravel\\ArtisanConfigurationPublisher::__clone',
        'Cieplik206\\Fakturownia\\Laravel\\ArtisanConfigurationPublisher::__construct',
        'Cieplik206\\Fakturownia\\Laravel\\ArtisanConfigurationPublisher::__debugInfo',
        'Cieplik206\\Fakturownia\\Laravel\\ArtisanConfigurationPublisher::__serialize',
        'Cieplik206\\Fakturownia\\Laravel\\ArtisanConfigurationPublisher::__unserialize',
        'Cieplik206\\Fakturownia\\Laravel\\ArtisanConfigurationPublisher::jsonSerialize',
        'Cieplik206\\Fakturownia\\Laravel\\ArtisanConfigurationPublisher::publish',
        'Cieplik206\\Fakturownia\\Laravel\\ConfigConnectionResolver::__clone',
        'Cieplik206\\Fakturownia\\Laravel\\ConfigConnectionResolver::__construct',
        'Cieplik206\\Fakturownia\\Laravel\\ConfigConnectionResolver::__debugInfo',
        'Cieplik206\\Fakturownia\\Laravel\\ConfigConnectionResolver::__serialize',
        'Cieplik206\\Fakturownia\\Laravel\\ConfigConnectionResolver::__unserialize',
        'Cieplik206\\Fakturownia\\Laravel\\ConfigConnectionResolver::jsonSerialize',
        'Cieplik206\\Fakturownia\\Laravel\\ConfigConnectionResolver::resolve',
        'Cieplik206\\Fakturownia\\Laravel\\DeferredOperationQuery::__construct',
        'Cieplik206\\Fakturownia\\Laravel\\DeferredOperationQuery::within',
        'Cieplik206\\Fakturownia\\Laravel\\Console\\InstallFakturowniaCommand::__construct',
        'Cieplik206\\Fakturownia\\Laravel\\Console\\InstallFakturowniaCommand::handle',
        'Cieplik206\\Fakturownia\\Laravel\\Contracts\\ConfigurationPublisher::publish',
        'Cieplik206\\Fakturownia\\Laravel\\FakturowniaServiceProvider::boot',
        'Cieplik206\\Fakturownia\\Laravel\\FakturowniaServiceProvider::register',
        'Cieplik206\\Fakturownia\\Stateful\\ConnectionProfile::__clone',
        'Cieplik206\\Fakturownia\\Stateful\\ConnectionProfile::__construct',
        'Cieplik206\\Fakturownia\\Stateful\\ConnectionProfile::__debugInfo',
        'Cieplik206\\Fakturownia\\Stateful\\ConnectionProfile::__serialize',
        'Cieplik206\\Fakturownia\\Stateful\\ConnectionProfile::__unserialize',
        'Cieplik206\\Fakturownia\\Stateful\\ConnectionProfile::createClient',
        'Cieplik206\\Fakturownia\\Stateful\\ConnectionProfile::jsonSerialize',
        'Cieplik206\\Fakturownia\\Stateful\\ConnectionProfile::key',
        'Cieplik206\\Fakturownia\\Stateful\\Contracts\\ConnectionResolver::resolve',
        'Cieplik206\\Fakturownia\\Stateful\\Exceptions\\ConnectionConfigurationInvalid::__construct',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaConnection::__clone',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaConnection::__construct',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaConnection::__debugInfo',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaConnection::__serialize',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaConnection::__unserialize',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaConnection::jsonSerialize',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaConnection::key',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaOperations::__construct',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaManager::__clone',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaManager::__construct',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaManager::__debugInfo',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaManager::__serialize',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaManager::__unserialize',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaManager::connection',
        'Cieplik206\\Fakturownia\\Stateful\\FakturowniaManager::jsonSerialize',
    ];
}
