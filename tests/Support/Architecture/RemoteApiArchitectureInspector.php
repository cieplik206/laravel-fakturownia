<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Support\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RuntimeException;

final class RemoteApiArchitectureInspector
{
    /** Legacy adversarial fixture base retained to prove old Saloon request bypasses fail. */
    public const SecureRequest = 'Cieplik206\\Fakturownia\\Client\\Requests\\SecureFakturowniaRequest';

    public const JsonReadRequest = 'Cieplik206\\Fakturownia\\Read\\Requests\\JsonReadRequest';

    public const StreamReadRequest = 'Cieplik206\\Fakturownia\\Read\\Requests\\StreamReadRequest';

    private const InMemoryReadExecutor = 'Cieplik206\\Fakturownia\\Client\\ReadTransport\\Testing\\InMemorySaloonReadRequestExecutor';

    private const InMemoryExchange = 'Cieplik206\\Fakturownia\\Client\\ReadTransport\\Testing\\InMemorySaloonExchange';

    private const InMemorySender = 'Cieplik206\\Fakturownia\\Client\\ReadTransport\\Testing\\InMemorySaloonSender';

    private const PinnedCapabilityGate = 'Cieplik206\\Fakturownia\\Client\\ReadTransport\\PinnedReadCapabilityGate';

    private const ProductionReadExecutor = 'Cieplik206\\Fakturownia\\Client\\ReadTransport\\SealedSaloonReadRequestExecutor';

    private const SealedReadTransport = 'Cieplik206\\Fakturownia\\Client\\ReadTransport\\ExecutesSealedSaloonReads';

    private const LaravelFacade = 'Illuminate\\Support\\Facades\\Facade';

    private const FakturowniaFacade = 'Cieplik206\\Fakturownia\\Laravel\\Facades\\Fakturownia';

    private const FakturowniaManager = 'Cieplik206\\Fakturownia\\Stateful\\FakturowniaManager';

    private const ReadResource = 'Cieplik206\\Fakturownia\\Read\\Resources\\ReadResource';

    private const RetryingReadExecutor = 'Cieplik206\\Fakturownia\\Read\\Retry\\RetryingReadRequestExecutor';

    private const FindInvoicesByExactOidRequest = 'Cieplik206\\Fakturownia\\Read\\Requests\\FindInvoicesByExactOidRequest';

    private const Pagination = 'Cieplik206\\Fakturownia\\Read\\ValueObjects\\Pagination';

    private const ReadCapability = 'Cieplik206\\Fakturownia\\Read\\ValueObjects\\ReadCapability';

    /** @var array<string, string> */
    private const ReadCapabilityCases = [
        'AccountInvoiceList' => 'account.invoice.read',
        'ClientGet' => 'client.read.get',
        'ClientList' => 'client.read.list',
        'InvoiceAttachmentsZipStream' => 'invoice.attachments.zip.stream',
        'InvoiceGet' => 'invoice.read.get',
        'InvoiceKsefUpoStream' => 'invoice.ksef.upo.stream',
        'InvoiceKsefXmlStream' => 'invoice.ksef.xml.stream',
        'InvoiceList' => 'invoice.read.list',
        'InvoicePdfStream' => 'invoice.pdf.stream',
        'PaymentGet' => 'payment.read.get',
        'PaymentList' => 'payment.read.list',
        'ProductGet' => 'product.read.get',
        'ProductList' => 'product.read.list',
    ];

    private const SaloonConnector = 'Saloon\\Http\\Connector';

    private const SaloonPendingRequest = 'Saloon\\Http\\PendingRequest';

    private const SaloonRequest = 'Saloon\\Http\\Request';

    /** @var list<string> */
    private const DispatchMethods = [
        'creatependingrequest',
        'pool',
        'send',
        'sendandretry',
        'sendasync',
    ];

    /** @var list<string> */
    private const AlternateNetworkClientTypes = [
        'Amp\\Http\\Client\\HttpClient',
        'Amp\\Http\\Client\\HttpClientBuilder',
        'GuzzleHttp\\Client',
        'GuzzleHttp\\ClientInterface',
        'Illuminate\\Http\\Client\\Factory',
        'Illuminate\\Http\\Client\\PendingRequest',
        'Illuminate\\Support\\Facades\\Http',
        'Psr\\Http\\Client\\ClientInterface',
        'React\\Http\\Browser',
        'SoapClient',
        'Symfony\\Component\\HttpClient\\CurlHttpClient',
        'Symfony\\Component\\HttpClient\\NativeHttpClient',
        'Symfony\\Contracts\\HttpClient\\HttpClientInterface',
    ];

    /** @var list<string> */
    private const AlternateNetworkFunctions = [
        'dns_get_record',
        'fsockopen',
        'get_headers',
        'pfsockopen',
    ];

    /** @var list<string> */
    private const ProcessExecutionFunctions = [
        'exec',
        'passthru',
        'pcntl_exec',
        'popen',
        'proc_open',
        'shell_exec',
        'system',
    ];

    /** @var list<string> */
    private const DangerousReflectionTypes = [
        'ReflectionClass',
        'ReflectionFunction',
        'ReflectionFunctionAbstract',
        'ReflectionMethod',
        'ReflectionObject',
        'ReflectionProperty',
    ];

    /** @var list<string> */
    private const DangerousInvocationMethods = [
        'bind',
        'bindto',
        'call',
        'getclosure',
        'invoke',
        'invokeargs',
        'newinstancewithoutconstructor',
        'setaccessible',
        'setvalue',
    ];

    /** @var array<string, array<string, string>> */
    private const PinnedExecutionEscapeCallsites = [
        'Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\PendingLiteralRemoteConsumptionClaim::completeLiteralNow' => [
            'method:bind' => '20551507850567048cd3e35985d37a552d62f45b402ba254f4d9c492d17fbb73',
        ],
        'Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\RemoteConsumptionCoordinator::beginLiteralInMemoryClaimNow' => [
            'method:bind' => 'cfef60d85594181ec88051b14e55bb277a016555d7e32843291386ddbdd757e2',
        ],
        'Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\SaloonRuntimeIsolationGuard::assertIsolated' => [
            'new:ReflectionClass' => '3fa16c7fe2357d4358d0d854db10262cc9e9615545534a3ba3e8130a98476a4e',
        ],
        'Cieplik206\\Fakturownia\\Laravel\\ArtisanConfigurationPublisher::publish' => [
            'method:call' => 'b545e1aed65d1b2e393c3d6a16621eb0412776913ae32033d8e68edfa558ff96',
        ],
    ];

    /** @var list<string> */
    private const AlternateNetworkDispatchMethods = [
        'request',
        'sendrequest',
    ];

    /** @var list<string> */
    private const NetworkStreamWrapperFunctions = [
        'copy',
        'file',
        'file_get_contents',
        'fopen',
        'hash_file',
        'readfile',
        'simplexml_load_file',
    ];

    /** @var array<string, array<string, string>> */
    private const PinnedLocalStreamWrapperCallsites = [
        'Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\PinnedRepositorySnapshotReader::read' => [
            'fopen' => '065866aac950e57468716a78a0c9f217e478100c837704e3920d038fdb72ca9c',
        ],
    ];

    /** @var list<string> */
    private const ForwardingFunctions = [
        'array_diff_uassoc',
        'array_diff_ukey',
        'array_intersect_uassoc',
        'array_intersect_ukey',
        'array_udiff',
        'array_udiff_assoc',
        'array_udiff_uassoc',
        'array_uintersect',
        'array_uintersect_assoc',
        'array_uintersect_uassoc',
        'call_user_func',
        'call_user_func_array',
        'forward_static_call',
        'forward_static_call_array',
        'header_register_callback',
        'ob_start',
        'pcntl_signal',
        'preg_replace_callback_array',
        'register_tick_function',
        'session_set_save_handler',
        'spl_autoload_register',
    ];

    /**
     * @var array<string, array{index: int, optional: bool, nullable: bool}>
     */
    private const CallbackFunctionArguments = [
        'array_filter' => ['index' => 1, 'optional' => true, 'nullable' => true],
        'array_map' => ['index' => 0, 'optional' => false, 'nullable' => true],
        'array_reduce' => ['index' => 1, 'optional' => false, 'nullable' => false],
        'array_walk' => ['index' => 1, 'optional' => false, 'nullable' => false],
        'array_walk_recursive' => ['index' => 1, 'optional' => false, 'nullable' => false],
        'iterator_apply' => ['index' => 1, 'optional' => false, 'nullable' => false],
        'preg_replace_callback' => ['index' => 1, 'optional' => false, 'nullable' => false],
        'register_shutdown_function' => ['index' => 0, 'optional' => false, 'nullable' => false],
        'set_error_handler' => ['index' => 0, 'optional' => false, 'nullable' => true],
        'set_exception_handler' => ['index' => 0, 'optional' => false, 'nullable' => true],
        'uasort' => ['index' => 1, 'optional' => false, 'nullable' => false],
        'uksort' => ['index' => 1, 'optional' => false, 'nullable' => false],
        'usort' => ['index' => 1, 'optional' => false, 'nullable' => false],
    ];

    /** @var list<string> */
    private const UnsafePublicTypes = [
        'callable',
        'Closure',
        'mixed',
        'object',
        self::SaloonConnector,
        self::SaloonPendingRequest,
        self::SaloonRequest,
    ];

    /**
     * @param  list<string>  $files
     * @param  array<string, string>  $capabilityStatuses
     * @param  array<string, string>  $publicMethodClassifications
     * @param  list<class-string>  $allowedExternalParents
     * @return list<string>
     */
    public function inspect(
        array $files,
        array $capabilityStatuses,
        array $publicMethodClassifications,
        array $allowedExternalParents,
    ): array {
        $classes = [];
        $methods = [];
        $methodNodes = [];
        $dispatchSites = [];
        $methodCalls = [];
        $pinnedGateMethods = [];
        $traitUses = [];
        $violations = [];

        foreach ($files as $file) {
            $collector = $this->collect($file);

            foreach ($collector->classes as $class) {
                if (array_key_exists($class->name, $classes)) {
                    $violations[] = "duplicate_class {$class->name} {$class->location()}";
                }

                $classes[$class->name] = $class;
            }

            foreach ($collector->methods as $method) {
                if (array_key_exists($method->key(), $methods)) {
                    $violations[] = "duplicate_method {$method->key()} {$method->location()}";
                }

                $methods[$method->key()] = $method;
            }

            foreach ($collector->methodNodes as $methodKey => $methodNode) {
                $methodNodes[$methodKey] = $methodNode;
            }

            array_push($dispatchSites, ...$collector->dispatchSites);
            array_push($methodCalls, ...$collector->methodCalls);
            array_push($pinnedGateMethods, ...$collector->pinnedGateMethods);
            array_push($traitUses, ...$collector->traitUses);
            array_push($violations, ...$collector->violations);
        }

        $calledMethods = [];

        foreach ($methodCalls as $methodCall) {
            if ($methodCall->caller === null) {
                continue;
            }

            $calledMethods[$methodCall->caller][$methodCall->method] = true;
        }

        $methodsWithPinnedGate = array_fill_keys($pinnedGateMethods, true);
        $usedTraits = [];

        foreach ($traitUses as $traitUse) {
            $adaptationFree = ! $traitUse->hasAdaptations;
            $usedTraits[$traitUse->class][$traitUse->trait] = ($usedTraits[$traitUse->class][$traitUse->trait] ?? true)
                && $adaptationFree;

            if ($traitUse->trait === self::SealedReadTransport && ! $adaptationFree) {
                $violations[] = "sealed_transport_trait_adaptation {$traitUse->class} {$traitUse->location()}";
            }
        }

        $externalParents = array_fill_keys($allowedExternalParents, true);
        $validGates = [];
        $referencedRequests = [];
        $fakturowniaFacade = $classes[self::FakturowniaFacade] ?? null;
        $hasSealedFakturowniaFacade = $fakturowniaFacade instanceof ProductionClassDeclaration
            && $this->isSealedFakturowniaFacade($fakturowniaFacade, $methodNodes);

        foreach ($classes as $class) {
            if ($class->parent === null || array_key_exists($class->parent, $classes)) {
                continue;
            }

            if ($class->parent === self::LaravelFacade
                && ($class->name !== self::FakturowniaFacade || ! $hasSealedFakturowniaFacade)) {
                $violations[] = "inherited_facade_dispatch_not_sealed {$class->name} {$class->location()}";
            }

            if ($class->name === self::SecureRequest && $class->parent === self::SaloonRequest) {
                continue;
            }

            if (! array_key_exists($class->parent, $externalParents)) {
                $violations[] = "external_parent_not_allowlisted {$class->name} extends {$class->parent} {$class->location()}";
            }
        }

        foreach ($methods as $method) {
            $isSealedFacadeMethod = $hasSealedFakturowniaFacade
                && $method->class === self::FakturowniaFacade
                && in_array($method->name, ['__callStatic', 'connection'], true);

            if (in_array($method->name, ['__call', '__callStatic'], true) && ! $isSealedFacadeMethod) {
                $violations[] = "unreviewable_magic_method {$method->key()} {$method->location()}";
            }

            if ($method->isPublic
                && ! $isSealedFacadeMethod
                && ! array_key_exists($method->key(), $publicMethodClassifications)
                && $method->key() !== self::SecureRequest.'::resolveEndpoint') {
                if (count($method->gates) !== 1) {
                    $violations[] = "public_method_not_classified {$method->key()} {$method->location()}";
                }
            }

            if ($method->gates === []) {
                continue;
            }

            if (count($method->gates) !== 1) {
                $violations[] = "capability_gate_count {$method->key()} {$method->location()}";

                continue;
            }

            $gate = $method->gates[0];

            if ($gate->capabilityId === null || $gate->requestClass === null) {
                $violations[] = "capability_gate_not_static {$method->key()} {$method->location()}";

                continue;
            }

            if (($capabilityStatuses[$gate->capabilityId] ?? null) !== 'passed') {
                $violations[] = "capability_not_passed {$method->key()} {$gate->capabilityId} {$method->location()}";
            }

            $isSealedDescriptor = $this->isSealedReadDescriptor($gate->requestClass, $classes, $methods);

            if (! $isSealedDescriptor
                && ! $this->isDescendantOf($gate->requestClass, self::SecureRequest, $classes)) {
                $violations[] = "gate_request_not_secure {$method->key()} {$gate->requestClass} {$method->location()}";
            }

            if ($isSealedDescriptor
                && $this->sealedDescriptorCapabilityId($gate->requestClass, $methodNodes) !== $gate->capabilityId) {
                $violations[] = "capability_descriptor_mismatch {$method->key()} {$gate->requestClass} {$method->location()}";
            }

            $validGates[$method->key()] = $gate;
            $referencedRequests[$gate->requestClass] = true;
        }

        $legacySaloonRequestClasses = [];
        $readDescriptorClasses = [];

        foreach ($classes as $class) {
            if ($class->name === self::SecureRequest
                || $this->isDescendantOf($class->name, self::SaloonRequest, $classes)) {
                $legacySaloonRequestClasses[$class->name] = $class;
            }

            if (in_array($class->parent, [self::JsonReadRequest, self::StreamReadRequest], true)) {
                $readDescriptorClasses[$class->name] = $class;
            }

            if ($class->name !== self::SecureRequest
                && $this->isDescendantOf($class->name, self::SaloonConnector, $classes)) {
                $violations[] = "named_connector_forbidden {$class->name} {$class->location()}";
            }
        }

        /**
         * Deliberate RT-1 release latch. RT-3 must replace this violation with
         * executable proofs that request overrides, redirects, TLS verification,
         * retries, query-token telemetry, and Saloon/Laravel debugging are sealed
         * before the first production request class is admitted.
         */
        if ($legacySaloonRequestClasses !== []) {
            $violations[] = 'rt3_remote_request_security_boundary_not_implemented';
        }

        if ($legacySaloonRequestClasses !== []) {
            $secureRequest = $classes[self::SecureRequest] ?? null;

            if (! $secureRequest instanceof ProductionClassDeclaration) {
                $violations[] = 'secure_request_base_missing';
            } else {
                if (! $secureRequest->isAbstract || $secureRequest->parent !== self::SaloonRequest) {
                    $violations[] = "secure_request_base_not_abstract_direct_child {$secureRequest->location()}";
                }

                $resolveEndpoint = $methods[self::SecureRequest.'::resolveEndpoint'] ?? null;

                if (! $resolveEndpoint instanceof ProductionMethodDeclaration
                    || ! $resolveEndpoint->isPublic
                    || ! $resolveEndpoint->isFinal) {
                    $violations[] = "secure_request_endpoint_not_final {$secureRequest->location()}";
                }
            }
        }

        foreach ([self::JsonReadRequest, self::StreamReadRequest] as $descriptorBase) {
            $base = $classes[$descriptorBase] ?? null;

            if (! $base instanceof ProductionClassDeclaration
                || ! $base->isAbstract
                || ! $base->isReadonly
                || $base->parent !== null) {
                $violations[] = "read_descriptor_base_not_sealed {$descriptorBase}";

                continue;
            }

            foreach (['operation', 'capability', 'path', 'query', 'safety', 'maximumResponseBytes', 'fingerprint'] as $methodName) {
                $descriptorMethod = $methods[$descriptorBase.'::'.$methodName] ?? null;

                if (! $descriptorMethod instanceof ProductionMethodDeclaration
                    || ! $descriptorMethod->isPublic
                    || ! $descriptorMethod->isFinal) {
                    $violations[] = "read_descriptor_method_not_final {$descriptorBase}::{$methodName} {$base->location()}";
                }
            }
        }

        foreach ($legacySaloonRequestClasses as $requestClass => $class) {
            if ($requestClass === self::SecureRequest) {
                continue;
            }

            if ($class->parent !== self::SecureRequest) {
                $violations[] = "request_not_direct_secure_child {$requestClass} {$class->location()}";
            }

            if (! $class->isFinal || $class->isAbstract) {
                $violations[] = "request_not_final {$requestClass} {$class->location()}";
            }

            if (array_key_exists($requestClass.'::resolveEndpoint', $methods)) {
                $violations[] = "request_overrides_secure_endpoint {$requestClass} {$class->location()}";
            }

            if (! array_key_exists($requestClass, $referencedRequests)) {
                $violations[] = "request_without_capability_entrypoint {$requestClass} {$class->location()}";
            }
        }

        foreach ($readDescriptorClasses as $requestClass => $class) {
            if (! $class->isFinal || $class->isAbstract || ! $class->isReadonly) {
                $violations[] = "read_descriptor_not_final_readonly {$requestClass} {$class->location()}";
            }

            foreach (['operation', 'capability', 'path', 'query', 'safety', 'maximumResponseBytes', 'fingerprint'] as $methodName) {
                if (array_key_exists($requestClass.'::'.$methodName, $methods)) {
                    $violations[] = "read_descriptor_overrides_sealed_method {$requestClass}::{$methodName} {$class->location()}";
                }
            }

            if (! array_key_exists($requestClass, $referencedRequests)) {
                $violations[] = "request_without_capability_entrypoint {$requestClass} {$class->location()}";
            }
        }

        foreach ($methodNodes as $methodKey => $methodNode) {
            $descriptorDispatches = $this->descriptorDispatchCalls($methodNode);
            $gate = $validGates[$methodKey] ?? null;

            if ($gate instanceof CapabilityGate) {
                if ($descriptorDispatches === []) {
                    if (! $this->hasExactDeferredResourceFlow($methodNode, $gate->requestClass)
                        && ! $this->hasExactHardDeniedCapability($methodNode, $gate->requestClass)) {
                        $violations[] = "capability_request_dispatch_missing {$methodKey} {$methodNode->getStartLine()}";
                    }

                    continue;
                }

                foreach ($descriptorDispatches as $descriptorDispatch) {
                    $requestClass = $this->descriptorClassForCall($methodNode, $descriptorDispatch);

                    if ($requestClass !== $gate->requestClass) {
                        $actual = $requestClass ?? 'dynamic';
                        $violations[] = "capability_request_dispatch_mismatch {$methodKey} {$gate->requestClass} {$actual} {$descriptorDispatch->getStartLine()}";
                    }
                }

                continue;
            }

            if ($descriptorDispatches === []) {
                continue;
            }

            if ($this->hasExactReadResourceBoundary($methodKey, $methodNode, $descriptorDispatches)
                || $this->hasExactRetryingReadDelegate($methodKey, $methodNode, $descriptorDispatches)) {
                continue;
            }

            foreach ($descriptorDispatches as $descriptorDispatch) {
                $violations[] = "descriptor_dispatch_not_gated {$methodKey} {$descriptorDispatch->getStartLine()}";
            }
        }

        foreach ($dispatchSites as $dispatchSite) {
            if ($this->isPinnedSealedTransportDispatch(
                $dispatchSite,
                $dispatchSites,
                $classes,
                $methods,
                $methodNodes,
                $usedTraits,
            ) || $this->isSealedInMemoryDispatch($dispatchSite, $classes)) {
                continue;
            }

            if ($dispatchSite->methodKey === null) {
                $violations[] = "dispatch_outside_named_method {$dispatchSite->kind} {$dispatchSite->location()}";

                continue;
            }

            $gate = $validGates[$dispatchSite->methodKey] ?? null;

            if (! $gate instanceof CapabilityGate) {
                $violations[] = "dispatch_method_not_gated {$dispatchSite->methodKey} {$dispatchSite->kind} {$dispatchSite->location()}";

                continue;
            }

            if ($dispatchSite->requestClass === null) {
                $violations[] = "dispatch_request_not_static {$dispatchSite->methodKey} {$dispatchSite->kind} {$dispatchSite->location()}";

                continue;
            }

            if ($gate->requestClass !== $dispatchSite->requestClass) {
                $violations[] = "dispatch_request_gate_mismatch {$dispatchSite->methodKey} {$gate->requestClass} {$dispatchSite->requestClass} {$dispatchSite->location()}";
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    public static function dispatchMethods(): array
    {
        return self::DispatchMethods;
    }

    /** @return list<string> */
    public static function forwardingFunctions(): array
    {
        return self::ForwardingFunctions;
    }

    /** @return array{index: int, optional: bool, nullable: bool}|null */
    public static function callbackFunctionArgument(string $function): ?array
    {
        return self::CallbackFunctionArguments[$function] ?? null;
    }

    /** @return list<string> */
    public static function unsafePublicTypes(): array
    {
        return self::UnsafePublicTypes;
    }

    /** @return list<string> */
    public static function alternateNetworkClientTypes(): array
    {
        return self::AlternateNetworkClientTypes;
    }

    /** @return list<string> */
    public static function alternateNetworkFunctions(): array
    {
        return self::AlternateNetworkFunctions;
    }

    /** @return list<string> */
    public static function alternateNetworkDispatchMethods(): array
    {
        return self::AlternateNetworkDispatchMethods;
    }

    /** @return list<string> */
    public static function processExecutionFunctions(): array
    {
        return self::ProcessExecutionFunctions;
    }

    /** @return list<string> */
    public static function dangerousReflectionTypes(): array
    {
        return self::DangerousReflectionTypes;
    }

    /** @return list<string> */
    public static function dangerousInvocationMethods(): array
    {
        return self::DangerousInvocationMethods;
    }

    public static function isPinnedExecutionEscapeCallsite(
        string $methodKey,
        string $operation,
        string $file,
    ): bool {
        $expectedFileHash = self::PinnedExecutionEscapeCallsites[$methodKey][$operation] ?? null;

        return is_string($expectedFileHash)
            && hash_file('sha256', $file) === $expectedFileHash;
    }

    /** @return list<string> */
    public static function networkStreamWrapperFunctions(): array
    {
        return self::NetworkStreamWrapperFunctions;
    }

    public static function isPinnedLocalStreamWrapperCallsite(
        string $methodKey,
        string $function,
        string $file,
    ): bool {
        $expectedFileHash = self::PinnedLocalStreamWrapperCallsites[$methodKey][$function] ?? null;

        return is_string($expectedFileHash)
            && hash_file('sha256', $file) === $expectedFileHash;
    }

    private function collect(string $file): RemoteApiNodeCollector
    {
        $contents = file_get_contents($file);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read architecture input: {$file}");
        }

        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents);

        if ($nodes === null) {
            throw new RuntimeException("Unable to parse architecture input: {$file}");
        }

        $resolver = new NodeTraverser;
        $resolver->addVisitor(new NameResolver);
        $nodes = $resolver->traverse($nodes);

        $collector = new RemoteApiNodeCollector($file);
        $traverser = new NodeTraverser;
        $traverser->addVisitor($collector);
        $traverser->traverse($nodes);

        return $collector;
    }

    /**
     * @param  array<string, ProductionClassDeclaration>  $classes
     */
    private function isDescendantOf(string $class, string $ancestor, array $classes): bool
    {
        $visited = [];
        $candidate = $class;

        while (isset($classes[$candidate]) && ! isset($visited[$candidate])) {
            $visited[$candidate] = true;
            $parent = $classes[$candidate]->parent;

            if ($parent === $ancestor) {
                return true;
            }

            if ($parent === null) {
                return false;
            }

            $candidate = $parent;
        }

        return false;
    }

    /**
     * @param  array<string, ProductionClassDeclaration>  $classes
     * @param  array<string, ProductionMethodDeclaration>  $methods
     */
    private function isSealedReadDescriptor(string $class, array $classes, array $methods): bool
    {
        $declaration = $classes[$class] ?? null;

        if (! $declaration instanceof ProductionClassDeclaration
            || ! in_array($declaration->parent, [self::JsonReadRequest, self::StreamReadRequest], true)
            || ! $declaration->isFinal
            || $declaration->isAbstract
            || ! $declaration->isReadonly) {
            return false;
        }

        foreach (['operation', 'capability', 'path', 'query', 'safety', 'maximumResponseBytes', 'fingerprint'] as $methodName) {
            if (array_key_exists($class.'::'.$methodName, $methods)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, Stmt\ClassMethod> $methodNodes */
    private function sealedDescriptorCapabilityId(string $requestClass, array $methodNodes): ?string
    {
        $constructor = $methodNodes[$requestClass.'::__construct'] ?? null;

        if (! $constructor instanceof Stmt\ClassMethod) {
            return null;
        }

        $parentCalls = array_values(array_filter(
            (new NodeFinder)->findInstanceOf($constructor, Expr\StaticCall::class),
            static fn (Expr\StaticCall $call): bool => $call->class instanceof Name
                && strtolower($call->class->toString()) === 'parent'
                && $call->name instanceof Identifier
                && strtolower($call->name->toString()) === '__construct',
        ));

        if (count($parentCalls) !== 1) {
            return null;
        }

        $parentCall = $parentCalls[0];
        $isTopLevel = false;

        foreach ($constructor->stmts ?? [] as $statement) {
            if ($statement instanceof Stmt\Expression && $statement->expr === $parentCall) {
                $isTopLevel = true;

                break;
            }
        }

        if (! $isTopLevel || count($parentCall->args) < 4) {
            return null;
        }

        $operation = $parentCall->args[0] ?? null;
        $capability = $parentCall->args[1] ?? null;

        if (! $operation instanceof Node\Arg
            || $operation->name !== null
            || $operation->byRef
            || $operation->unpack
            || ! $capability instanceof Node\Arg
            || $capability->name !== null
            || $capability->byRef
            || $capability->unpack) {
            return null;
        }

        $operationCase = $this->readCapabilityCaseFromOperation($operation->value);
        $capabilityCase = $this->readCapabilityCase($capability->value);

        if ($operationCase === null || $operationCase !== $capabilityCase) {
            return null;
        }

        return self::ReadCapabilityCases[$operationCase] ?? null;
    }

    private function readCapabilityCaseFromOperation(Expr $expression): ?string
    {
        return $expression instanceof Expr\PropertyFetch
            && $expression->name instanceof Identifier
            && strtolower($expression->name->toString()) === 'value'
                ? $this->readCapabilityCase($expression->var)
                : null;
    }

    private function readCapabilityCase(Expr $expression): ?string
    {
        if (! $expression instanceof Expr\ClassConstFetch
            || ! $expression->class instanceof Name
            || $this->resolvedNodeName($expression->class) !== self::ReadCapability
            || ! $expression->name instanceof Identifier) {
            return null;
        }

        return $expression->name->toString();
    }

    /**
     * @param  array<string, ProductionClassDeclaration>  $classes
     * @param  array<string, ProductionMethodDeclaration>  $methods
     * @param  array<string, Stmt\ClassMethod>  $methodNodes
     * @param  array<string, array<string, bool>>  $usedTraits
     * @param  array<array-key, DispatchSite>  $dispatchSites
     */
    private function isPinnedSealedTransportDispatch(
        DispatchSite $dispatchSite,
        array $dispatchSites,
        array $classes,
        array $methods,
        array $methodNodes,
        array $usedTraits,
    ): bool {
        $dispatchMethod = self::SealedReadTransport.'::dispatch';

        if ($dispatchSite->methodKey !== $dispatchMethod
            || ! in_array($dispatchSite->kind, ['creatependingrequest', 'send'], true)) {
            return false;
        }

        $executor = $classes[self::ProductionReadExecutor] ?? null;
        $transport = $classes[self::SealedReadTransport] ?? null;
        $pinnedGate = $classes[self::PinnedCapabilityGate] ?? null;

        if (! $executor instanceof ProductionClassDeclaration
            || $executor->kind !== 'class'
            || ! $executor->isFinal
            || ! $executor->isReadonly
            || ! $transport instanceof ProductionClassDeclaration
            || $transport->kind !== 'trait'
            || ! $pinnedGate instanceof ProductionClassDeclaration
            || ! $pinnedGate->isFinal
            || ! $pinnedGate->isReadonly
            || ($usedTraits[self::ProductionReadExecutor][self::SealedReadTransport] ?? false) !== true) {
            return false;
        }

        $requiredPrivateTransportMethods = [
            'assertTransportExecutionAuthorized',
            'dispatch',
            'dispatchStream',
            'executeSealedRead',
            'streamSealedRead',
            'transportRequest',
        ];

        foreach ($requiredPrivateTransportMethods as $methodName) {
            $method = $methods[self::SealedReadTransport.'::'.$methodName] ?? null;

            if (! $method instanceof ProductionMethodDeclaration || ! $method->isPrivate) {
                return false;
            }
        }

        foreach (['execute', 'stream'] as $methodName) {
            $method = $methods[self::ProductionReadExecutor.'::'.$methodName] ?? null;

            if (! $method instanceof ProductionMethodDeclaration || ! $method->isPublic) {
                return false;
            }
        }

        $execute = self::ProductionReadExecutor.'::execute';
        $stream = self::ProductionReadExecutor.'::stream';
        $sealedExecute = self::SealedReadTransport.'::executeSealedRead';
        $sealedStream = self::SealedReadTransport.'::streamSealedRead';
        $sealedDispatch = self::SealedReadTransport.'::dispatch';
        $transportAuthority = self::SealedReadTransport.'::assertTransportExecutionAuthorized';

        if (! $this->hasExactExecutorEntryFlow($methodNodes[$execute] ?? null, 'assertJsonRequestContract', 'executeSealedRead')
            || ! $this->hasExactExecutorEntryFlow($methodNodes[$stream] ?? null, 'assertStreamRequestContract', 'streamSealedRead')
            || ! $this->hasExactSealedEntryFlow($methodNodes[$sealedExecute] ?? null, 'assertJsonRequestContract', 'dispatch')
            || ! $this->hasExactSealedEntryFlow($methodNodes[$sealedStream] ?? null, 'assertStreamRequestContract', 'dispatchStream')
            || ! $this->hasExactDispatchPrelude($methodNodes[$sealedDispatch] ?? null)
            || ! $this->hasExactSealedDispatchSites($dispatchSites)
            || ! $this->hasExactTransportAuthority($methodNodes[$transportAuthority] ?? null)
            || ! $this->hasExactPinnedCapabilityGate($methodNodes[self::PinnedCapabilityGate.'::assertSupported'] ?? null)) {
            return false;
        }

        return true;
    }

    private function hasExactPinnedCapabilityGate(mixed $method): bool
    {
        if (! $method instanceof Stmt\ClassMethod
            || ! $method->isPublic()
            || $method->isStatic()
            || $this->parameterNames($method) !== ['capability']
            || count($method->stmts ?? []) !== 1) {
            return false;
        }

        $throw = $this->throwExpression($method->stmts[0]);

        return $throw?->expr instanceof Expr\New_
            && $throw->expr->class instanceof Name
            && $this->resolvedNodeName($throw->expr->class) === 'Cieplik206\\Fakturownia\\Read\\Exceptions\\UnsupportedCapability'
            && $this->argumentsMatch($throw->expr->args, [new Expr\Variable('capability')]);
    }

    /** @param array<array-key, DispatchSite> $dispatchSites */
    private function hasExactSealedDispatchSites(array $dispatchSites): bool
    {
        $sealed = array_values(array_filter(
            $dispatchSites,
            static fn (DispatchSite $site): bool => $site->methodKey === self::SealedReadTransport.'::dispatch',
        ));

        return count($sealed) === 2
            && $sealed[0]->kind === 'creatependingrequest'
            && $sealed[1]->kind === 'send';
    }

    /** @param array<string, ProductionClassDeclaration> $classes */
    private function isSealedInMemoryDispatch(DispatchSite $dispatchSite, array $classes): bool
    {
        if ($dispatchSite->methodKey !== self::InMemorySender.'::sendAsync'
            || $dispatchSite->kind !== 'send') {
            return false;
        }

        $sender = $classes[self::InMemorySender] ?? null;
        $executor = $classes[self::InMemoryReadExecutor] ?? null;
        $exchange = $classes[self::InMemoryExchange] ?? null;

        if (! $sender instanceof ProductionClassDeclaration
            || $sender->kind !== 'class'
            || ! $sender->isFinal
            || $sender->parent !== null
            || ! $executor instanceof ProductionClassDeclaration
            || ! $executor->isFinal
            || ! $executor->isReadonly
            || ! $exchange instanceof ProductionClassDeclaration
            || ! $exchange->isFinal
            || ! $exchange->isReadonly
            || $exchange->parent !== null) {
            return false;
        }

        $senderSource = file_get_contents($sender->file);
        $executorSource = file_get_contents($executor->file);
        $exchangeSource = file_get_contents($exchange->file);

        if (! is_string($senderSource)
            || ! is_string($executorSource)
            || ! is_string($exchangeSource)
            || ! str_contains($executorSource, "public const OriginHost = 'tenant.fakturownia.invalid'")
            || ! str_contains($executorSource, 'InMemorySaloonSender $sender')
            || ! str_contains($senderSource, 'list<InMemorySaloonExchange>')) {
            return false;
        }

        foreach (['new GuzzleSender', 'MockClient::', 'callable $', 'Closure $', 'use Closure;', 'curl_', 'fsockopen(', 'passthrough(', 'stream_socket_client('] as $forbidden) {
            if (str_contains($senderSource, $forbidden) || str_contains($exchangeSource, $forbidden)) {
                return false;
            }
        }

        return true;
    }

    private function hasExactExecutorEntryFlow(
        mixed $method,
        string $contractMethod,
        string $transportMethod,
    ): bool {
        if (! $method instanceof Stmt\ClassMethod
            || ! $this->hasSingleParameter($method, 'request')
            || count($method->stmts ?? []) !== 3) {
            return false;
        }

        $statements = $method->stmts;

        return $this->isThisCallStatement($statements[0], $contractMethod, [new Expr\Variable('request')])
            && $this->isPinnedGateStatement($statements[1], $this->requestCapabilityExpression())
            && $statements[2] instanceof Stmt\Return_
            && $this->isThisMethodCall($statements[2]->expr, $transportMethod, [new Expr\Variable('request')]);
    }

    private function hasExactSealedEntryFlow(
        mixed $method,
        string $contractMethod,
        string $transportMethod,
    ): bool {
        if (! $method instanceof Stmt\ClassMethod
            || ! $this->hasSingleParameter($method, 'request')
            || count($method->stmts ?? []) !== 3) {
            return false;
        }

        $statements = $method->stmts;

        return $this->isThisCallStatement($statements[0], $contractMethod, [new Expr\Variable('request')])
            && $this->isThisCallStatement($statements[1], 'assertTransportExecutionAuthorized', [$this->requestCapabilityExpression()])
            && $statements[2] instanceof Stmt\TryCatch
            && $this->containsThisMethodCall($statements[2], $transportMethod);
    }

    private function hasExactDispatchPrelude(mixed $method): bool
    {
        if (! $method instanceof Stmt\ClassMethod
            || $this->parameterNames($method) !== [
                'connector',
                'request',
                'operation',
                'expectedHost',
                'credentialsAttached',
                'capability',
            ]
            || count($method->stmts ?? []) !== 8) {
            return false;
        }

        $statements = $method->stmts;
        $pendingAssignment = $this->assignmentExpression($statements[5]);

        return $this->isThisCallStatement($statements[0], 'assertTransportExecutionAuthorized', [new Expr\Variable('capability')])
            && $this->isStaticCallStatement(
                $statements[1],
                'Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\SaloonRuntimeIsolationGuard',
                'assertIsolated',
            )
            && $this->assignmentVariableName($statements[2]) === 'expectedAuthenticator'
            && $this->isThisCallStatement($statements[3], 'assertConnectorState')
            && $this->isThisCallStatement($statements[4], 'assertTransportRequestState')
            && $pendingAssignment instanceof Expr\Assign
            && $pendingAssignment->var instanceof Expr\Variable
            && $pendingAssignment->var->name === 'pendingRequest'
            && $this->isVariableMethodCall(
                $pendingAssignment->expr,
                'connector',
                'createPendingRequest',
                [new Expr\Variable('request')],
            )
            && $this->isThisCallStatement($statements[6], 'assertPendingRequestState')
            && $statements[7] instanceof Stmt\TryCatch
            && $this->hasExactDispatchTry($statements[7]);
    }

    private function hasExactDispatchTry(Stmt\TryCatch $try): bool
    {
        if (count($try->stmts) !== 2 || count($try->catches) !== 2 || $try->finally !== null) {
            return false;
        }

        $responseAssignment = $this->assignmentExpression($try->stmts[0]);
        $return = $try->stmts[1];

        if (! $responseAssignment instanceof Expr\Assign
            || ! $responseAssignment->var instanceof Expr\Variable
            || $responseAssignment->var->name !== 'response'
            || ! $responseAssignment->expr instanceof Expr\MethodCall
            || ! $responseAssignment->expr->var instanceof Expr\MethodCall
            || ! $this->isThisMethodCall($responseAssignment->expr->var, 'sender', [])
            || ! $responseAssignment->expr->name instanceof Identifier
            || $responseAssignment->expr->name->toString() !== 'send'
            || ! $this->argumentsMatch($responseAssignment->expr->args, [new Expr\Variable('pendingRequest')])
            || ! $return instanceof Stmt\Return_
            || ! $return->expr instanceof Expr\MethodCall
            || ! $this->isVariableMethodCall(
                $return->expr,
                'pendingRequest',
                'executeResponsePipeline',
                [new Expr\Variable('response')],
            )) {
            return false;
        }

        [$readFailure, $rawFailure] = $try->catches;
        $readRethrow = $readFailure->stmts[0] ?? null;
        $rawRethrow = $rawFailure->stmts[0] ?? null;
        $readThrow = $readRethrow instanceof Stmt ? $this->throwExpression($readRethrow) : null;
        $rawThrow = $rawRethrow instanceof Stmt ? $this->throwExpression($rawRethrow) : null;

        return count($readFailure->types) === 1
            && $this->resolvedNodeName($readFailure->types[0]) === 'Cieplik206\\Fakturownia\\Read\\Exceptions\\FakturowniaReadException'
            && $readFailure->var instanceof Expr\Variable
            && $readFailure->var->name === 'exception'
            && count($readFailure->stmts) === 1
            && $readThrow?->expr instanceof Expr\Variable
            && $readThrow->expr->name === 'exception'
            && count($rawFailure->types) === 1
            && $this->resolvedNodeName($rawFailure->types[0]) === 'Throwable'
            && $rawFailure->var === null
            && count($rawFailure->stmts) === 1
            && $rawThrow?->expr instanceof Expr\New_
            && $rawThrow->expr->class instanceof Name
            && $this->resolvedNodeName($rawThrow->expr->class) === 'Cieplik206\\Fakturownia\\Read\\Exceptions\\TransportFailed'
            && $this->argumentsMatch($rawThrow->expr->args, [new Expr\Variable('operation')]);
    }

    private function hasExactTransportAuthority(mixed $method): bool
    {
        if (! $method instanceof Stmt\ClassMethod
            || $this->parameterNames($method) !== ['capability']
            || count($method->stmts ?? []) !== 2) {
            return false;
        }

        [$testBypass, $productionGate] = $method->stmts;

        return $testBypass instanceof Stmt\If_
            && $testBypass->else === null
            && $testBypass->elseifs === []
            && count($testBypass->stmts) === 1
            && $testBypass->stmts[0] instanceof Stmt\Return_
            && $testBypass->stmts[0]->expr === null
            && $this->isExactInMemoryAuthorityCondition($testBypass->cond)
            && $this->isPinnedGateStatement($productionGate, new Expr\Variable('capability'));
    }

    private function hasSingleParameter(Stmt\ClassMethod $method, string $name): bool
    {
        return $this->parameterNames($method) === [$name]
            && ! $method->params[0]->byRef
            && ! $method->params[0]->variadic
            && $method->params[0]->default === null;
    }

    /** @return list<string> */
    private function parameterNames(Stmt\ClassMethod $method): array
    {
        $names = [];

        foreach ($method->params as $parameter) {
            if (! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name)) {
                return [];
            }

            $names[] = $parameter->var->name;
        }

        return $names;
    }

    /** @param list<Expr>|null $arguments */
    private function isThisCallStatement(Stmt $statement, string $method, ?array $arguments = null): bool
    {
        return $statement instanceof Stmt\Expression
            && $this->isThisMethodCall($statement->expr, $method, $arguments);
    }

    /** @param list<Expr>|null $arguments */
    private function isThisMethodCall(?Expr $expression, string $method, ?array $arguments = null): bool
    {
        if (! $expression instanceof Expr\MethodCall
            || ! $expression->var instanceof Expr\Variable
            || $expression->var->name !== 'this'
            || ! $expression->name instanceof Identifier
            || $expression->name->toString() !== $method) {
            return false;
        }

        return $arguments === null || $this->argumentsMatch($expression->args, $arguments);
    }

    /** @param list<Expr> $arguments */
    private function isVariableMethodCall(
        Expr $expression,
        string $variable,
        string $method,
        array $arguments,
    ): bool {
        return $expression instanceof Expr\MethodCall
            && $expression->var instanceof Expr\Variable
            && $expression->var->name === $variable
            && $expression->name instanceof Identifier
            && $expression->name->toString() === $method
            && $this->argumentsMatch($expression->args, $arguments);
    }

    /** @param list<Expr>|null $arguments */
    private function isStaticCallStatement(
        Stmt $statement,
        string $class,
        string $method,
        ?array $arguments = null,
    ): bool {
        if (! $statement instanceof Stmt\Expression
            || ! $statement->expr instanceof Expr\StaticCall
            || ! $statement->expr->class instanceof Name
            || $this->resolvedNodeName($statement->expr->class) !== $class
            || ! $statement->expr->name instanceof Identifier
            || $statement->expr->name->toString() !== $method) {
            return false;
        }

        return $arguments === null
            ? $statement->expr->args === []
            : $this->argumentsMatch($statement->expr->args, $arguments);
    }

    private function assignmentExpression(Stmt $statement): ?Expr\Assign
    {
        return $statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Assign
            ? $statement->expr
            : null;
    }

    private function assignmentVariableName(Stmt $statement): ?string
    {
        $assignment = $this->assignmentExpression($statement);

        return $assignment instanceof Expr\Assign
            && $assignment->var instanceof Expr\Variable
            && is_string($assignment->var->name)
                ? $assignment->var->name
                : null;
    }

    private function requestCapabilityExpression(): Expr\MethodCall
    {
        return new Expr\MethodCall(new Expr\Variable('request'), 'capability');
    }

    private function isPinnedGateStatement(Stmt $statement, Expr $capability): bool
    {
        if (! $statement instanceof Stmt\Expression
            || ! $statement->expr instanceof Expr\MethodCall
            || ! $statement->expr->var instanceof Expr\New_
            || ! $statement->expr->var->class instanceof Name
            || $this->resolvedNodeName($statement->expr->var->class) !== self::PinnedCapabilityGate
            || $statement->expr->var->args !== []
            || ! $statement->expr->name instanceof Identifier
            || $statement->expr->name->toString() !== 'assertSupported') {
            return false;
        }

        return $this->argumentsMatch($statement->expr->args, [$capability]);
    }

    private function containsThisMethodCall(Node $node, string $method): bool
    {
        return (new NodeFinder)->findFirst(
            $node,
            fn (Node $candidate): bool => $candidate instanceof Expr\MethodCall
                && $this->isThisMethodCall($candidate, $method),
        ) instanceof Node;
    }

    private function isExactInMemoryAuthorityCondition(Expr $condition): bool
    {
        if (! $condition instanceof Expr\BinaryOp\BooleanAnd
            || ! $condition->left instanceof Expr\BinaryOp\Identical
            || ! $condition->left->left instanceof Expr\ClassConstFetch
            || ! $condition->left->right instanceof Expr\ClassConstFetch
            || ! $condition->left->left->class instanceof Expr\MethodCall
            || ! $this->isThisMethodCall($condition->left->left->class, 'sender', [])
            || ! $condition->left->left->name instanceof Identifier
            || strtolower($condition->left->left->name->toString()) !== 'class'
            || ! $this->isClassConstant(
                $condition->left->right,
                self::InMemorySender,
                'class',
            )) {
            return false;
        }

        $hostProof = $condition->right;

        return $hostProof instanceof Expr\FuncCall
            && $hostProof->name instanceof Name
            && strtolower($hostProof->name->toString()) === 'hash_equals'
            && count($hostProof->args) === 2
            && $hostProof->args[0] instanceof Node\Arg
            && $hostProof->args[0]->value instanceof Expr\ClassConstFetch
            && $this->isClassConstant(
                $hostProof->args[0]->value,
                self::InMemoryReadExecutor,
                'OriginHost',
            )
            && $hostProof->args[1] instanceof Node\Arg
            && $hostProof->args[1]->value instanceof Expr\MethodCall
            && $hostProof->args[1]->value->name instanceof Identifier
            && $hostProof->args[1]->value->name->toString() === 'host'
            && $hostProof->args[1]->value->args === []
            && $hostProof->args[1]->value->var instanceof Expr\MethodCall
            && $this->isThisMethodCall($hostProof->args[1]->value->var, 'baseUrl', []);
    }

    private function isClassConstant(
        Expr\ClassConstFetch $constant,
        string $class,
        string $name,
    ): bool {
        return $constant->class instanceof Name
            && $this->resolvedNodeName($constant->class) === $class
            && $constant->name instanceof Identifier
            && strtolower($constant->name->toString()) === strtolower($name);
    }

    /**
     * @param  array<array-key, Node\Arg|Node\VariadicPlaceholder>  $arguments
     * @param  list<Expr>  $expected
     */
    private function argumentsMatch(array $arguments, array $expected): bool
    {
        $arguments = array_values($arguments);

        if (count($arguments) !== count($expected)) {
            return false;
        }

        foreach ($arguments as $index => $argument) {
            if (! $argument instanceof Node\Arg
                || $argument->name !== null
                || $argument->byRef
                || $argument->unpack
                || ! $this->expressionsMatch($argument->value, $expected[$index])) {
                return false;
            }
        }

        return true;
    }

    private function expressionsMatch(Expr $actual, Expr $expected): bool
    {
        if ($expected instanceof Expr\Variable) {
            return $actual instanceof Expr\Variable && $actual->name === $expected->name;
        }

        if ($expected instanceof Expr\MethodCall) {
            return $actual instanceof Expr\MethodCall
                && $expected->name instanceof Identifier
                && $this->isExpressionMethodCallEquivalent($actual, $expected);
        }

        if ($expected instanceof Expr\New_) {
            if (! $actual instanceof Expr\New_
                || ! $actual->class instanceof Name
                || ! $expected->class instanceof Name
                || $this->resolvedNodeName($actual->class) !== $this->resolvedNodeName($expected->class)) {
                return false;
            }

            $expectedArguments = [];
            foreach ($expected->args as $argument) {
                if (! $argument instanceof Node\Arg) {
                    return false;
                }

                $expectedArguments[] = $argument->value;
            }

            return $this->argumentsMatch($actual->args, $expectedArguments);
        }

        return false;
    }

    private function isExpressionMethodCallEquivalent(
        Expr\MethodCall $actual,
        Expr\MethodCall $expected,
    ): bool {
        if (! $actual->name instanceof Identifier
            || ! $expected->name instanceof Identifier
            || $actual->name->toString() !== $expected->name->toString()
            || ! $this->expressionsMatch($actual->var, $expected->var)) {
            return false;
        }

        $expectedArguments = [];
        foreach ($expected->args as $argument) {
            if (! $argument instanceof Node\Arg) {
                return false;
            }

            $expectedArguments[] = $argument->value;
        }

        return $this->argumentsMatch($actual->args, $expectedArguments);
    }

    /** @return list<Expr\MethodCall> */
    private function descriptorDispatchCalls(Stmt\ClassMethod $method): array
    {
        $calls = (new NodeFinder)->findInstanceOf($method, Expr\MethodCall::class);

        return array_values(array_filter(
            $calls,
            static fn (Expr\MethodCall $call): bool => $call->name instanceof Identifier
                && in_array(strtolower($call->name->toString()), ['artifact', 'execute', 'stream'], true)
                && isset($call->args[0]),
        ));
    }

    private function descriptorClassForCall(
        Stmt\ClassMethod $method,
        Expr\MethodCall $call,
    ): ?string {
        $argument = $call->args[0] ?? null;

        if (! $argument instanceof Node\Arg) {
            return null;
        }

        if ($argument->value instanceof Expr\New_ && $argument->value->class instanceof Name) {
            return $this->resolvedNodeName($argument->value->class);
        }

        if (! $argument->value instanceof Expr\Variable || ! is_string($argument->value->name)) {
            return null;
        }

        $variable = $argument->value->name;
        $assignments = array_values(array_filter(
            (new NodeFinder)->findInstanceOf($method, Expr\Assign::class),
            static fn (Expr\Assign $assignment): bool => $assignment->var instanceof Expr\Variable
                && $assignment->var->name === $variable,
        ));

        if (count($assignments) !== 1) {
            return null;
        }

        $onlyAssignment = $assignments[0];
        $resolved = null;

        foreach ($method->stmts ?? [] as $statement) {
            if ($statement->getStartLine() >= $call->getStartLine()) {
                break;
            }

            $assignment = $this->assignmentExpression($statement);

            if ($assignment !== $onlyAssignment) {
                continue;
            }

            if (! $assignment->expr instanceof Expr\New_
                || ! $assignment->expr->class instanceof Name) {
                return null;
            }

            $resolved = $this->resolvedNodeName($assignment->expr->class);
        }

        return $resolved;
    }

    /** @param list<Expr\MethodCall> $dispatches */
    private function hasExactReadResourceBoundary(
        string $methodKey,
        Stmt\ClassMethod $method,
        array $dispatches,
    ): bool {
        $dispatchMethod = match ($methodKey) {
            self::ReadResource.'::execute' => 'execute',
            self::ReadResource.'::artifact' => 'stream',
            default => null,
        };

        if ($dispatchMethod === null
            || ! $this->hasSingleParameter($method, 'request')
            || count($method->stmts ?? []) !== 2
            || count($dispatches) !== 1
            || ! $this->isCapabilityPropertyGateStatement(
                $method->stmts[0],
                $this->requestCapabilityExpression(),
            )) {
            return false;
        }

        $dispatch = $dispatches[0];

        return $dispatch->name instanceof Identifier
            && $dispatch->name->toString() === $dispatchMethod
            && $this->isThisPropertyMethodCall(
                $dispatch,
                'executor',
                $dispatchMethod,
                [new Expr\Variable('request')],
            );
    }

    /** @param list<Expr\MethodCall> $dispatches */
    private function hasExactRetryingReadDelegate(
        string $methodKey,
        Stmt\ClassMethod $method,
        array $dispatches,
    ): bool {
        $dispatchMethod = match ($methodKey) {
            self::RetryingReadExecutor.'::execute' => 'execute',
            self::RetryingReadExecutor.'::stream' => 'stream',
            default => null,
        };

        if ($dispatchMethod === null || ! $this->hasSingleParameter($method, 'request')) {
            return false;
        }

        foreach ($dispatches as $dispatch) {
            if (! $this->isThisPropertyMethodCall(
                $dispatch,
                'executor',
                $dispatchMethod,
                [new Expr\Variable('request')],
            )) {
                return false;
            }
        }

        return true;
    }

    private function hasExactDeferredResourceFlow(
        Stmt\ClassMethod $method,
        ?string $requestClass,
    ): bool {
        if ($requestClass === null
            || count($method->stmts ?? []) !== 3
            || ! $this->isCapabilityPropertyGateStatement(
                $method->stmts[1],
                $this->newRequestCapabilityExpression($requestClass),
            )) {
            return false;
        }

        $return = $method->stmts[2];

        if (! $return instanceof Stmt\Return_) {
            return false;
        }

        return match ($method->name->toString()) {
            'stream' => $this->isStaticCallStatement(
                $method->stmts[0],
                self::Pagination,
                'assertMaximumPages',
                [new Expr\Variable('maximumPages')],
            ) && $this->isThisMethodCall(
                $return->expr,
                'iterate',
                [new Expr\Variable('query'), new Expr\Variable('maximumPages')],
            ),
            'streamByExactOid' => $requestClass === self::FindInvoicesByExactOidRequest
                && $this->isThisCallStatement(
                    $method->stmts[0],
                    'assertExactOidScanBounds',
                    [new Expr\Variable('query'), new Expr\Variable('maximumPages')],
                )
                && $this->isThisMethodCall(
                    $return->expr,
                    'iterateByExactOid',
                    [new Expr\Variable('query'), new Expr\Variable('maximumPages')],
                ),
            default => false,
        };
    }

    private function hasExactHardDeniedCapability(
        Stmt\ClassMethod $method,
        ?string $requestClass,
    ): bool {
        if ($requestClass === null || count($method->stmts ?? []) !== 2) {
            return false;
        }

        $assignment = $this->assignmentExpression($method->stmts[0]);
        $denial = $this->throwExpression($method->stmts[1]);

        return $assignment instanceof Expr\Assign
            && $assignment->var instanceof Expr\Variable
            && $assignment->var->name === 'request'
            && $assignment->expr instanceof Expr\New_
            && $assignment->expr->class instanceof Name
            && $this->resolvedNodeName($assignment->expr->class) === $requestClass
            && $denial instanceof Expr\Throw_
            && $denial->expr instanceof Expr\New_
            && $denial->expr->class instanceof Name
            && $this->resolvedNodeName($denial->expr->class) === 'Cieplik206\\Fakturownia\\Read\\Exceptions\\UnsupportedCapability'
            && $this->argumentsMatch($denial->expr->args, [$this->requestCapabilityExpression()]);
    }

    private function throwExpression(Stmt $statement): ?Expr\Throw_
    {
        return $statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Throw_
            ? $statement->expr
            : null;
    }

    private function isCapabilityPropertyGateStatement(
        Stmt $statement,
        Expr $capability,
    ): bool {
        return $statement instanceof Stmt\Expression
            && $statement->expr instanceof Expr\MethodCall
            && $this->isThisPropertyMethodCall(
                $statement->expr,
                'capabilities',
                'assertSupported',
                [$capability],
            );
    }

    /** @param list<Expr> $arguments */
    private function isThisPropertyMethodCall(
        Expr\MethodCall $call,
        string $property,
        string $method,
        array $arguments,
    ): bool {
        return $call->var instanceof Expr\PropertyFetch
            && $call->var->var instanceof Expr\Variable
            && $call->var->var->name === 'this'
            && $call->var->name instanceof Identifier
            && $call->var->name->toString() === $property
            && $call->name instanceof Identifier
            && $call->name->toString() === $method
            && $this->argumentsMatch($call->args, $arguments);
    }

    private function newRequestCapabilityExpression(string $requestClass): Expr\MethodCall
    {
        return new Expr\MethodCall(
            new Expr\New_(new Name\FullyQualified($requestClass), [new Node\Arg(new Expr\Variable('query'))]),
            'capability',
        );
    }

    private function resolvedNodeName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }

    /** @param array<string, Stmt\ClassMethod> $methodNodes */
    private function isSealedFakturowniaFacade(
        ProductionClassDeclaration $class,
        array $methodNodes,
    ): bool {
        if ($class->name !== self::FakturowniaFacade || ! $class->isFinal) {
            return false;
        }

        $connection = $methodNodes[self::FakturowniaFacade.'::connection'] ?? null;
        $denyMagic = $methodNodes[self::FakturowniaFacade.'::__callStatic'] ?? null;
        $accessor = $methodNodes[self::FakturowniaFacade.'::getFacadeAccessor'] ?? null;

        return $this->hasExactFacadeConnection($connection)
            && $this->hasExactFacadeMagicDeny($denyMagic)
            && $this->hasExactFacadeAccessor($accessor);
    }

    private function hasExactFacadeConnection(mixed $method): bool
    {
        if (! $method instanceof Stmt\ClassMethod
            || ! $method->isPublic()
            || ! $method->isStatic()
            || $this->parameterNames($method) !== ['connectionKey']
            || count($method->stmts ?? []) !== 3) {
            return false;
        }

        [$resolveRoot, $guardRoot, $returnConnection] = $method->stmts;
        $assignment = $this->assignmentExpression($resolveRoot);

        return $assignment instanceof Expr\Assign
            && $assignment->var instanceof Expr\Variable
            && $assignment->var->name === 'manager'
            && $assignment->expr instanceof Expr\StaticCall
            && $assignment->expr->class instanceof Name
            && strtolower($assignment->expr->class->toString()) === 'self'
            && $assignment->expr->name instanceof Identifier
            && $assignment->expr->name->toString() === 'getFacadeRoot'
            && $assignment->expr->args === []
            && $guardRoot instanceof Stmt\If_
            && $guardRoot->cond instanceof Expr\BooleanNot
            && $guardRoot->cond->expr instanceof Expr\Instanceof_
            && $guardRoot->cond->expr->expr instanceof Expr\Variable
            && $guardRoot->cond->expr->expr->name === 'manager'
            && $guardRoot->cond->expr->class instanceof Name
            && $this->resolvedNodeName($guardRoot->cond->expr->class) === self::FakturowniaManager
            && $guardRoot->else === null
            && $guardRoot->elseifs === []
            && count($guardRoot->stmts) === 1
            && $this->throwExpression($guardRoot->stmts[0]) instanceof Expr\Throw_
            && $returnConnection instanceof Stmt\Return_
            && $returnConnection->expr instanceof Expr\MethodCall
            && $this->isVariableMethodCall(
                $returnConnection->expr,
                'manager',
                'connection',
                [new Expr\Variable('connectionKey')],
            );
    }

    private function hasExactFacadeMagicDeny(mixed $method): bool
    {
        return $method instanceof Stmt\ClassMethod
            && $method->isPublic()
            && $method->isStatic()
            && $method->isFinal()
            && $this->parameterNames($method) === ['method', 'arguments']
            && count($method->stmts ?? []) === 1
            && $this->throwExpression($method->stmts[0])?->expr instanceof Expr\New_
            && $this->throwExpression($method->stmts[0])->expr->class instanceof Name
            && $this->resolvedNodeName($this->throwExpression($method->stmts[0])->expr->class) === 'BadMethodCallException';
    }

    private function hasExactFacadeAccessor(mixed $method): bool
    {
        return $method instanceof Stmt\ClassMethod
            && $method->isProtected()
            && $method->isStatic()
            && $method->params === []
            && count($method->stmts ?? []) === 1
            && $method->stmts[0] instanceof Stmt\Return_
            && $method->stmts[0]->expr instanceof Expr\ClassConstFetch
            && $this->isClassConstant(
                $method->stmts[0]->expr,
                self::FakturowniaManager,
                'class',
            );
    }
}

final class RemoteApiNodeCollector extends NodeVisitorAbstract
{
    /** @var list<ProductionClassDeclaration> */
    public array $classes = [];

    /** @var list<ProductionMethodDeclaration> */
    public array $methods = [];

    /** @var array<string, Stmt\ClassMethod> */
    public array $methodNodes = [];

    /** @var list<DispatchSite> */
    public array $dispatchSites = [];

    /** @var list<MethodCallReference> */
    public array $methodCalls = [];

    /** @var list<string> */
    public array $pinnedGateMethods = [];

    /** @var list<TraitUseReference> */
    public array $traitUses = [];

    /** @var list<string> */
    public array $violations = [];

    /** @var list<string|null> */
    private array $classStack = [];

    /** @var list<string|null> */
    private array $methodStack = [];

    public function __construct(private readonly string $file) {}

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\Function_) {
            $function = $node->namespacedName?->toString() ?? $node->name->toString();
            $this->violations[] = "global_function_forbidden {$function} {$this->location($node)}";
        }

        if ($node instanceof Stmt\Class_) {
            $this->inspectAlternateNetworkClass($node);
        }

        if ($node instanceof Stmt\ClassLike) {
            $this->enterClass($node);
        }

        if ($node instanceof Stmt\ClassMethod) {
            $this->enterMethod($node);
        }

        $methodKey = $this->currentMethodKey();
        $surface = $methodKey ?? $this->currentClassName() ?? 'global';

        if ($node instanceof Expr\Eval_) {
            $this->violations[] = "eval_forbidden {$surface} {$this->location($node)}";
        }

        if ($node instanceof Expr\ShellExec) {
            $this->violations[] = "shell_execution_forbidden {$surface} {$this->location($node)}";
        }

        if ($node instanceof Expr\New_ && $node->class instanceof Expr) {
            $this->violations[] = "dynamic_class_instantiation_forbidden {$surface} {$this->location($node)}";
        }

        if ($node instanceof Expr\New_
            && $node->class instanceof Name
            && $this->isDangerousReflectionType($this->resolvedName($node->class))
            && ($methodKey === null
                || ! RemoteApiArchitectureInspector::isPinnedExecutionEscapeCallsite(
                    $methodKey,
                    'new:'.$this->resolvedName($node->class),
                    $this->file,
                ))) {
            $this->violations[] = "dangerous_reflection_type {$surface} {$this->resolvedName($node->class)} {$this->location($node)}";
        }

        if ($node instanceof Expr\MethodCall
            || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\StaticCall) {
            $this->inspectMethodCall($node, $methodKey);
        }

        if ($node instanceof Expr\FuncCall) {
            $this->inspectFunctionCall($node, $methodKey);
        }

        if ($node instanceof Expr\Array_) {
            $this->inspectCallableArray($node, $methodKey);
        }

        if ($node instanceof Expr\New_
            && $node->class instanceof Name
            && $this->resolvedName($node->class) === RemoteApiArchitectureInspector::SecureRequest) {
            $this->violations[] = "secure_request_base_instantiated {$this->location($node)}";
        }

        if ($node instanceof Expr\New_
            && $node->class instanceof Name
            && $this->isAlternateNetworkType($this->resolvedName($node->class))) {
            $this->violations[] = "alternate_network_client {$this->resolvedName($node->class)} {$this->location($node)}";
        }

        if ($node instanceof Expr\Include_) {
            $kind = match ($node->type) {
                Expr\Include_::TYPE_INCLUDE => 'include',
                Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
                Expr\Include_::TYPE_REQUIRE => 'require',
                Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
                default => 'unknown',
            };
            $this->violations[] = "include_require_forbidden {$surface} {$kind} {$this->location($node)}";
        }

        if ($node instanceof Expr\New_
            && $node->class instanceof Name
            && $this->resolvedName($node->class) === 'Saloon\\Http\\PendingRequest') {
            $requestArgument = $node->args[1] ?? null;
            $requestClass = $requestArgument instanceof Node\Arg
                ? $this->newClassName($requestArgument->value)
                : null;
            $this->dispatchSites[] = new DispatchSite(
                'new PendingRequest',
                $methodKey,
                $requestClass,
                $this->file,
                $node->getStartLine(),
            );
        }

        if ($this->currentClassName() !== null && $node instanceof Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $traitName = $this->resolvedName($trait);

                $this->traitUses[] = new TraitUseReference(
                    $this->currentClassName(),
                    $traitName,
                    $node->adaptations !== [],
                    $this->file,
                    $node->getStartLine(),
                );

                if (! str_starts_with($traitName, 'Cieplik206\\Fakturownia\\')) {
                    $this->violations[] = "external_trait_not_allowlisted {$this->currentClassName()} {$traitName} {$this->location($node)}";
                }
            }
        }

        if ($this->currentClassName() !== null && $node instanceof Stmt\Property && $node->isPublic()) {
            $this->inspectPublicType($node->type, $this->currentClassName().'::$property', $node);
        }

        if (($node instanceof Stmt\Property || $node instanceof Node\Param)
            && $this->containsAlternateNetworkType($node->type)) {
            $this->violations[] = "alternate_network_type {$this->currentMethodKeyOrClass()} {$this->location($node)}";
        }

        if (($node instanceof Stmt\Property || $node instanceof Node\Param)
            && $this->containsDangerousReflectionType($node->type)) {
            $this->violations[] = "dangerous_reflection_type {$this->currentMethodKeyOrClass()} {$this->location($node)}";
        }

        if ($this->currentClassName() !== null
            && $node instanceof Node\Param
            && ($node->flags & Stmt\Class_::MODIFIER_PUBLIC) !== 0) {
            $this->inspectPublicType($node->type, $this->currentClassName().'::$promotedProperty', $node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassMethod) {
            array_pop($this->methodStack);
        }

        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classStack);
        }

        return null;
    }

    private function enterClass(Stmt\ClassLike $class): void
    {
        if ($class instanceof Stmt\Class_ && $class->isAnonymous()) {
            $this->classStack[] = null;

            return;
        }

        $name = $class->namespacedName ?? null;

        if (! $name instanceof Name) {
            $this->classStack[] = null;

            return;
        }

        $className = $name->toString();
        $parent = $class instanceof Stmt\Class_ && $class->extends instanceof Name
            ? $this->resolvedName($class->extends)
            : null;

        $this->classes[] = new ProductionClassDeclaration(
            $className,
            match (true) {
                $class instanceof Stmt\Trait_ => 'trait',
                $class instanceof Stmt\Interface_ => 'interface',
                $class instanceof Stmt\Enum_ => 'enum',
                default => 'class',
            },
            $parent,
            $class instanceof Stmt\Class_ && $class->isAbstract(),
            $class instanceof Stmt\Class_ && $class->isFinal(),
            $class instanceof Stmt\Class_ && $class->isReadonly(),
            $this->file,
            $class->getStartLine(),
        );
        $this->classStack[] = $className;
    }

    private function enterMethod(Stmt\ClassMethod $method): void
    {
        $class = $this->currentClassName();

        if ($class === null) {
            $this->methodStack[] = null;

            return;
        }

        $declaration = new ProductionMethodDeclaration(
            $class,
            $method->name->toString(),
            $method->isPublic(),
            $method->isPrivate(),
            $method->isFinal(),
            $this->capabilityGates($method),
            $this->file,
            $method->getStartLine(),
        );
        $this->methods[] = $declaration;
        $this->methodNodes[$declaration->key()] = $method;
        $this->methodStack[] = $declaration->key();

        if ($method->isPublic()
            && ($method->returnType !== null || ! in_array($declaration->name, ['__clone', '__construct'], true))) {
            $this->inspectPublicType($method->returnType, $declaration->key().' return', $method);
        }
    }

    /** @return list<CapabilityGate> */
    private function capabilityGates(Stmt\ClassMethod $method): array
    {
        $gates = [];

        foreach ($method->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if ($this->resolvedName($attribute->name) !== 'Cieplik206\\Fakturownia\\Client\\Attributes\\RequiresCapability') {
                    continue;
                }

                $arguments = [];

                foreach ($attribute->args as $position => $argument) {
                    $arguments[$argument->name?->toString() ?? $position] = $argument->value;
                }

                $capability = $arguments['capabilityId'] ?? $arguments[0] ?? null;
                $request = $arguments['requestClass'] ?? $arguments[1] ?? null;
                $requestClass = null;

                if ($request instanceof Expr\ClassConstFetch
                    && $request->class instanceof Name
                    && $request->name instanceof Identifier
                    && strtolower($request->name->toString()) === 'class') {
                    $requestClass = $this->resolvedName($request->class);
                }

                $gates[] = new CapabilityGate(
                    $capability instanceof Node\Scalar\String_ ? $capability->value : null,
                    $requestClass,
                );
            }
        }

        return $gates;
    }

    private function inspectMethodCall(
        Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call,
        ?string $methodKey,
    ): void {
        if (! $call->name instanceof Identifier) {
            if ($methodKey !== null) {
                $this->violations[] = "dynamic_method_call {$methodKey} {$this->location($call)}";
            }

            return;
        }

        $method = strtolower($call->name->toString());
        $surface = $methodKey ?? $this->currentClassName() ?? 'global';

        $this->methodCalls[] = new MethodCallReference($methodKey, $method);

        if (in_array($method, RemoteApiArchitectureInspector::dangerousInvocationMethods(), true)
            && ($methodKey === null
                || ! RemoteApiArchitectureInspector::isPinnedExecutionEscapeCallsite(
                    $methodKey,
                    'method:'.$method,
                    $this->file,
                ))) {
            $this->violations[] = "dangerous_invocation {$surface} {$method} {$this->location($call)}";
        }

        if ($call instanceof Expr\StaticCall
            && $call->class instanceof Name
            && $this->isDangerousReflectionType($this->resolvedName($call->class))) {
            $this->violations[] = "dangerous_reflection_type {$surface} {$this->resolvedName($call->class)} {$this->location($call)}";
        }

        if ($methodKey !== null
            && in_array($method, RemoteApiArchitectureInspector::alternateNetworkDispatchMethods(), true)) {
            $this->violations[] = "alternate_network_dispatch {$methodKey} {$method} {$this->location($call)}";
        }

        if ($methodKey !== null
            && $call instanceof Expr\StaticCall
            && $call->class instanceof Name
            && $this->isAlternateNetworkType($this->resolvedName($call->class))) {
            $this->violations[] = "alternate_network_client {$this->resolvedName($call->class)} {$this->location($call)}";
        }

        if ($methodKey !== null
            && $method === 'assertsupported'
            && $call instanceof Expr\MethodCall
            && $call->var instanceof Expr\New_
            && $call->var->class instanceof Name
            && $this->resolvedName($call->var->class) === 'Cieplik206\\Fakturownia\\Client\\ReadTransport\\PinnedReadCapabilityGate') {
            $this->pinnedGateMethods[] = $methodKey;
        }

        if (in_array($method, RemoteApiArchitectureInspector::dispatchMethods(), true)) {
            $requestArgument = $call->args[0] ?? null;
            $requestClass = $method !== 'pool' && $requestArgument instanceof Node\Arg
                ? $this->newClassName($requestArgument->value)
                : null;
            $this->dispatchSites[] = new DispatchSite(
                $method,
                $methodKey,
                $requestClass,
                $this->file,
                $call->getStartLine(),
            );
        }

        if ($call->isFirstClassCallable() && $methodKey !== null) {
            $this->violations[] = "first_class_callable {$methodKey}::{$method} {$this->location($call)}";
        }

        if ($call instanceof Expr\StaticCall
            && $method === 'fromcallable'
            && $call->class instanceof Name
            && $this->resolvedName($call->class) === 'Closure'
            && $methodKey !== null) {
            $this->violations[] = "callable_forwarder {$methodKey} {$this->location($call)}";
        }
    }

    private function inspectFunctionCall(Expr\FuncCall $call, ?string $methodKey): void
    {
        if ($methodKey === null) {
            return;
        }

        if (! $call->name instanceof Name) {
            $this->violations[] = "dynamic_function_call {$methodKey} {$this->location($call)}";

            return;
        }

        $function = strtolower(ltrim($call->name->toString(), '\\'));

        if (in_array($function, RemoteApiArchitectureInspector::forwardingFunctions(), true)) {
            $this->violations[] = "callable_forwarder {$methodKey} {$function} {$this->location($call)}";
        }

        $callbackArgument = RemoteApiArchitectureInspector::callbackFunctionArgument($function);

        if ($callbackArgument !== null && ! $this->hasSafeInlineCallback($call, $callbackArgument)) {
            $this->violations[] = "callable_forwarder {$methodKey} {$function} callback {$this->location($call)}";
        }

        if (in_array($function, RemoteApiArchitectureInspector::processExecutionFunctions(), true)) {
            $this->violations[] = "process_execution_forbidden {$methodKey} {$function} {$this->location($call)}";
        }

        if (in_array($function, RemoteApiArchitectureInspector::alternateNetworkFunctions(), true)
            || str_starts_with($function, 'curl_')
            || str_starts_with($function, 'ftp_')
            || str_starts_with($function, 'socket_')
            || str_starts_with($function, 'ssh2_')
            || str_starts_with($function, 'stream_socket_')
            || str_starts_with($function, 'guzzlehttp\\')) {
            $this->violations[] = "alternate_network_function {$methodKey} {$function} {$this->location($call)}";
        }

        if (in_array($function, RemoteApiArchitectureInspector::networkStreamWrapperFunctions(), true)) {
            $isPinnedLocalCallsite = RemoteApiArchitectureInspector::isPinnedLocalStreamWrapperCallsite(
                $methodKey,
                $function,
                $this->file,
            );

            if (! $isPinnedLocalCallsite) {
                $this->violations[] = "alternate_network_stream_wrapper {$methodKey} {$function} {$this->location($call)}";
            }
        }
    }

    private function inspectCallableArray(Expr\Array_ $array, ?string $methodKey): void
    {
        if ($methodKey === null || count($array->items) !== 2) {
            return;
        }

        $method = $array->items[1]->value;

        if ($method instanceof Node\Scalar\String_) {
            $normalized = strtolower($method->value);

            if (in_array($normalized, RemoteApiArchitectureInspector::dispatchMethods(), true)) {
                $this->violations[] = "dispatch_callable {$methodKey}::{$normalized} {$this->location($array)}";
            }
        }
    }

    /** @param array{index: int, optional: bool, nullable: bool} $contract */
    private function hasSafeInlineCallback(Expr\FuncCall $call, array $contract): bool
    {
        $argument = null;

        foreach ($call->args as $candidate) {
            if ($candidate->name?->toString() === 'callback') {
                $argument = $candidate;

                break;
            }
        }

        $argument ??= $call->args[$contract['index']] ?? null;

        if (! $argument instanceof Node\Arg) {
            return $contract['optional'];
        }

        if ($argument->value instanceof Expr\Closure || $argument->value instanceof Expr\ArrowFunction) {
            return true;
        }

        return $contract['nullable']
            && $argument->value instanceof Expr\ConstFetch
            && strtolower($argument->value->name->toString()) === 'null';
    }

    private function inspectPublicType(Node|string|null $type, string $surface, Node $node): void
    {
        if ($type === null) {
            $this->violations[] = "unsafe_public_transport_surface {$surface} untyped {$this->location($node)}";

            return;
        }

        foreach ($this->typeNames($type) as $typeName) {
            if (in_array($typeName, RemoteApiArchitectureInspector::unsafePublicTypes(), true)) {
                $this->violations[] = "unsafe_public_transport_surface {$surface} {$typeName} {$this->location($node)}";
            }
        }
    }

    /** @return list<string> */
    private function typeNames(Node|string|null $type): array
    {
        if ($type instanceof NullableType) {
            return $this->typeNames($type->type);
        }

        if ($type instanceof UnionType || $type instanceof Node\IntersectionType) {
            $types = [];

            foreach ($type->types as $nestedType) {
                array_push($types, ...$this->typeNames($nestedType));
            }

            return $types;
        }

        if ($type instanceof Name) {
            return [$this->resolvedName($type)];
        }

        if ($type instanceof Identifier) {
            return [$type->toString()];
        }

        return is_string($type) ? [$type] : [];
    }

    private function inspectAlternateNetworkClass(Stmt\Class_ $class): void
    {
        $types = $class->implements;

        if ($class->extends instanceof Name) {
            $types[] = $class->extends;
        }

        foreach ($types as $type) {
            $name = $this->resolvedName($type);

            if ($this->isAlternateNetworkType($name)) {
                $this->violations[] = "alternate_network_type {$name} {$this->location($class)}";
            }
        }
    }

    private function containsAlternateNetworkType(Node|string|null $type): bool
    {
        foreach ($this->typeNames($type) as $typeName) {
            if ($this->isAlternateNetworkType($typeName)) {
                return true;
            }
        }

        return false;
    }

    private function containsDangerousReflectionType(Node|string|null $type): bool
    {
        foreach ($this->typeNames($type) as $typeName) {
            if ($this->isDangerousReflectionType($typeName)) {
                return true;
            }
        }

        return false;
    }

    private function isAlternateNetworkType(string $type): bool
    {
        foreach (RemoteApiArchitectureInspector::alternateNetworkClientTypes() as $forbidden) {
            if (strcasecmp($type, $forbidden) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isDangerousReflectionType(string $type): bool
    {
        foreach (RemoteApiArchitectureInspector::dangerousReflectionTypes() as $forbidden) {
            if (strcasecmp($type, $forbidden) === 0) {
                return true;
            }
        }

        return false;
    }

    private function currentMethodKeyOrClass(): string
    {
        return $this->currentMethodKey() ?? $this->currentClassName() ?? 'global';
    }

    private function currentClassName(): ?string
    {
        $class = end($this->classStack);

        return is_string($class) ? $class : null;
    }

    private function currentMethodKey(): ?string
    {
        $method = end($this->methodStack);

        return is_string($method) ? $method : null;
    }

    private function resolvedName(Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName');

        return $resolvedName instanceof Name ? $resolvedName->toString() : $name->toString();
    }

    private function newClassName(Expr $expression): ?string
    {
        if (! $expression instanceof Expr\New_ || ! $expression->class instanceof Name) {
            return null;
        }

        return $this->resolvedName($expression->class);
    }

    private function location(Node $node): string
    {
        return $this->file.':'.$node->getStartLine();
    }
}

final readonly class MethodCallReference
{
    public function __construct(
        public ?string $caller,
        public string $method,
    ) {}
}

final readonly class TraitUseReference
{
    public function __construct(
        public string $class,
        public string $trait,
        public bool $hasAdaptations,
        public string $file,
        public int $line,
    ) {}

    public function location(): string
    {
        return $this->file.':'.$this->line;
    }
}

final readonly class ProductionClassDeclaration
{
    public function __construct(
        public string $name,
        public string $kind,
        public ?string $parent,
        public bool $isAbstract,
        public bool $isFinal,
        public bool $isReadonly,
        public string $file,
        public int $line,
    ) {}

    public function location(): string
    {
        return $this->file.':'.$this->line;
    }
}

final readonly class ProductionMethodDeclaration
{
    /** @param list<CapabilityGate> $gates */
    public function __construct(
        public string $class,
        public string $name,
        public bool $isPublic,
        public bool $isPrivate,
        public bool $isFinal,
        public array $gates,
        public string $file,
        public int $line,
    ) {}

    public function key(): string
    {
        return $this->class.'::'.$this->name;
    }

    public function location(): string
    {
        return $this->file.':'.$this->line;
    }
}

final readonly class CapabilityGate
{
    public function __construct(
        public ?string $capabilityId,
        public ?string $requestClass,
    ) {}
}

final readonly class DispatchSite
{
    public function __construct(
        public string $kind,
        public ?string $methodKey,
        public ?string $requestClass,
        public string $file,
        public int $line,
    ) {}

    public function location(): string
    {
        return $this->file.':'.$this->line;
    }
}
