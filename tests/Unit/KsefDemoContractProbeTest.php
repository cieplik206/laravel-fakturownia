<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredExecutionRequiredException;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionClaimRequest;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionReceipt;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\FreshClaimGrant;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\RecoveredConsumedProof;
use Cieplik206\Fakturownia\Tests\Contract\Support\AccountKsefDemoRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\CreateKsefDemoInvoiceRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\DownloadKsefDemoPdfRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoConnector;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoContractProbe;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoEndpoint;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoFixtureGuard;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoInMemoryTransport;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoLiteralFailure;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoLiteralResponse;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoLiteralResponseSequence;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoProbeConfiguration;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoProfile;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefOwnership;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefValidationMode;
use Cieplik206\Fakturownia\Tests\Contract\Support\LiveEvidenceAttestationGuard;
use Cieplik206\Fakturownia\Tests\Contract\Support\LiveEvidenceConsumptionAuthority;
use Cieplik206\Fakturownia\Tests\Contract\Support\ReadKsefDemoInvoiceRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\SearchKsefDemoInvoicesRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\SendKsefDemoInvoiceRequest;
use GuzzleHttp\RequestOptions;
use Saloon\Config;
use Saloon\Contracts\Body\HasBody;
use Saloon\Contracts\Sender;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Senders\GuzzleSender;

class_exists(KsefDemoProbeConfiguration::class);
class_exists(KsefDemoContractProbe::class);

function s04Pdf(string $marker = 'body'): string
{
    return "%PDF-1.7\n{$marker}\n".str_repeat('x', KsefDemoProbeConfiguration::DefaultMinimumPdfSizeBytes)."\n%%EOF\n";
}

/** @param non-empty-list<string> $sensitiveValues */
function s04ThrowableTraceContainsSensitiveValue(
    #[SensitiveParameter] Throwable $exception,
    #[SensitiveParameter] array $sensitiveValues,
): bool {
    $current = $exception;
    $throwables = 0;

    while ($current instanceof Throwable && $throwables < 16) {
        if (s04TraceValueContainsSensitiveValue($current->getMessage(), $sensitiveValues)) {
            return true;
        }

        foreach ($current->getTrace() as $frame) {
            if (s04TraceValueContainsSensitiveValue($frame['args'] ?? [], $sensitiveValues)) {
                return true;
            }
        }

        $current = $current->getPrevious();
        $throwables++;
    }

    return false;
}

/**
 * @param  non-empty-list<string>  $sensitiveValues
 */
function s04TraceValueContainsSensitiveValue(
    #[SensitiveParameter] mixed $value,
    #[SensitiveParameter] array $sensitiveValues,
    int $depth = 0,
): bool {
    if (is_string($value)) {
        foreach ($sensitiveValues as $sensitiveValue) {
            if (\str_contains($value, $sensitiveValue)) {
                return true;
            }
        }

        return false;
    }

    if (! is_array($value) || $depth >= 8) {
        return false;
    }

    $inspected = 0;

    foreach ($value as $key => $item) {
        if ($inspected >= 4_096) {
            return false;
        }

        if (s04TraceValueContainsSensitiveValue($key, $sensitiveValues, $depth + 1)
            || s04TraceValueContainsSensitiveValue($item, $sensitiveValues, $depth + 1)) {
            return true;
        }

        $inspected++;
    }

    return false;
}

/** @param non-empty-list<string> $sensitiveValues */
function s04PrintedValueContainsSensitiveValue(
    #[SensitiveParameter] mixed $value,
    #[SensitiveParameter] array $sensitiveValues,
): bool {
    $seenObjects = new WeakMap;
    $seenReferences = [];

    return s04ValueGraphContainsSensitiveValue($value, $sensitiveValues, $seenObjects, $seenReferences);
}

/**
 * @param  non-empty-list<string>  $sensitiveValues
 * @param  WeakMap<object, true>  $seenObjects
 * @param  array<string, true>  $seenReferences
 */
function s04ValueGraphContainsSensitiveValue(
    #[SensitiveParameter] mixed &$value,
    #[SensitiveParameter] array $sensitiveValues,
    WeakMap $seenObjects,
    array &$seenReferences,
): bool {
    if (is_string($value)) {
        foreach ($sensitiveValues as $sensitiveValue) {
            if (str_contains($value, $sensitiveValue)) {
                return true;
            }
        }

        return false;
    }

    if (is_array($value)) {
        foreach (array_keys($value) as $key) {
            $keyValue = $key;

            if (s04ValueGraphContainsSensitiveValue($keyValue, $sensitiveValues, $seenObjects, $seenReferences)) {
                return true;
            }

            $reference = ReflectionReference::fromArrayElement($value, $key);

            if ($reference instanceof ReflectionReference) {
                $referenceId = bin2hex($reference->getId());

                if (isset($seenReferences[$referenceId])) {
                    continue;
                }

                $seenReferences[$referenceId] = true;
            }

            $item = &$value[$key];

            if (s04ValueGraphContainsSensitiveValue($item, $sensitiveValues, $seenObjects, $seenReferences)) {
                return true;
            }

            unset($item);
        }

        return false;
    }

    if (is_object($value)) {
        if (isset($seenObjects[$value])) {
            return false;
        }

        $seenObjects[$value] = true;
        $properties = (array) $value;

        if (s04ValueGraphContainsSensitiveValue($properties, $sensitiveValues, $seenObjects, $seenReferences)) {
            return true;
        }

        if ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);
            $staticVariables = $reflection->getStaticVariables();

            if (s04ValueGraphContainsSensitiveValue($staticVariables, $sensitiveValues, $seenObjects, $seenReferences)) {
                return true;
            }

            $closureThis = $reflection->getClosureThis();

            if ($closureThis !== null
                && s04ValueGraphContainsSensitiveValue($closureThis, $sensitiveValues, $seenObjects, $seenReferences)) {
                return true;
            }
        }

        $reflection = new ReflectionObject($value);

        if ($reflection->hasMethod('__debugInfo')) {
            $debugInfo = $reflection->getMethod('__debugInfo');

            if ($debugInfo->isPublic() && ! $debugInfo->isStatic()) {
                try {
                    $debugValues = $debugInfo->invoke($value);
                } catch (Throwable) {
                    return true;
                }

                if (s04ValueGraphContainsSensitiveValue($debugValues, $sensitiveValues, $seenObjects, $seenReferences)) {
                    return true;
                }
            }
        }

        return false;
    }

    if (is_int($value) || is_float($value) || is_bool($value)) {
        $scalar = (string) $value;

        return s04ValueGraphContainsSensitiveValue($scalar, $sensitiveValues, $seenObjects, $seenReferences);
    }

    return false;
}

/**
 * @param  array<string|int, mixed>|string  $body
 * @param  array<string, string|int|float|bool>  $headers
 */
function s04Response(array|string $body = [], int $status = 200, array $headers = []): KsefDemoLiteralResponse
{
    return KsefDemoLiteralResponse::make($body, $status, $headers);
}

/** @param class-string<Request> $requestClass */
function s04Responses(string $requestClass, KsefDemoLiteralResponse ...$responses): KsefDemoLiteralResponseSequence
{
    return new KsefDemoLiteralResponseSequence($requestClass, array_values($responses));
}

/**
 * @param  class-string<Request>  $requestClass
 * @param  non-empty-list<KsefDemoLiteralResponse>  $responses
 * @param  array<string, mixed>  $query
 * @param  array<string, mixed>  $body
 */
function s04Route(
    string $requestClass,
    array $responses,
    ?string $host = null,
    ?string $endpoint = null,
    array $query = [],
    array $body = [],
    bool $repeatLast = false,
    ?string $afterRequestClass = null,
    int $minimumRequestCount = 0,
): KsefDemoLiteralResponseSequence {
    return new KsefDemoLiteralResponseSequence(
        $requestClass,
        $responses,
        $host,
        $endpoint,
        $query,
        $body,
        $repeatLast,
        $afterRequestClass,
        $minimumRequestCount,
    );
}

function s04Transport(KsefDemoLiteralResponseSequence ...$sequences): KsefDemoInMemoryTransport
{
    return new KsefDemoInMemoryTransport(...$sequences);
}

function s04ResetSaloonRuntime(): void
{
    MockClient::destroyGlobal();
    Config::clearGlobalMiddleware();
    Config::setSenderResolver(null);
    Config::$defaultSender = GuzzleSender::class;
    Config::$defaultTlsMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    Config::$defaultConnectionTimeout = 10;
    Config::$defaultRequestTimeout = 30;
}

function s04Oid(string $profileKey, bool $invalid = false): string
{
    $kind = $invalid ? 'invalid' : 'valid';

    return "s04-{$profileKey}-".KsefDemoInMemoryTransport::DeterministicScenarioNonce."-{$kind}";
}

/** @return array<string, mixed> */
function s04InvoiceTemplate(string $marker = 'valid'): array
{
    return [
        'department_id' => 10,
        'issue_date' => '2026-08-25',
        'sell_date' => '2026-08-25',
        'payment_to_kind' => 'off',
        'buyer_name' => 'S04 contract buyer',
        'buyer_tax_no' => $marker === 'invalid' ? '' : '1111111111',
        'buyer_company' => true,
        'buyer_country' => 'PL',
        'currency' => 'PLN',
        'positions' => [[
            'name' => 'S04 contract item',
            'quantity' => '1.00',
            'price_net' => '10.00',
            'tax' => '23',
        ]],
    ];
}

/** @return array{code: string, message: array{buyer_tax_no: list<string>}} */
function s04BlockInvalidResponse(): array
{
    return [
        'code' => 'error',
        'message' => ['buyer_tax_no' => ['- nie może być puste']],
    ];
}

function s04AttestedAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-25T00:00:00+00:00');
}

function s04AttestationExpiresAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-26T00:00:00+00:00');
}

function s04AccountId(string $key): string
{
    return match ($key) {
        'explicit_block' => '1001',
        'explicit_persist' => '1002',
        'auto_block' => '1003',
        'auto_persist' => '1004',
        default => throw new InvalidArgumentException('Unknown S0.4 test profile key.'),
    };
}

/**
 * @return array{poll_window_ms: int, poll_interval_ms: int, max_search_pages: int, pre_send_observation_window_ms: int, visibility_window_ms: int, visibility_poll_interval_ms: int, connect_timeout_ms: int, request_timeout_ms: int, minimum_pdf_size_bytes: int}
 */
function s04LiveLimits(): array
{
    return (new KsefDemoProbeConfiguration([
        'explicit_block' => s04Profile('explicit_block', KsefOwnership::ExplicitSdk, KsefValidationMode::BlockInvalid),
        'explicit_persist' => s04Profile('explicit_persist', KsefOwnership::ExplicitSdk, KsefValidationMode::PersistWithErrors),
        'auto_block' => s04Profile('auto_block', KsefOwnership::ProviderAutoSend, KsefValidationMode::BlockInvalid),
        'auto_persist' => s04Profile('auto_persist', KsefOwnership::ProviderAutoSend, KsefValidationMode::PersistWithErrors),
    ]))->evidenceLimits();
}

/** @return array{public: non-empty-string, secret: non-empty-string} */
function s04SigningMaterial(): array
{
    /** @var null|array{public: non-empty-string, secret: non-empty-string} $material */
    static $material = null;

    if (is_array($material)) {
        return $material;
    }

    $keyPair = sodium_crypto_sign_keypair();
    $public = base64_encode(sodium_crypto_sign_publickey($keyPair));
    $secret = sodium_crypto_sign_secretkey($keyPair);

    $material = ['public' => $public, 'secret' => $secret];

    return $material;
}

/** @return array{public: non-empty-string, secret: non-empty-string} */
function s04AuthoritySigningMaterial(): array
{
    /** @var null|array{public: non-empty-string, secret: non-empty-string} $material */
    static $material = null;

    if (is_array($material)) {
        return $material;
    }

    $keyPair = sodium_crypto_sign_keypair();
    $public = base64_encode(sodium_crypto_sign_publickey($keyPair));
    $secret = sodium_crypto_sign_secretkey($keyPair);

    $material = ['public' => $public, 'secret' => $secret];

    return $material;
}

function s04BindingKey(string $key): string
{
    return hash('sha256', "s04-test-binding-key-{$key}", true);
}

function s04AuthorityId(): string
{
    return 's04-test-consumption-authority';
}

function s04AuthorityPolicySha256(): string
{
    return hash('sha256', 's04-test-append-only-cas-policy-v1');
}

function s04StoreId(): string
{
    return 's04-test-cas-primary';
}

function s04StoreIdentitySha256(): string
{
    return hash('sha256', 's04-test-cas-primary-identity');
}

function s04LaunchManifestSha256(): string
{
    return hash('sha256', 's04-test-supervised-launch-manifest-v1');
}

/** @param array<string, mixed> $envelope */
function s04SignAuthorityEnvelope(array $envelope): ConsumptionReceipt
{
    $signature = sodium_crypto_sign_detached(
        LiveEvidenceAttestationGuard::canonicalJson($envelope),
        s04AuthoritySigningMaterial()['secret'],
    );

    return ConsumptionReceipt::fromArray([
        'envelope' => $envelope,
        'signature' => base64_encode($signature),
    ]);
}

function s04FreshConsumptionAuthority(): LiveEvidenceConsumptionAuthority
{
    return new class implements LiveEvidenceConsumptionAuthority
    {
        public function claim(
            array $signedAuthorizations,
            ConsumptionClaimRequest $request,
        ): FreshClaimGrant {
            $runStartedAt = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.u\Z',
                $request->runStartedAt,
                new DateTimeZone('UTC'),
            );

            if (! $runStartedAt instanceof DateTimeImmutable) {
                throw new RuntimeException('The S0.4 test claim has an invalid run start.');
            }

            $issuedAt = $runStartedAt->modify('+1 microsecond');
            $envelope = LiveEvidenceAttestationGuard::buildConsumptionAuthorityEnvelopeForTesting(
                $signedAuthorizations,
                $request->toArray(),
                s04AuthorityId(),
                ['store_id' => s04StoreId(), 'sequence' => '1'],
                $issuedAt,
                $issuedAt->modify('+1 hour'),
            );

            return new FreshClaimGrant(s04SignAuthorityEnvelope($envelope));
        }
    };
}

function s04AuthorizedProbe(
    KsefDemoProbeConfiguration $configuration,
    KsefDemoInMemoryTransport $inMemoryTransport,
    bool $verifyEffectAccountIdentity = false,
): KsefDemoContractProbe {
    $clockTick = 0;
    $clock = function () use (&$clockTick): DateTimeImmutable {
        $startedAt = new DateTimeImmutable('2026-08-25T08:00:00.000000Z');
        $now = $startedAt->modify("+{$clockTick} microseconds");
        $clockTick++;

        return $now;
    };

    return KsefDemoContractProbe::forAuthorizedTesting(
        $configuration,
        $inMemoryTransport,
        s04FreshConsumptionAuthority(),
        ['s04-test-operator' => s04SigningMaterial()['public']],
        [s04AuthorityId() => s04AuthoritySigningMaterial()['public']],
        clock: $clock,
        verifyEffectAccountIdentity: $verifyEffectAccountIdentity,
    );
}

function s04Profile(string $key, KsefOwnership $ownership, KsefValidationMode $validation, ?string $accountId = null): KsefDemoProfile
{
    $hostKey = str_replace('_', '-', $key);
    $host = "s04-demo-{$hostKey}.fakturownia.pl";
    $accountId ??= s04AccountId($key);
    $endpoint = new KsefDemoEndpoint(
        $key,
        "https://{$host}",
        "token-{$key}",
        KsefDemoEndpoint::fingerprintFor($key, $host, $accountId),
    );
    $govAutoSendMode = $ownership === KsefOwnership::ExplicitSdk ? null : 'pl_companies';
    $validateInvoicesForGov = $validation === KsefValidationMode::BlockInvalid;

    return new KsefDemoProfile(
        $key,
        $ownership,
        $validation,
        $endpoint,
        s04InvoiceTemplate(),
        s04InvoiceTemplate('invalid'),
        'buyer_tax_no',
        'demo',
        $govAutoSendMode,
        $validateInvoicesForGov,
        true,
        true,
        true,
        true,
        true,
        s04AttestedAt(),
        s04AttestationExpiresAt(),
        KsefDemoProfile::settingsChecksumFor(
            $endpoint,
            $ownership,
            $validation,
            'demo',
            $govAutoSendMode,
            $validateInvoicesForGov,
            true,
            true,
            true,
            true,
            true,
            s04AttestedAt(),
            s04AttestationExpiresAt(),
        ),
    );
}

/**
 * @param  null|array{poll_window_ms: int, poll_interval_ms: int, max_search_pages: int, pre_send_observation_window_ms: int, visibility_window_ms: int, visibility_poll_interval_ms: int, connect_timeout_ms: int, request_timeout_ms: int, minimum_pdf_size_bytes: int}  $limits
 * @return array<string, mixed>
 */
function s04SignedProfileData(
    string $key,
    KsefOwnership $ownership,
    KsefValidationMode $validation,
    ?array $limits = null,
): array {
    $profile = s04Profile($key, $ownership, $validation);
    $limits ??= s04LiveLimits();
    $signerId = 's04-test-operator';
    $envelope = $profile->operatorAttestationEnvelope(
        $signerId,
        $limits,
        str_repeat('a', 40),
        s04LaunchManifestSha256(),
        s04AuthorityId(),
        s04AuthorityPolicySha256(),
        s04StoreId(),
        s04StoreIdentitySha256(),
        substr(hash('sha256', 's04-test-run'), 0, 32),
        s04BindingKey($key),
    );
    $signature = sodium_crypto_sign_detached(
        LiveEvidenceAttestationGuard::canonicalJson($envelope),
        s04SigningMaterial()['secret'],
    );

    return [
        'base_url' => $profile->endpoint->baseUrl,
        'token' => $profile->endpoint->token,
        'tenant_fingerprint' => $profile->endpoint->expectedFingerprint,
        'valid_invoice' => $profile->validInvoice,
        'invalid_invoice' => $profile->invalidInvoice,
        'expected_validation_field' => $profile->expectedValidationField,
        'settings' => [
            'ownership' => $profile->ownership->value,
            'validation_mode' => $profile->validationMode->value,
            'ksef_environment' => $profile->expectedKsefEnvironment,
            'gov_auto_send_mode' => $profile->expectedGovAutoSendMode,
            'validate_invoices_for_gov' => $profile->expectedValidateInvoicesForGov,
            'buyer_company' => $profile->expectedBuyerCompany,
            'throwaway_tenant' => $profile->expectedThrowawayTenant,
            'email_delivery_disabled' => $profile->expectedEmailDeliveryDisabled,
            'payments_disabled' => $profile->expectedPaymentsDisabled,
            'webhooks_disabled' => $profile->expectedWebhooksDisabled,
            'settings_checksum' => $profile->settingsChecksum,
        ],
        'operator_attestation' => [
            'envelope' => $envelope,
            'signature' => base64_encode($signature),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $profile
 * @return array<string, mixed>
 */
function s04ResignProfileData(array $profile): array
{
    $attestation = $profile['operator_attestation'] ?? null;
    $envelope = is_array($attestation) ? ($attestation['envelope'] ?? null) : null;

    if (! is_array($envelope)) {
        throw new RuntimeException('The test profile has no operator authorization envelope.');
    }

    $profile['operator_attestation']['signature'] = base64_encode(sodium_crypto_sign_detached(
        LiveEvidenceAttestationGuard::canonicalJson($envelope),
        s04SigningMaterial()['secret'],
    ));

    return $profile;
}

function s04Configuration(): KsefDemoProbeConfiguration
{
    return new KsefDemoProbeConfiguration([
        'explicit_block' => s04Profile('explicit_block', KsefOwnership::ExplicitSdk, KsefValidationMode::BlockInvalid),
        'explicit_persist' => s04Profile('explicit_persist', KsefOwnership::ExplicitSdk, KsefValidationMode::PersistWithErrors),
        'auto_block' => s04Profile('auto_block', KsefOwnership::ProviderAutoSend, KsefValidationMode::BlockInvalid),
        'auto_persist' => s04Profile('auto_persist', KsefOwnership::ProviderAutoSend, KsefValidationMode::PersistWithErrors),
    ], pollWindowMs: 20, pollIntervalMs: 1, maxSearchPages: 2, preSendObservationWindowMs: 2, visibilityWindowMs: 20, visibilityPollIntervalMs: 1);
}

/**
 * @param  null|array{poll_window_ms: int, poll_interval_ms: int, max_search_pages: int, pre_send_observation_window_ms: int, visibility_window_ms: int, visibility_poll_interval_ms: int, connect_timeout_ms: int, request_timeout_ms: int, minimum_pdf_size_bytes: int}  $limits
 */
function s04SignedConfiguration(?array $limits = null): KsefDemoProbeConfiguration
{
    $limits ??= s04LiveLimits();
    $now = new DateTimeImmutable('2026-08-25T08:00:00+00:00');
    $trustedSigners = ['s04-test-operator' => s04SigningMaterial()['public']];
    $profiles = [];

    foreach ([
        'explicit_block' => [KsefOwnership::ExplicitSdk, KsefValidationMode::BlockInvalid],
        'explicit_persist' => [KsefOwnership::ExplicitSdk, KsefValidationMode::PersistWithErrors],
        'auto_block' => [KsefOwnership::ProviderAutoSend, KsefValidationMode::BlockInvalid],
        'auto_persist' => [KsefOwnership::ProviderAutoSend, KsefValidationMode::PersistWithErrors],
    ] as $key => [$ownership, $validation]) {
        $profiles[$key] = KsefDemoProfile::fromArray(
            $key,
            s04SignedProfileData($key, $ownership, $validation, $limits),
            $now,
            $limits,
            $trustedSigners,
            s04BindingKey($key),
            s04LaunchManifestSha256(),
        );
    }

    return new KsefDemoProbeConfiguration(
        $profiles,
        $limits['poll_window_ms'],
        $limits['poll_interval_ms'],
        $limits['max_search_pages'],
        $limits['pre_send_observation_window_ms'],
        $limits['visibility_window_ms'],
        $limits['visibility_poll_interval_ms'],
        $limits['connect_timeout_ms'],
        $limits['request_timeout_ms'],
        $limits['minimum_pdf_size_bytes'],
    );
}

/** @return array<string, mixed> */
function s04ProfileEvidence(string $key): array
{
    $explicit = str_starts_with($key, 'explicit');
    $block = str_ends_with($key, 'block');
    $sha = hash('sha256', "pdf-{$key}");

    return [
        'profile' => ($explicit ? 'explicit_sdk' : 'provider_auto_send').'+'.($block ? 'block_invalid' : 'persist_with_errors'),
        'status_codes' => [
            'account_preflight' => 200,
            'valid_issue' => 201,
            'invalid_issue' => $block ? 422 : 201,
            'invalid_final_read' => $block ? null : 200,
            'preflight_read' => 200,
            'pdf_before_boundary_read' => 200,
            'pre_send_read' => $explicit ? 200 : null,
            'send' => $explicit ? 200 : null,
            'terminal_read' => 200,
            'pdf_before' => 200,
            'pdf_after' => 200,
            'pdf_after_boundary_read' => 200,
            'final_read' => 200,
        ],
        'ksef_statuses' => [
            'issue' => null,
            'before' => $explicit ? 'not_sent' : 'demo_processing',
            'pdf_before_boundary' => $explicit ? 'not_sent' : 'demo_processing',
            'pre_send' => $explicit ? 'not_sent' : null,
            'after_send' => $explicit ? 'demo_processing' : null,
            'terminal' => 'demo_ok',
            'terminal_gov_id_present' => true,
            'terminal_stable' => true,
            'terminal_observations' => 4,
            'pdf_after_boundary' => 'demo_ok',
            'pdf_after_boundary_gov_id_present' => true,
            'final' => 'demo_ok',
            'final_gov_id_present' => true,
            'observed' => $explicit
                ? ['not_sent', 'not_sent', 'demo_processing', 'demo_ok', 'demo_ok', 'demo_ok', 'demo_ok']
                : ['demo_processing', 'demo_ok', 'demo_ok', 'demo_ok', 'demo_ok'],
        ],
        'send_count' => $explicit ? 1 : 0,
        'exact_search' => [
            'valid_count' => 1,
            'invalid_count' => $block ? 0 : 1,
            'all_results_exact' => true,
            'invalid_gov_errors_present' => ! $block,
            'invalid_validation_error_category' => $block ? null : 'expected_validation_leaf_gov_error',
            'invalid_ksef_status' => $block ? null : ($key === 'auto_persist' ? 'demo_ok' : 'not_sent'),
            'invalid_gov_id_present' => $key === 'auto_persist',
            'invalid_terminal_stable' => true,
            'invalid_terminal_observations' => $key === 'auto_persist' ? 2 : 0,
            'invalid_observations' => match (true) {
                $block => [],
                $key === 'auto_persist' => [
                    [
                        'status' => 'demo_ok',
                        'gov_id_hmac_sha256' => hash('sha256', "invalid-gov-{$key}"),
                        'validation_error_category' => 'expected_validation_leaf_gov_error',
                    ],
                    [
                        'status' => 'demo_ok',
                        'gov_id_hmac_sha256' => hash('sha256', "invalid-gov-{$key}"),
                        'validation_error_category' => 'expected_validation_leaf_gov_error',
                    ],
                ],
                default => [[
                    'status' => 'not_sent',
                    'gov_id_hmac_sha256' => null,
                    'validation_error_category' => 'expected_validation_leaf_gov_error',
                ]],
            },
            'invalid_explicit_send_count' => 0,
            'invalid_outcome' => $block
                ? 'rejected_not_persisted'
                : ($key === 'auto_persist' ? 'persisted_with_errors_demo_accepted' : 'persisted_with_errors'),
        ],
        'pdf' => [
            'before' => ['mime' => 'application/pdf', 'size' => KsefDemoProbeConfiguration::DefaultMinimumPdfSizeBytes, 'hmac_sha256' => $sha],
            'after' => ['mime' => 'application/pdf', 'size' => KsefDemoProbeConfiguration::DefaultMinimumPdfSizeBytes, 'hmac_sha256' => $sha],
            'equal' => true,
        ],
    ];
}

/** @return array<string, mixed> */
function s04SafeResult(): array
{
    $profiles = [];

    foreach (KsefDemoProbeConfiguration::profileKeys() as $key) {
        $profiles[$key] = s04ProfileEvidence($key);
    }

    return [
        'contract' => 'fakturownia-ksef-demo-s0.4-v1',
        'run' => [
            'started_at' => '2026-08-25T08:00:00.000000Z',
            'finished_at' => '2026-08-25T08:00:01.000000Z',
            'environment' => 'ksef_demo',
            'launch_manifest_sha256' => s04LaunchManifestSha256(),
        ],
        'probe_limits' => s04LiveLimits(),
        'profiles' => $profiles,
        'capability_0_2' => KsefDemoContractProbe::resolveCapabilityPolicy($profiles),
    ];
}

/** @return array{exception: RuntimeException, secret: non-empty-string, host: non-empty-string} */
function s04CredentialBearingTransportException(): array
{
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $secret = 'S04_UNIQUE_TRANSPORT_TRACE_TOKEN';
    $host = 's04-demo-unique-transport-trace.fakturownia.pl';
    $profile->endpoint->token = $secret;
    $profile->endpoint->host = $host;
    $profile->endpoint->baseUrl = "https://{$host}";
    $transport = s04Transport(
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '413', 'gov_status' => null])],
            endpoint: '/invoices/413.json',
            repeatLast: true,
        ),
        s04Responses(
            SendKsefDemoInvoiceRequest::class,
            KsefDemoLiteralResponse::fail(KsefDemoLiteralFailure::logic("https://{$host}/invoice.json?api_token={$secret}")),
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);
    $invokeEnsureAccepted = Closure::bind(
        static fn (): array => $probe->ensureAccepted($profile, '413', 'not_sent'),
        null,
        KsefDemoContractProbe::class,
    );

    try {
        $invokeEnsureAccepted();
    } catch (RuntimeException $exception) {
        return [
            'exception' => $exception,
            'secret' => $secret,
            'host' => $host,
        ];
    }

    throw new RuntimeException('The credential-bearing transport fixture did not fail.');
}

/**
 * @return array{
 *     previous_removed: bool,
 *     public_text_redacted: bool,
 *     complete_trace_graph_redacted: bool,
 *     sensitive_trace_frames: list<string>
 * }
 */
function s04CredentialBearingTransportExceptionSecurityReport(): array
{
    $result = s04CredentialBearingTransportException();
    $exception = $result['exception'];
    $publicSensitiveValues = [$result['secret'], $result['host'], 'api_token'];
    $traceSensitiveValues = [$result['secret'], $result['host']];
    $publicTextRedacted = true;

    foreach ($publicSensitiveValues as $sensitiveValue) {
        if (str_contains((string) $exception, $sensitiveValue)) {
            $publicTextRedacted = false;
            break;
        }
    }

    $sensitiveTraceFrames = [];

    foreach ($exception->getTrace() as $index => $frame) {
        foreach (['secret' => $result['secret'], 'host' => $result['host']] as $label => $sensitiveValue) {
            if (! s04PrintedValueContainsSensitiveValue($frame, [$sensitiveValue])) {
                continue;
            }

            $sensitiveTraceFrames[] = sprintf(
                '#%d %s%s [%s]',
                $index,
                is_string($frame['class'] ?? null) ? $frame['class'].'::' : '',
                $frame['function'],
                $label,
            );
        }
    }

    return [
        'previous_removed' => $exception->getPrevious() === null,
        'public_text_redacted' => $publicTextRedacted,
        'complete_trace_graph_redacted' => ! s04PrintedValueContainsSensitiveValue(
            $exception->getTrace(),
            $traceSensitiveValues,
        ),
        'sensitive_trace_frames' => $sensitiveTraceFrames,
    ];
}

it('requires the exact isolated four-profile KSeF DEMO matrix', function (): void {
    $configuration = s04Configuration();
    $profiles = $configuration->profiles;
    unset($profiles['auto_persist']);

    expect(array_keys($configuration->profiles))->toBe(KsefDemoProbeConfiguration::profileKeys())
        ->and(fn () => new KsefDemoProbeConfiguration($profiles))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new KsefDemoEndpoint('explicit_block', 'https://production.fakturownia.pl', 'token', str_repeat('a', 64)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new KsefDemoProbeConfiguration(
            $configuration->profiles,
            pollWindowMs: 1,
            pollIntervalMs: 2,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new KsefDemoProbeConfiguration(
            $configuration->profiles,
            pollIntervalMs: 1,
            preSendObservationWindowMs: 1,
            visibilityWindowMs: 1,
            visibilityPollIntervalMs: 2,
        ))->toThrow(InvalidArgumentException::class);
});

it('rejects endpoint URLs with authority or locator extras', function (string $url): void {
    expect(fn () => new KsefDemoEndpoint(
        'explicit_block',
        $url,
        'token',
        str_repeat('a', 64),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'port' => 'https://s04-demo-explicit-block.fakturownia.pl:443',
    'userinfo' => 'https://operator:secret@s04-demo-explicit-block.fakturownia.pl',
    'query' => 'https://s04-demo-explicit-block.fakturownia.pl?api_token=secret',
    'fragment' => 'https://s04-demo-explicit-block.fakturownia.pl#account',
]);

it('rejects non-canonical remote document IDs before endpoint construction', function (string $documentId): void {
    expect(fn () => new ReadKsefDemoInvoiceRequest('token', $documentId))->toThrow(RuntimeException::class)
        ->and(fn () => new SendKsefDemoInvoiceRequest('token', $documentId))->toThrow(RuntimeException::class)
        ->and(fn () => new DownloadKsefDemoPdfRequest('token', $documentId))->toThrow(RuntimeException::class);
})->with([
    'zero' => '0',
    'negative sign' => '-1',
    'positive sign' => '+1',
    'leading zero' => '01',
    'leading whitespace' => ' 1',
    'trailing whitespace' => '1 ',
    'oversized decimal' => str_repeat('9', 20),
    'path and query injection' => '../account?send_to_ksef=yes',
    'query injection' => '1?send_to_ksef=yes',
]);

it('requires a fresh trusted Ed25519 authorization bound to the exact S0.4 profile and harness', function (): void {
    $key = 'explicit_block';
    $limits = s04LiveLimits();
    $profile = s04SignedProfileData($key, KsefOwnership::ExplicitSdk, KsefValidationMode::BlockInvalid, $limits);
    $trustedSigners = ['s04-test-operator' => s04SigningMaterial()['public']];
    $now = new DateTimeImmutable('2026-08-25T08:00:00+00:00');
    $configured = KsefDemoProfile::fromArray($key, $profile, $now, $limits, $trustedSigners, s04BindingKey($key), s04LaunchManifestSha256());

    $wrongCode = $profile;
    $wrongCode['operator_attestation']['envelope']['harness']['code_sha256'] = str_repeat('b', 64);
    $wrongCode = s04ResignProfileData($wrongCode);

    $wrongLaunchManifest = $profile;
    $wrongLaunchManifest['operator_attestation']['envelope']['harness']['launch_manifest_sha256'] = str_repeat('b', 64);
    $wrongLaunchManifest = s04ResignProfileData($wrongLaunchManifest);

    $expired = $profile;
    $expired['operator_attestation']['envelope']['issued_at'] = '2026-08-24T00:00:00.000000Z';
    $expired['operator_attestation']['envelope']['expires_at'] = '2026-08-25T07:59:59.000000Z';
    $expired = s04ResignProfileData($expired);

    $relativeDate = $profile;
    $relativeDate['operator_attestation']['envelope']['issued_at'] = 'tomorrow';
    $relativeDate = s04ResignProfileData($relativeDate);

    $evidenceCrossUse = $profile;
    $evidenceCrossUse['operator_attestation']['envelope']['contract'] = LiveEvidenceAttestationGuard::EvidenceContract;
    $evidenceCrossUse = s04ResignProfileData($evidenceCrossUse);

    $tamperedSafety = $profile;
    $tamperedSafety['settings']['webhooks_disabled'] = false;

    expect($configured->key)->toBe($key)
        ->and($configured->verifiedAuthorizationSha256())->toMatch('/^[a-f0-9]{64}$/')
        ->and(fn () => KsefDemoProfile::fromArray($key, $profile, $now, $limits, [], s04BindingKey($key), s04LaunchManifestSha256()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => KsefDemoProfile::fromArray($key, $wrongCode, $now, $limits, $trustedSigners, s04BindingKey($key), s04LaunchManifestSha256()))->toThrow(InvalidArgumentException::class, 'current code')
        ->and(fn () => KsefDemoProfile::fromArray($key, $wrongLaunchManifest, $now, $limits, $trustedSigners, s04BindingKey($key), s04LaunchManifestSha256()))->toThrow(InvalidArgumentException::class, 'supervised launch manifest')
        ->and(fn () => KsefDemoProfile::fromArray($key, $expired, $now, $limits, $trustedSigners, s04BindingKey($key), s04LaunchManifestSha256()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => KsefDemoProfile::fromArray($key, $relativeDate, $now, $limits, $trustedSigners, s04BindingKey($key), s04LaunchManifestSha256()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => KsefDemoProfile::fromArray($key, $evidenceCrossUse, $now, $limits, $trustedSigners, s04BindingKey($key), s04LaunchManifestSha256()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => KsefDemoProfile::fromArray($key, $tamperedSafety, $now, $limits, $trustedSigners, s04BindingKey($key), s04LaunchManifestSha256()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => KsefDemoProfile::fromArray('auto_block', $profile, $now, $limits, $trustedSigners, s04BindingKey($key), s04LaunchManifestSha256()))->toThrow(InvalidArgumentException::class);
});

it('enforces ownership, validation and issue separation locally', function (): void {
    $explicit = s04Profile('explicit_block', KsefOwnership::ExplicitSdk, KsefValidationMode::BlockInvalid);
    $auto = s04Profile('auto_block', KsefOwnership::ProviderAutoSend, KsefValidationMode::BlockInvalid);
    $invalidWithExtraDelta = s04InvoiceTemplate('invalid');
    $invalidWithExtraDelta['currency'] = 'EUR';
    $invalidWrongField = s04InvoiceTemplate();
    $invalidWrongField['currency'] = 'EUR';
    $invalidNonEmptyNip = s04InvoiceTemplate('invalid');
    $invalidNonEmptyNip['buyer_tax_no'] = '2222222222';
    $nonCompanyValid = s04InvoiceTemplate();
    $nonCompanyValid['buyer_company'] = false;
    $nonCompanyInvalid = s04InvoiceTemplate('invalid');
    $nonCompanyInvalid['buyer_company'] = false;

    expect($explicit->expectedKsefSendCount())->toBe(1)
        ->and($auto->expectedKsefSendCount())->toBe(0)
        ->and(fn () => new CreateKsefDemoInvoiceRequest('token', ['gov_save_and_send' => true]))->toThrow(RuntimeException::class)
        ->and(fn () => new KsefDemoProfile(
            'auto_block',
            KsefOwnership::ProviderAutoSend,
            KsefValidationMode::BlockInvalid,
            $auto->endpoint,
            s04InvoiceTemplate(),
            s04InvoiceTemplate('invalid'),
            'buyer_tax_no',
            'demo',
            null,
            true,
            true,
            true,
            true,
            true,
            true,
            s04AttestedAt(),
            s04AttestationExpiresAt(),
            KsefDemoProfile::settingsChecksumFor(
                $auto->endpoint,
                KsefOwnership::ProviderAutoSend,
                KsefValidationMode::BlockInvalid,
                'demo',
                null,
                true,
                true,
                true,
                true,
                true,
                true,
                s04AttestedAt(),
                s04AttestationExpiresAt(),
            ),
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new KsefDemoProfile(
            'explicit_block',
            KsefOwnership::ExplicitSdk,
            KsefValidationMode::BlockInvalid,
            $explicit->endpoint,
            s04InvoiceTemplate(),
            $invalidNonEmptyNip,
            'buyer_tax_no',
            'demo',
            null,
            true,
            true,
            true,
            true,
            true,
            true,
            s04AttestedAt(),
            s04AttestationExpiresAt(),
            $explicit->settingsChecksum,
        ))->toThrow(InvalidArgumentException::class, 'exactly empty buyer_tax_no')
        ->and(fn () => new KsefDemoProfile(
            'explicit_block',
            KsefOwnership::ExplicitSdk,
            KsefValidationMode::BlockInvalid,
            $explicit->endpoint,
            $nonCompanyValid,
            $nonCompanyInvalid,
            'buyer_tax_no',
            'demo',
            null,
            true,
            false,
            true,
            true,
            true,
            true,
            s04AttestedAt(),
            s04AttestationExpiresAt(),
            KsefDemoProfile::settingsChecksumFor(
                $explicit->endpoint,
                KsefOwnership::ExplicitSdk,
                KsefValidationMode::BlockInvalid,
                'demo',
                null,
                true,
                false,
                true,
                true,
                true,
                true,
                s04AttestedAt(),
                s04AttestationExpiresAt(),
            ),
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new KsefDemoProfile(
            'explicit_block',
            KsefOwnership::ExplicitSdk,
            KsefValidationMode::BlockInvalid,
            $explicit->endpoint,
            s04InvoiceTemplate(),
            $invalidWithExtraDelta,
            'buyer_tax_no',
            'demo',
            null,
            true,
            true,
            true,
            true,
            true,
            true,
            s04AttestedAt(),
            s04AttestationExpiresAt(),
            $explicit->settingsChecksum,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new KsefDemoProfile(
            'explicit_block',
            KsefOwnership::ExplicitSdk,
            KsefValidationMode::BlockInvalid,
            $explicit->endpoint,
            s04InvoiceTemplate(),
            $invalidWrongField,
            'currency',
            'demo',
            null,
            true,
            true,
            true,
            true,
            true,
            true,
            s04AttestedAt(),
            s04AttestationExpiresAt(),
            $explicit->settingsChecksum,
        ))->toThrow(InvalidArgumentException::class);
});

it('rejects additional invoice and position fields before a mutating request can be built', function (): void {
    $method = new ReflectionMethod(KsefDemoProfile::class, 'validateTemplate');
    $invoiceField = s04InvoiceTemplate();
    $invoiceField['status'] = 'paid';
    $positionField = s04InvoiceTemplate();
    $positionField['positions'][0]['client_id'] = 123;

    expect(fn () => $method->invoke(null, $invoiceField, true))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $method->invoke(null, $positionField, true))->toThrow(InvalidArgumentException::class);
});

it('redacts KSeF credentials identities and PII from rejected-contract traces when arguments are enabled', function (): void {
    $previousSetting = ini_get('zend.exception_ignore_args');
    $tokenSentinel = 'S04_SENTINEL_TOKEN';
    $hostSentinel = 's04-demo-sentinel-trace.fakturownia.pl';
    $piiSentinel = 'S04_SENTINEL_PII';
    $traceLeaks = [];

    try {
        ini_set('zend.exception_ignore_args', '0');
        $endpoint = new KsefDemoEndpoint(
            'explicit_block',
            "https://{$hostSentinel}",
            $tokenSentinel,
            str_repeat('a', 64),
        );
        $valid = s04InvoiceTemplate();
        $valid['buyer_name'] = $piiSentinel;
        $invalid = $valid;
        $invalid['buyer_tax_no'] = '2222222222';

        try {
            new KsefDemoProfile(
                'explicit_block',
                KsefOwnership::ExplicitSdk,
                KsefValidationMode::BlockInvalid,
                $endpoint,
                $valid,
                $invalid,
                'buyer_tax_no',
                'demo',
                null,
                true,
                true,
                true,
                true,
                true,
                true,
                s04AttestedAt(),
                s04AttestationExpiresAt(),
                str_repeat('b', 64),
            );
        } catch (Throwable $exception) {
            $traceLeaks[] = s04ThrowableTraceContainsSensitiveValue(
                $exception,
                [$tokenSentinel, $hostSentinel, $piiSentinel],
            );
        }

        try {
            KsefDemoFixtureGuard::assertSafe(
                ['leak' => $piiSentinel],
                [$tokenSentinel, $hostSentinel, $piiSentinel],
            );
        } catch (Throwable $exception) {
            $traceLeaks[] = s04ThrowableTraceContainsSensitiveValue(
                $exception,
                [$tokenSentinel, $hostSentinel, $piiSentinel],
            );
        }
    } finally {
        if (is_string($previousSetting)) {
            ini_set('zend.exception_ignore_args', $previousSetting);
        }
    }

    expect($traceLeaks)->toBe([false, false]);
});

it('keeps every S0.4 trust-boundary payload parameter marked sensitive', function (): void {
    $sensitiveParameters = [
        [KsefDemoProbeConfiguration::class, '__construct', ['profiles']],
        [KsefDemoProfile::class, '__construct', ['endpoint', 'validInvoice', 'invalidInvoice', 'settingsChecksum']],
        [KsefDemoProfile::class, 'fromArray', ['data', 'bindingKey']],
        [KsefDemoProfile::class, 'validateTemplate', ['template']],
        [KsefDemoProfile::class, 'forbidUnsafeFields', ['template']],
        [KsefDemoEndpoint::class, '__construct', ['baseUrl', 'token', 'expectedFingerprint']],
        [KsefDemoEndpoint::class, 'verifyAccountId', ['accountId']],
        [KsefDemoContractProbe::class, '__construct', ['configuration', 'inMemoryTransport', 'testClock']],
        [KsefDemoContractProbe::class, 'forTesting', ['configuration', 'inMemoryTransport', 'clock']],
        [KsefDemoContractProbe::class, 'forAuthorizedTesting', ['configuration', 'inMemoryTransport', 'consumptionAuthority', 'trustedOperatorSigners', 'trustedConsumptionAuthorities', 'clock']],
        [KsefDemoContractProbe::class, 'preflight', ['profile']],
        [KsefDemoContractProbe::class, 'profileEvidence', ['profile', 'runEvidenceKey']],
        [KsefDemoContractProbe::class, 'validationEvidence', ['profile', 'invalidInvoice', 'invalidOid', 'runEvidenceKey']],
        [KsefDemoContractProbe::class, 'ensureAccepted', ['profile', 'documentId']],
        [KsefDemoContractProbe::class, 'send', ['profile', 'request']],
        [KsefDemoContractProbe::class, 'jsonObject', ['response']],
        [KsefDemoContractProbe::class, 'strictSnapshot', ['response', 'expectedDocumentId']],
        [KsefDemoFixtureGuard::class, 'assertSafe', ['result', 'sensitive']],
        [KsefDemoFixtureGuard::class, 'assertSafeForTesting', ['result', 'sensitive']],
        [KsefDemoFixtureGuard::class, 'assertSafeEvidence', ['result', 'sensitive']],
        [KsefDemoFixtureGuard::class, 'exactKeys', ['data']],
        [KsefDemoConnector::class, '__construct', ['baseUrl']],
        [AccountKsefDemoRequest::class, '__construct', ['token']],
        [CreateKsefDemoInvoiceRequest::class, '__construct', ['token', 'invoice']],
        [SearchKsefDemoInvoicesRequest::class, '__construct', ['token', 'oid']],
        [ReadKsefDemoInvoiceRequest::class, '__construct', ['token', 'documentId']],
        [SendKsefDemoInvoiceRequest::class, '__construct', ['token', 'documentId']],
        [DownloadKsefDemoPdfRequest::class, '__construct', ['token', 'documentId']],
    ];

    foreach ($sensitiveParameters as [$class, $method, $parameterNames]) {
        $parameters = (new ReflectionMethod($class, $method))->getParameters();
        $parametersByName = [];

        foreach ($parameters as $parameter) {
            $parametersByName[$parameter->getName()] = $parameter;
        }

        foreach ($parameterNames as $parameterName) {
            expect($parametersByName[$parameterName]->getAttributes(SensitiveParameter::class))
                ->toHaveCount(1, "{$class}::{$method}(\${$parameterName}) must stay sensitive.");
        }
    }
});

it('builds issue and ensure-accepted as separate requests', function (): void {
    $issue = new CreateKsefDemoInvoiceRequest('secret-token', ['oid' => 'internal-oid']);
    $ensureAccepted = new SendKsefDemoInvoiceRequest('secret-token', '10');
    $issueBody = (new ReflectionMethod($issue, 'defaultBody'))->invoke($issue);
    $issueQuery = (new ReflectionMethod($issue, 'defaultQuery'))->invoke($issue);
    $ensureAcceptedQuery = (new ReflectionMethod($ensureAccepted, 'defaultQuery'))->invoke($ensureAccepted);
    $connectorConfig = (new ReflectionMethod(KsefDemoConnector::class, 'defaultConfig'))
        ->invoke(new KsefDemoConnector('https://s04-demo-explicit-block.fakturownia.pl'));

    expect($issueBody)->toBe([
        'api_token' => 'secret-token',
        'invoice' => ['oid' => 'internal-oid'],
    ])->and($issueQuery)->toBe([])
        ->and($ensureAccepted)->not->toBeInstanceOf(HasBody::class)
        ->and($ensureAcceptedQuery['send_to_ksef'] ?? null)->toBe('yes')
        ->and($connectorConfig[RequestOptions::ALLOW_REDIRECTS] ?? null)->toBeFalse();
});

it('observes exact search through the visibility boundary and rejects non-list JSON', function (): void {
    $baseConfiguration = s04Configuration();
    $configuration = new KsefDemoProbeConfiguration(
        $baseConfiguration->profiles,
        pollWindowMs: 20,
        pollIntervalMs: 1,
        maxSearchPages: 2,
        preSendObservationWindowMs: 2,
        visibilityWindowMs: 50,
        visibilityPollIntervalMs: 1,
    );
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'exactSearch');
    $snapshotMethod = new ReflectionMethod(KsefDemoContractProbe::class, 'searchSnapshot');
    $lateTransport = s04Transport(s04Responses(
        SearchKsefDemoInvoicesRequest::class,
        s04Response([['id' => '10', 'oid' => 'late-duplicate']]),
        ...array_fill(0, 100, s04Response([
            ['id' => '10', 'oid' => 'late-duplicate'],
            ['id' => '11', 'oid' => 'late-duplicate'],
        ])),
    ));
    $disappearingTransport = s04Transport(s04Responses(
        SearchKsefDemoInvoicesRequest::class,
        s04Response([['id' => '10', 'oid' => 'disappearing']]),
        ...array_fill(0, 100, s04Response([])),
    ));
    $paginatedTransport = s04Transport(s04Responses(
        SearchKsefDemoInvoicesRequest::class,
        s04Response(array_map(
            static fn (int $id): array => ['id' => (string) $id, 'oid' => 'paginated'],
            range(1, 100),
        )),
        s04Response([['id' => '101', 'oid' => 'paginated']]),
    ));
    $boundedDocuments = array_map(
        static fn (int $id): array => ['id' => (string) $id, 'oid' => 'bounded-pages'],
        range(1, 100),
    );
    $boundedTransport = s04Transport(s04Responses(
        SearchKsefDemoInvoicesRequest::class,
        KsefDemoLiteralResponse::delayed($boundedDocuments, 60_000),
        KsefDemoLiteralResponse::delayed($boundedDocuments, 60_000),
    ));
    $itemErrorTransport = s04Transport(s04Responses(
        SearchKsefDemoInvoicesRequest::class,
        s04Response([['id' => '10', 'oid' => 'item-error', 'error' => 'unauthorized']]),
    ));
    $malformedTransport = s04Transport(s04Responses(
        SearchKsefDemoInvoicesRequest::class,
        s04Response(['unexpected' => 'object']),
    ));
    $partialTransport = s04Transport(s04Responses(
        SearchKsefDemoInvoicesRequest::class,
        s04Response([['id' => '10', 'oid' => 'partial-response']], 206),
    ));

    $lateDuplicate = $method->invoke(KsefDemoContractProbe::forTesting($configuration, $lateTransport), $profile, 'late-duplicate', '10');
    $disappearing = $method->invoke(KsefDemoContractProbe::forTesting($configuration, $disappearingTransport), $profile, 'disappearing', '10');
    $paginated = $snapshotMethod->invoke(KsefDemoContractProbe::forTesting($configuration, $paginatedTransport), $profile, 'paginated');
    $bounded = $method->invoke(KsefDemoContractProbe::forTesting($configuration, $boundedTransport), $profile, 'bounded-pages', '1');
    $itemError = $snapshotMethod->invoke(KsefDemoContractProbe::forTesting($configuration, $itemErrorTransport), $profile, 'item-error');

    expect($lateDuplicate)->toBe([
        'count' => 2,
        'exact' => false,
    ])->and($disappearing)->toBe([
        'count' => 1,
        'exact' => false,
    ])->and($lateTransport->requestCount(SearchKsefDemoInvoicesRequest::class))->toBeGreaterThan(1)
        ->and($disappearingTransport->requestCount(SearchKsefDemoInvoicesRequest::class))->toBeGreaterThan(1)
        ->and($paginated['documents'])->toHaveCount(101)
        ->and($paginated['exact'])->toBeTrue()
        ->and($paginated['complete'])->toBeTrue()
        ->and($paginatedTransport->requestCount(SearchKsefDemoInvoicesRequest::class))->toBe(2)
        ->and($bounded)->toBe(['count' => 100, 'exact' => false])
        ->and($boundedTransport->requestCount(SearchKsefDemoInvoicesRequest::class))->toBe(2)
        ->and($itemError)->toBe(['documents' => [], 'exact' => false, 'complete' => true])
        ->and(fn () => $method->invoke(KsefDemoContractProbe::forTesting($configuration, $malformedTransport), $profile, 'malformed-shape'))->toThrow(RuntimeException::class)
        ->and(fn () => $method->invoke(KsefDemoContractProbe::forTesting($configuration, $partialTransport), $profile, 'partial-response', '10'))
        ->toThrow(RuntimeException::class, 'complete 200 snapshot');
});

it('stops before invalid issue or send when valid exact-search evidence is not unique', function (): void {
    $baseConfiguration = s04Configuration();
    $configuration = new KsefDemoProbeConfiguration(
        $baseConfiguration->profiles,
        pollWindowMs: 20,
        pollIntervalMs: 1,
        maxSearchPages: 2,
        preSendObservationWindowMs: 2,
        visibilityWindowMs: 20,
        visibilityPollIntervalMs: 1,
    );
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'profileEvidence');
    $transport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '408'], 201)),
        s04Responses(
            SearchKsefDemoInvoicesRequest::class,
            ...array_fill(0, 100, s04Response([
                ['id' => '408', 'oid' => 's04-explicit_block-000000000000-valid'],
                ['id' => '409', 'oid' => 's04-explicit_block-000000000000-valid'],
            ])),
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, 200, random_bytes(32)))
        ->toThrow(RuntimeException::class, 'identity evidence is incomplete')
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(0)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(0);
});

it('rejects a valid-invoice duplicate that appears after the initial visibility scan', function (): void {
    $baseConfiguration = s04Configuration();
    $configuration = new KsefDemoProbeConfiguration(
        $baseConfiguration->profiles,
        pollWindowMs: 10,
        pollIntervalMs: 1,
        maxSearchPages: 2,
        preSendObservationWindowMs: 2,
        visibilityWindowMs: 10,
        visibilityPollIntervalMs: 1,
    );
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'profileEvidence');
    $validOid = s04Oid('explicit_block');
    $invalidOid = s04Oid('explicit_block', true);
    $transport = s04Transport(
        s04Route(CreateKsefDemoInvoiceRequest::class, [s04Response(['id' => '416'], 201)], body: ['invoice' => ['oid' => $validOid]]),
        s04Route(CreateKsefDemoInvoiceRequest::class, [s04Response(s04BlockInvalidResponse(), 422)], body: ['invoice' => ['oid' => $invalidOid]]),
        s04Route(
            SearchKsefDemoInvoicesRequest::class,
            [s04Response([
                ['id' => '416', 'oid' => $validOid],
                ['id' => '417', 'oid' => $validOid],
            ])],
            query: ['oid' => $validOid],
            repeatLast: true,
            afterRequestClass: CreateKsefDemoInvoiceRequest::class,
            minimumRequestCount: 2,
        ),
        s04Route(
            SearchKsefDemoInvoicesRequest::class,
            [s04Response([['id' => '416', 'oid' => $validOid]]), s04Response([['id' => '416', 'oid' => $validOid]])],
            query: ['oid' => $validOid],
            repeatLast: true,
        ),
        s04Route(SearchKsefDemoInvoicesRequest::class, [s04Response([])], query: ['oid' => $invalidOid], repeatLast: true),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '416', 'gov_status' => 'demo_ok', 'gov_id' => 'late-duplicate-gov-id'])],
            endpoint: '/invoices/416.json',
            repeatLast: true,
            afterRequestClass: SendKsefDemoInvoiceRequest::class,
            minimumRequestCount: 1,
        ),
        s04Route(ReadKsefDemoInvoiceRequest::class, [s04Response(['id' => '416', 'gov_status' => null])], endpoint: '/invoices/416.json', repeatLast: true),
        s04Responses(SendKsefDemoInvoiceRequest::class, s04Response(['id' => '416', 'gov_status' => 'demo_processing'])),
        s04Route(DownloadKsefDemoPdfRequest::class, [s04Response(s04Pdf('late-duplicate'), 200, ['Content-Type' => 'application/pdf'])], repeatLast: true),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, 200, random_bytes(32)))
        ->toThrow(RuntimeException::class, 'final KSeF DEMO valid-invoice identity')
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(2)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBeGreaterThan(1);
});

it('re-scans invalid identity and status at the final boundary', function (KsefValidationMode $validationMode, bool $lateStatusChange): void {
    $baseConfiguration = s04Configuration();
    $configuration = new KsefDemoProbeConfiguration(
        $baseConfiguration->profiles,
        pollWindowMs: 1_000,
        pollIntervalMs: 10,
        maxSearchPages: 2,
        preSendObservationWindowMs: 10,
        visibilityWindowMs: 500,
        visibilityPollIntervalMs: 10,
    );
    $profile = $configuration->profiles[$validationMode === KsefValidationMode::BlockInvalid ? 'explicit_block' : 'explicit_persist'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'profileEvidence');
    $profileKey = $validationMode === KsefValidationMode::BlockInvalid ? 'explicit_block' : 'explicit_persist';
    $validOid = s04Oid($profileKey);
    $invalidOid = s04Oid($profileKey, true);
    $invalidInitialSearch = $validationMode === KsefValidationMode::BlockInvalid
        ? []
        : [['id' => '417', 'oid' => $invalidOid]];
    $invalidFinalSearch = $validationMode === KsefValidationMode::BlockInvalid
        ? [['id' => '417', 'oid' => $invalidOid]]
        : ($lateStatusChange
            ? [['id' => '417', 'oid' => $invalidOid]]
            : [
                ['id' => '417', 'oid' => $invalidOid],
                ['id' => '418', 'oid' => $invalidOid],
            ]);
    $transport = s04Transport(
        s04Route(
            SearchKsefDemoInvoicesRequest::class,
            [s04Response($invalidFinalSearch)],
            query: ['oid' => $invalidOid],
            repeatLast: true,
            afterRequestClass: SendKsefDemoInvoiceRequest::class,
            minimumRequestCount: 1,
        ),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response([
                'id' => '417',
                'gov_status' => 'demo_processing',
                'gov_error_messages' => ['NIP nabywcy - nie może być puste'],
            ])],
            endpoint: '/invoices/417.json',
            repeatLast: true,
            afterRequestClass: SendKsefDemoInvoiceRequest::class,
            minimumRequestCount: $lateStatusChange ? 1 : 2,
        ),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '416', 'gov_status' => 'demo_ok', 'gov_id' => 'stable-valid-gov-id'])],
            endpoint: '/invoices/416.json',
            repeatLast: true,
            afterRequestClass: SendKsefDemoInvoiceRequest::class,
            minimumRequestCount: 1,
        ),
        s04Route(
            CreateKsefDemoInvoiceRequest::class,
            [$validationMode === KsefValidationMode::BlockInvalid
                ? s04Response(s04BlockInvalidResponse(), 422)
                : s04Response(['id' => '417'], 201)],
            body: ['invoice' => ['oid' => $invalidOid]],
        ),
        s04Route(
            CreateKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '416'], 201)],
            body: ['invoice' => ['oid' => $validOid]],
        ),
        s04Route(
            SearchKsefDemoInvoicesRequest::class,
            [s04Response($invalidInitialSearch)],
            query: ['oid' => $invalidOid],
            repeatLast: true,
        ),
        s04Route(
            SearchKsefDemoInvoicesRequest::class,
            [s04Response([['id' => '416', 'oid' => $validOid]])],
            query: ['oid' => $validOid],
            repeatLast: true,
        ),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response([
                'id' => '417',
                'gov_status' => null,
                'gov_error_messages' => ['NIP nabywcy - nie może być puste'],
            ])],
            endpoint: '/invoices/417.json',
            repeatLast: true,
        ),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '416', 'gov_status' => null])],
            endpoint: '/invoices/416.json',
            repeatLast: true,
        ),
        s04Responses(SendKsefDemoInvoiceRequest::class, s04Response(['id' => '416', 'gov_status' => 'demo_processing'])),
        s04Route(
            DownloadKsefDemoPdfRequest::class,
            [s04Response(s04Pdf('invalid-final-rescan'), 200, ['Content-Type' => 'application/pdf'])],
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    $expectedFailure = $lateStatusChange
        ? 'final persisted invalid-invoice status'
        : 'final KSeF DEMO invalid-invoice identity';

    expect(fn () => $method->invoke($probe, $profile, 200, random_bytes(32)))
        ->toThrow(RuntimeException::class, $expectedFailure)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(SearchKsefDemoInvoicesRequest::class))->toBeGreaterThan(1);
})->with([
    'BlockInvalid late persistence' => [KsefValidationMode::BlockInvalid, false],
    'PersistWithErrors late duplicate' => [KsefValidationMode::PersistWithErrors, false],
    'PersistWithErrors late status change' => [KsefValidationMode::PersistWithErrors, true],
]);

it('polls bounded PersistWithErrors evidence without an explicit send', function (): void {
    $baseConfiguration = s04Configuration();
    $configuration = new KsefDemoProbeConfiguration(
        $baseConfiguration->profiles,
        pollWindowMs: 20,
        pollIntervalMs: 1,
        maxSearchPages: 2,
        preSendObservationWindowMs: 2,
        visibilityWindowMs: 200,
        visibilityPollIntervalMs: 1,
    );
    $profile = $configuration->profiles['auto_persist'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'validationEvidence');
    $transport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '401'], 201)),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [
                s04Response([
                    'id' => '401',
                    'gov_status' => 'demo_ok',
                    'gov_id' => 'demo-invalid-401',
                    'gov_error_messages' => [],
                ]),
                s04Response([
                    'id' => '401',
                    'gov_status' => 'demo_ok',
                    'gov_id' => 'demo-invalid-401',
                    'gov_error_messages' => ['buyer_tax_no_invalid'],
                ]),
            ],
            endpoint: '/invoices/401.json',
            repeatLast: true,
        ),
        s04Route(
            SearchKsefDemoInvoicesRequest::class,
            [s04Response([['id' => '401', 'oid' => 'delayed-errors']])],
            query: ['oid' => 'delayed-errors'],
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    $evidence = $method->invoke($probe, $profile, s04InvoiceTemplate('invalid'), 'delayed-errors', s04BindingKey('delayed-errors'));

    expect($evidence)->toBeArray()
        ->and($evidence['gov_errors_present'])->toBeTrue()
        ->and($evidence['outcome'])->toBe('persisted_with_errors_demo_accepted')
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBeGreaterThan(1)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(0);

    $missingTransport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '402'], 201)),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '402', 'gov_status' => 'demo_ok', 'gov_id' => 'demo-invalid-402', 'gov_error_messages' => []])],
            endpoint: '/invoices/402.json',
            repeatLast: true,
        ),
    );
    $missingProbe = KsefDemoContractProbe::forTesting($configuration, $missingTransport);

    expect(fn () => $method->invoke($missingProbe, $profile, s04InvoiceTemplate('invalid'), 'missing-errors', s04BindingKey('missing-errors')))
        ->toThrow(RuntimeException::class, 'bounded KSeF validation-error evidence');

    expect($missingTransport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBeGreaterThan(1);

    $regressingTransport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '403'], 201)),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [
                s04Response([
                    'id' => '403',
                    'gov_status' => 'demo_ok',
                    'gov_id' => 'first-terminal-id',
                    'gov_error_messages' => ['buyer_tax_no_invalid'],
                ]),
                s04Response([
                    'id' => '403',
                    'gov_status' => 'demo_processing',
                    'gov_error_messages' => ['buyer_tax_no_invalid'],
                ]),
            ],
            endpoint: '/invoices/403.json',
            repeatLast: true,
        ),
    );
    $regressingProbe = KsefDemoContractProbe::forTesting($configuration, $regressingTransport);

    expect(fn () => $method->invoke($regressingProbe, $profile, s04InvoiceTemplate('invalid'), 'regressing-terminal', s04BindingKey('regressing-terminal')))
        ->toThrow(RuntimeException::class, 'status regressed')
        ->and($regressingTransport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBeGreaterThan(1);
});

it('requires and classifies a stable ProviderAutoSend PersistWithErrors terminal outcome', function (): void {
    $baseConfiguration = s04Configuration();
    $configuration = new KsefDemoProbeConfiguration(
        $baseConfiguration->profiles,
        pollWindowMs: 10,
        pollIntervalMs: 1,
        maxSearchPages: 2,
        preSendObservationWindowMs: 2,
        visibilityWindowMs: 10,
        visibilityPollIntervalMs: 1,
    );
    $profile = $configuration->profiles['auto_persist'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'validationEvidence');
    $transport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '405'], 201)),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [
                s04Response([
                    'id' => '405',
                    'gov_status' => 'demo_processing',
                    'gov_id' => null,
                    'gov_error_messages' => ['NIP nabywcy - nie może być puste'],
                ]),
                s04Response([
                    'id' => '405',
                    'gov_status' => 'demo_send_error',
                    'gov_id' => null,
                    'gov_error_messages' => ['NIP nabywcy - nie może być puste'],
                ]),
            ],
            endpoint: '/invoices/405.json',
            repeatLast: true,
        ),
        s04Route(
            SearchKsefDemoInvoicesRequest::class,
            [s04Response([['id' => '405', 'oid' => 'documented-send-error']])],
            query: ['oid' => 'documented-send-error'],
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);
    $evidence = $method->invoke(
        $probe,
        $profile,
        s04InvoiceTemplate('invalid'),
        'documented-send-error',
        s04BindingKey('documented-send-error'),
    );

    expect($evidence)->toBeArray()
        ->and($evidence['outcome'])->toBe('persisted_with_errors_demo_rejected')
        ->and($evidence['ksef_status'])->toBe('demo_send_error')
        ->and($evidence['gov_id_present'])->toBeFalse()
        ->and($evidence['terminal_stable'])->toBeTrue()
        ->and($evidence['terminal_observations'])->toBeGreaterThanOrEqual(2)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(0);

    $processingProbe = KsefDemoContractProbe::forTesting($configuration, s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '406'], 201)),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response([
                'id' => '406',
                'gov_status' => 'demo_processing',
                'gov_id' => null,
                'gov_error_messages' => ['NIP nabywcy - nie może być puste'],
            ])],
            endpoint: '/invoices/406.json',
            repeatLast: true,
        ),
    ));

    expect(fn () => $method->invoke(
        $processingProbe,
        $profile,
        s04InvoiceTemplate('invalid'),
        'processing-timeout',
        s04BindingKey('processing-timeout'),
    ))->toThrow(RuntimeException::class, 'stable terminal suffix');
});

it('rejects a PersistWithErrors nonterminal regression before search or later writes', function (): void {
    $baseConfiguration = s04Configuration();
    $configuration = new KsefDemoProbeConfiguration(
        $baseConfiguration->profiles,
        pollWindowMs: 20,
        pollIntervalMs: 1,
        maxSearchPages: 2,
        preSendObservationWindowMs: 2,
        visibilityWindowMs: 20,
        visibilityPollIntervalMs: 1,
    );
    $profile = $configuration->profiles['auto_persist'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'validationEvidence');
    $transport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '405'], 201)),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [
                s04Response([
                    'id' => '405',
                    'gov_status' => 'demo_processing',
                    'gov_error_messages' => ['buyer_tax_no_invalid'],
                ]),
                s04Response([
                    'id' => '405',
                    'gov_status' => null,
                    'gov_error_messages' => ['buyer_tax_no_invalid'],
                ]),
            ],
            endpoint: '/invoices/405.json',
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, s04InvoiceTemplate('invalid'), 'invalid-regression', s04BindingKey('invalid-regression')))
        ->toThrow(RuntimeException::class, 'status regressed')
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(2)
        ->and($transport->requestCount(SearchKsefDemoInvoicesRequest::class))->toBe(0);
});

it('rejects a non-200 invoice read before later profile effects', function (): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['auto_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'profileEvidence');
    $transport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '406'], 201)),
        s04Responses(ReadKsefDemoInvoiceRequest::class, s04Response(['id' => '406', 'gov_status' => 'demo_processing'], 206)),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, 200, random_bytes(32)))
        ->toThrow(RuntimeException::class, 'exact 200')
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(0);
});

it('allows a nested invoice business status while rejecting an error-envelope status', function (): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'read');
    $validProbe = KsefDemoContractProbe::forTesting($configuration, s04Transport(
        s04Responses(ReadKsefDemoInvoiceRequest::class, s04Response([
            'invoice' => [
                'id' => '407',
                'status' => 'issued',
                'gov_status' => null,
            ],
        ])),
    ));
    $errorProbe = KsefDemoContractProbe::forTesting($configuration, s04Transport(
        s04Responses(ReadKsefDemoInvoiceRequest::class, s04Response([
            'id' => '407',
            'status' => 'failed',
            'gov_status' => null,
        ])),
    ));

    expect($method->invoke($validProbe, $profile, '407')->status())->toBe(200)
        ->and(fn () => $method->invoke($errorProbe, $profile, '407'))
        ->toThrow(RuntimeException::class, 'explicit gov_status');
});

it('rejects provider error statuses in exact-search items', function (mixed $status): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'searchSnapshot');
    $probe = KsefDemoContractProbe::forTesting($configuration, s04Transport(
        s04Responses(SearchKsefDemoInvoicesRequest::class, s04Response([[
            'id' => '10',
            'oid' => 'provider-error-status',
            'status' => $status,
        ]])),
    ));

    expect($method->invoke($probe, $profile, 'provider-error-status'))->toBe([
        'documents' => [],
        'exact' => false,
        'complete' => true,
    ]);
})->with(['failed', 'unauthorized', 'denied', 'rejected', 'unprocessable-entity', 'not_found', '404 Not Found', '500', 500]);

it('does not start a status request after its signed observation deadline', function (): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'read');
    $transport = s04Transport(
        s04Responses(ReadKsefDemoInvoiceRequest::class, s04Response(['id' => '418', 'gov_status' => null])),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);
    $now = hrtime(true);

    if (! is_int($now)) {
        throw new RuntimeException('The unit test requires an integer monotonic clock.');
    }

    expect(fn () => $method->invoke($probe, $profile, '418', $now - 1))
        ->toThrow(RuntimeException::class, 'window elapsed')
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(0);
});

it('does not relabel unrelated or mixed provider errors as buyer_tax_no evidence', function (array $errors): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['auto_persist'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'observePersistedValidationErrors');
    $transport = s04Transport(
        s04Route(ReadKsefDemoInvoiceRequest::class, [s04Response([
            'id' => '404',
            'gov_status' => 'demo_processing',
            'gov_error_messages' => $errors,
        ])], repeatLast: true),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, '404', s04BindingKey('unrelated-error')))
        ->toThrow(RuntimeException::class, 'explicit buyer_tax_no validation error');
})->with([
    'unrelated field' => [['currency_invalid']],
    'seller tax id' => [['Błędny NIP sprzedawcy']],
    'expected plus unrelated' => [['buyer_tax_no_invalid', 'server_error']],
    'combined code plus unrelated' => [['buyer_tax_no_invalid server_error']],
    'combined context plus unrelated' => [['Błędny NIP nabywcy; authorization_failed']],
    'combined Polish field and date error' => [['Błędny NIP nabywcy oraz nieprawidłowa data sprzedaży']],
    'combined Polish field and KSeF connection error' => [['Błędny NIP nabywcy oraz brak połączenia z KSeF']],
]);

it('recognizes only the exact documented Polish buyer tax validation message', function (): void {
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'govErrorEvidenceInInvoice');

    $documented = $method->invoke(null, [
        'gov_error_messages' => ['NIP nabywcy - nie może być puste'],
    ]);
    $mixedSuffix = $method->invoke(null, [
        'gov_error_messages' => ['NIP nabywcy - nie może być puste; authorization_failed'],
    ]);

    expect($documented)->toBeArray()
        ->and($documented['memory_digest'])->toBeString()
        ->and($documented['expected_validation_field'])->toBeTrue()
        ->and($mixedSuffix)->toBeArray()
        ->and($mixedSuffix['memory_digest'])->toBeString()
        ->and($mixedSuffix['expected_validation_field'])->toBeFalse();

    expect(fn () => $method->invoke(null, [
        'gov_error_messages' => 'NIP nabywcy - nie może być puste',
    ]))->toThrow(RuntimeException::class, 'malformed gov_error_messages');
});

it('accepts BlockInvalid only from an explicit validation-error envelope without persisted identity', function (): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'validationEvidence');
    $shapeMethod = new ReflectionMethod(KsefDemoContractProbe::class, 'hasStrictExpectedValidationError');
    expect($shapeMethod->invoke(null, s04BlockInvalidResponse(), 'buyer_tax_no'))->toBeTrue()
        ->and($shapeMethod->invoke(null, ['message' => ['buyer_tax_no' => ['- nie może być puste']]], 'buyer_tax_no'))->toBeFalse()
        ->and($shapeMethod->invoke(null, ['code' => 'invalid', 'message' => ['buyer_tax_no' => ['- nie może być puste']]], 'buyer_tax_no'))->toBeFalse()
        ->and($shapeMethod->invoke(null, ['code' => 'error', 'message' => ['buyer_tax_no' => ['authorization_failed']]], 'buyer_tax_no'))->toBeFalse();

    $echoTransport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response([
            'invoice' => ['buyer_tax_no' => 'echoed-request-value'],
        ], 422)),
    );
    $echoProbe = KsefDemoContractProbe::forTesting($configuration, $echoTransport);

    $identityTransport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response([
            'id' => '401',
            ...s04BlockInvalidResponse(),
        ], 422)),
    );
    $identityProbe = KsefDemoContractProbe::forTesting($configuration, $identityTransport);

    $mixedTransport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response([
            'code' => 'error',
            'message' => [
                'buyer_tax_no' => ['- nie może być puste'],
                'base' => ['authorization_failed'],
            ],
        ], 422)),
    );
    $mixedProbe = KsefDemoContractProbe::forTesting($configuration, $mixedTransport);
    $upstreamProbe = KsefDemoContractProbe::forTesting($configuration, s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response([
            'errors' => ['buyer_tax_no' => ['required']],
            'status' => 'failed',
            'code' => 'upstream_unavailable',
        ], 422)),
    ));

    expect(fn () => $method->invoke($echoProbe, $profile, s04InvoiceTemplate('invalid'), 'echo-only', s04BindingKey('echo-only')))
        ->toThrow(RuntimeException::class, 'strict validation rejection')
        ->and(fn () => $method->invoke($identityProbe, $profile, s04InvoiceTemplate('invalid'), 'identity-on-rejection', s04BindingKey('identity-on-rejection')))
        ->toThrow(RuntimeException::class, 'strict validation rejection')
        ->and(fn () => $method->invoke($mixedProbe, $profile, s04InvoiceTemplate('invalid'), 'mixed-errors', s04BindingKey('mixed-errors')))
        ->toThrow(RuntimeException::class, 'strict validation rejection')
        ->and(fn () => $method->invoke($upstreamProbe, $profile, s04InvoiceTemplate('invalid'), 'upstream-error', s04BindingKey('upstream-error')))
        ->toThrow(RuntimeException::class, 'strict validation rejection')
        ->and($echoTransport->requestCount(SearchKsefDemoInvoicesRequest::class))->toBe(0);
});

it('rejects malformed semantic-send snapshots without masking them as lost responses', function (array $responseBody): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'ensureAccepted');
    $transport = s04Transport(
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '100', 'gov_status' => null])],
            endpoint: '/invoices/100.json',
            repeatLast: true,
        ),
        s04Responses(SendKsefDemoInvoiceRequest::class, s04Response($responseBody)),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, '100', 'not_sent'))
        ->toThrow(RuntimeException::class)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(1);
})->with([
    'missing snapshot fields' => [[]],
    'mismatched document' => [['id' => '999', 'gov_status' => 'demo_processing']],
    'ambiguous nested invoice' => [[
        'id' => '100',
        'invoice' => ['id' => '100', 'gov_status' => 'demo_processing'],
    ]],
    'not sent with gov id' => [['id' => '100', 'gov_status' => null, 'gov_id' => 'contradictory-gov-id']],
    'provider false success flag' => [['id' => '100', 'gov_status' => 'demo_processing', 'success' => false]],
    'malformed gov errors' => [['id' => '100', 'gov_status' => 'demo_processing', 'gov_error_messages' => 123]],
]);

it('does not mask a local semantic-send failure as an ambiguous transport result', function (): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'ensureAccepted');
    $transport = s04Transport(
        s04Responses(
            SendKsefDemoInvoiceRequest::class,
            KsefDemoLiteralResponse::fail(KsefDemoLiteralFailure::logic('local request construction failure')),
        ),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '100', 'gov_status' => null])],
            endpoint: '/invoices/100.json',
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, '100', 'not_sent'))
        ->toThrow(RuntimeException::class, 'non-reconcilable local transport boundary')
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(1);
});

it('reconciles a no-response connect failure with reads and never retries semantic send', function (): void {
    $baseConfiguration = s04Configuration();
    $configuration = new KsefDemoProbeConfiguration(
        $baseConfiguration->profiles,
        pollWindowMs: 10,
        pollIntervalMs: 1,
        maxSearchPages: 2,
        preSendObservationWindowMs: 2,
        visibilityWindowMs: 2,
        visibilityPollIntervalMs: 1,
    );
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'ensureAccepted');
    $transport = s04Transport(
        s04Responses(
            SendKsefDemoInvoiceRequest::class,
            KsefDemoLiteralResponse::fail(KsefDemoLiteralFailure::transport(
                'simulated no-response connection failure',
                28,
                128,
            )),
        ),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [
                s04Response(['id' => '410', 'gov_status' => null]),
                s04Response(['id' => '410', 'gov_status' => 'demo_ok', 'gov_id' => 'reconciled-gov-id']),
            ],
            endpoint: '/invoices/410.json',
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    $result = $method->invoke($probe, $profile, '410', 'not_sent');

    expect($result['send_count'])->toBe(1)
        ->and($result['send_status'])->toBeNull()
        ->and($result['terminal_status'])->toBe('demo_ok')
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBeGreaterThanOrEqual(3);
});

it('does not attribute definite or unproven connect failures to a later KSeF state', function (array $handlerContext): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'ensureAccepted');
    $transport = s04Transport(
        s04Responses(
            SendKsefDemoInvoiceRequest::class,
            KsefDemoLiteralResponse::fail(KsefDemoLiteralFailure::transport(
                'simulated definite connection failure',
                is_int($handlerContext['errno'] ?? null) ? $handlerContext['errno'] : 0,
                is_int($handlerContext['request_size'] ?? null) ? $handlerContext['request_size'] : 0,
            )),
        ),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [
                s04Response(['id' => '415', 'gov_status' => null]),
                s04Response(['id' => '415', 'gov_status' => 'demo_ok', 'gov_id' => 'unattributed-gov-id']),
            ],
            endpoint: '/invoices/415.json',
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, '415', 'not_sent'))
        ->toThrow(RuntimeException::class, 'failed before an ambiguous')
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(1);
})->with([
    'DNS failure' => [['errno' => 6]],
    'connection refused' => [['errno' => 7]],
    'TLS failure' => [['errno' => 35]],
    'missing handler context' => [[]],
    'timeout without transfer evidence' => [['errno' => 28]],
    'timeout after TCP only' => [['errno' => 28, 'connect_time' => 0.1, 'primary_ip' => '192.0.2.1', 'request_size' => 0]],
]);

it('rejects a received non-200 semantic-send response before reconciliation', function (): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'ensureAccepted');
    $transport = s04Transport(
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '412', 'gov_status' => null])],
            endpoint: '/invoices/412.json',
            repeatLast: true,
        ),
        s04Responses(SendKsefDemoInvoiceRequest::class, s04Response(['id' => '412', 'gov_status' => 'demo_processing'], 500)),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, '412', 'not_sent'))
        ->toThrow(RuntimeException::class, 'exact 200')
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(1);
});

it('cuts credential-bearing transport exceptions out of the public exception chain', function (): void {
    expect(s04CredentialBearingTransportExceptionSecurityReport())->toBe([
        'previous_removed' => true,
        'public_text_redacted' => true,
        'complete_trace_graph_redacted' => true,
        'sensitive_trace_frames' => [],
    ]);
});

it('rejects a terminal status regression before the final acceptance boundary', function (): void {
    $base = s04Configuration();
    $configuration = new KsefDemoProbeConfiguration(
        $base->profiles,
        pollWindowMs: 5,
        pollIntervalMs: 1,
        maxSearchPages: 2,
        preSendObservationWindowMs: 2,
        visibilityWindowMs: 2,
        visibilityPollIntervalMs: 1,
    );
    $profile = $configuration->profiles['auto_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'ensureAccepted');
    $transport = s04Transport(
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [
                s04Response(['id' => '300', 'gov_status' => 'demo_ok', 'gov_id' => 'stable-gov-id']),
                s04Response(['id' => '300', 'gov_status' => 'demo_processing', 'gov_id' => null]),
            ],
            endpoint: '/invoices/300.json',
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, '300', 'demo_processing'))
        ->toThrow(RuntimeException::class, 'status regressed')
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBeGreaterThan(1);
});

it('rejects validation errors that appear during the ExplicitSdk unsent observation', function (): void {
    $baseConfiguration = s04Configuration();
    $configuration = new KsefDemoProbeConfiguration(
        $baseConfiguration->profiles,
        pollWindowMs: 5,
        pollIntervalMs: 1,
        maxSearchPages: 2,
        preSendObservationWindowMs: 5,
        visibilityWindowMs: 2,
        visibilityPollIntervalMs: 1,
    );
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'observeExplicitUnsent');
    $initialTransport = s04Transport(
        s04Responses(ReadKsefDemoInvoiceRequest::class, s04Response(['id' => '414', 'gov_status' => null])),
    );
    $initialConnector = new KsefDemoConnector(
        $profile->endpoint->baseUrl,
        inMemoryTransport: $initialTransport,
    );
    $initial = $initialConnector->send(new ReadKsefDemoInvoiceRequest($profile->endpoint->token, '414'));
    $transport = s04Transport(
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response([
                'id' => '414',
                'gov_status' => null,
                'gov_error_messages' => ['buyer_tax_no_invalid'],
            ])],
            endpoint: '/invoices/414.json',
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, '414', $initial))
        ->toThrow(RuntimeException::class, 'unexpected KSeF send')
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(1);
});

it('rejects undocumented empty KSeF status and error shapes before semantic send', function (array $snapshot): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'ensureAccepted');
    $transport = s04Transport(
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response($snapshot)],
            endpoint: '/invoices/419.json',
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, '419', 'not_sent'))
        ->toThrow(RuntimeException::class)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(0);
})->with([
    'empty gov_status' => [['id' => '419', 'gov_status' => '']],
    'empty scalar gov errors' => [['id' => '419', 'gov_status' => null, 'gov_error_messages' => '']],
]);

it('fails when ProviderAutoSend reaches acceptance while the before PDF is downloading', function (): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['auto_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'profileEvidence');
    $transport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '300'], 201)),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [
                s04Response(['id' => '300', 'gov_status' => 'demo_processing']),
                s04Response(['id' => '300', 'gov_status' => 'demo_ok', 'gov_id' => 'accepted-during-pdf']),
            ],
            endpoint: '/invoices/300.json',
        ),
        s04Responses(
            DownloadKsefDemoPdfRequest::class,
            s04Response(s04Pdf('before'), 200, ['Content-Type' => 'application/pdf']),
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, 200, random_bytes(32)))
        ->toThrow(RuntimeException::class, 'crossed the acceptance boundary')
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(2);
});

it('fails a ProviderAutoSend nonterminal regression before any later write', function (): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['auto_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'profileEvidence');
    $transport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '300'], 201)),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [
                s04Response(['id' => '300', 'gov_status' => 'demo_processing']),
                s04Response(['id' => '300', 'gov_status' => null]),
            ],
            endpoint: '/invoices/300.json',
        ),
        s04Responses(
            DownloadKsefDemoPdfRequest::class,
            s04Response(s04Pdf('before'), 200, ['Content-Type' => 'application/pdf']),
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, 200, random_bytes(32)))
        ->toThrow(RuntimeException::class, 'crossed the acceptance boundary')
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(2)
        ->and($transport->requestCount(SearchKsefDemoInvoicesRequest::class))->toBe(0);
});

it('ends explicit pre-send checks with authoritative account identity before semantic send', function (): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['explicit_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'ensureAccepted');
    $transport = s04Transport(
        s04Route(
            AccountKsefDemoRequest::class,
            [
                s04Response(['id' => s04AccountId('explicit_block')]),
                s04Response(['id' => s04AccountId('explicit_block')]),
                s04Response(['id' => '9999']),
            ],
            repeatLast: true,
        ),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '411', 'gov_status' => null])],
            endpoint: '/invoices/411.json',
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting(
        $configuration,
        $transport,
        verifyEffectAccountIdentity: true,
    );

    expect(fn () => $method->invoke($probe, $profile, '411', 'not_sent'))
        ->toThrow(RuntimeException::class, 'API token does not match the allowlisted KSeF DEMO account')
        ->and($transport->requestCount(AccountKsefDemoRequest::class))->toBe(3)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(2)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(0);
});

it('fails when the accepted KSeF identity changes while the after PDF is downloading', function (array $snapshot): void {
    $configuration = s04Configuration();
    $profile = $configuration->profiles['auto_block'];
    $method = new ReflectionMethod(KsefDemoContractProbe::class, 'observePostAcceptancePdfBoundary');
    $transport = s04Transport(
        s04Responses(ReadKsefDemoInvoiceRequest::class, s04Response($snapshot)),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $method->invoke($probe, $profile, '300', 'stable-gov-id'))
        ->toThrow(RuntimeException::class, 'changed during the post-acceptance PDF');
})->with([
    'terminal regression' => [['id' => '300', 'gov_status' => 'demo_processing']],
    'changed gov identity' => [['id' => '300', 'gov_status' => 'demo_ok', 'gov_id' => 'different-gov-id']],
    'validation errors appeared' => [[
        'id' => '300',
        'gov_status' => 'demo_ok',
        'gov_id' => 'stable-gov-id',
        'gov_error_messages' => ['buyer_tax_no_late_validation_failure'],
    ]],
]);

it('fails explicit execution before transport when opt-in is missing', function (): void {
    $original = getenv('FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED');
    putenv('FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED');

    try {
        KsefDemoContractProbe::runConfigured();
        $exception = null;
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        $original === false
            ? putenv('FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED')
            : putenv("FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED={$original}");
    }

    expect($exception?->getMessage())->toContain('explicitly run');
});

it('requires the native launch broker before reading credentials or constructing provider transport', function (): void {
    $originalEnabled = getenv('FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED');
    $originalPath = getenv('FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE');
    $unreadablePath = sys_get_temp_dir().'/s04-must-not-read-credentials-'.bin2hex(random_bytes(8));
    putenv('FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED=yes');
    putenv("FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE={$unreadablePath}");

    try {
        expect(fn () => KsefDemoContractProbe::runConfigured())
            ->toThrow(BrokeredExecutionRequiredException::class, 'native broker');
    } finally {
        $originalEnabled === false
            ? putenv('FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED')
            : putenv("FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED={$originalEnabled}");
        $originalPath === false
            ? putenv('FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE')
            : putenv("FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE={$originalPath}");
    }

    expect(is_file($unreadablePath))->toBeFalse();
});

it('keeps real transport construction private and test construction literal-only', function (): void {
    $constructor = (new ReflectionClass(KsefDemoContractProbe::class))->getConstructor();
    $transport = s04Transport();
    $probe = KsefDemoContractProbe::forTesting(s04Configuration(), $transport);

    expect($constructor)->not->toBeNull()
        ->and($constructor?->isPrivate())->toBeTrue()
        ->and(fn () => $probe->collect())->toThrow(RuntimeException::class);
});

it('accepts only the sealed literal sender at every probe testing seam', function (): void {
    $callbackCalls = 0;
    $generalMock = new MockClient([
        function () use (&$callbackCalls): MockResponse {
            $callbackCalls++;

            return MockResponse::make(['id' => 'unexpected-network-capable-mock']);
        },
    ]);
    $forTesting = new ReflectionMethod(KsefDemoContractProbe::class, 'forTesting');
    $forAuthorizedTesting = new ReflectionMethod(KsefDemoContractProbe::class, 'forAuthorizedTesting');
    $literalTransport = new ReflectionClass(KsefDemoInMemoryTransport::class);
    $literalTransportParents = class_parents(KsefDemoInMemoryTransport::class);

    expect($literalTransport->isFinal())->toBeTrue()
        ->and(array_values($literalTransportParents))->not->toContain(MockClient::class)
        ->and($literalTransport->implementsInterface(Sender::class))->toBeTrue()
        ->and($literalTransport->hasMethod('addResponse'))->toBeFalse()
        ->and($literalTransport->hasMethod('addResponses'))->toBeFalse()
        ->and($literalTransport->hasMethod('withMockClient'))->toBeFalse()
        ->and($literalTransport->hasMethod('getRecordedResponses'))->toBeFalse()
        ->and(fn () => $forTesting->invoke(null, s04Configuration(), $generalMock))
        ->toThrow(TypeError::class)
        ->and(fn () => $forAuthorizedTesting->invoke(
            null,
            s04Configuration(),
            $generalMock,
            s04FreshConsumptionAuthority(),
            ['s04-test-operator' => s04SigningMaterial()['public']],
            [s04AuthorityId() => s04AuthoritySigningMaterial()['public']],
        ))->toThrow(TypeError::class)
        ->and($callbackCalls)->toBe(0);
});

it('rejects callable fixture-like and missing literal routes without I/O or artifact writes', function (): void {
    $callableCalls = 0;
    $callable = function () use (&$callableCalls): array {
        $callableCalls++;

        return ['id' => 'unexpected-callable-result'];
    };
    $fixtureDirectory = sys_get_temp_dir().'/fakturownia-s04-no-route-'.bin2hex(random_bytes(6));
    $transport = s04Transport();
    $probe = KsefDemoContractProbe::forTesting(s04Configuration(), $transport, $fixtureDirectory);
    $read = new ReflectionMethod(KsefDemoContractProbe::class, 'read');
    $profile = s04Configuration()->profiles['explicit_block'];

    expect(fn () => new KsefDemoLiteralResponseSequence(
        ReadKsefDemoInvoiceRequest::class,
        [$callable],
    ))->toThrow(InvalidArgumentException::class, 'cannot contain fixtures or callables')
        ->and(fn () => KsefDemoLiteralResponse::make(['payload' => $callable]))
        ->toThrow(InvalidArgumentException::class, 'only scalar JSON values and arrays')
        ->and(fn () => $read->invoke($probe, $profile, '123'))
        ->toThrow(RuntimeException::class, 'invoice status read failed')
        ->and($callableCalls)->toBe(0)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(0)
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(0)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(0)
        ->and(is_dir($fixtureDirectory))->toBeFalse();
});

it('fails closed before configuration or credentials when Saloon global state is contaminated', function (): void {
    $callbackCalls = 0;
    $sentinelToken = 'S04_GLOBAL_SALOON_SENTINEL_TOKEN';
    $configurationPath = tempnam(sys_get_temp_dir(), 'fakturownia-s04-global-saloon-');
    $originalEnabled = getenv('FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED');
    $originalPath = getenv('FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE');

    if (! is_string($configurationPath)) {
        throw new RuntimeException('Could not create the global-Saloon regression configuration.');
    }

    file_put_contents($configurationPath, json_encode([
        'profiles' => ['must-not-be-read' => ['token' => $sentinelToken]],
    ], JSON_THROW_ON_ERROR));
    chmod($configurationPath, 0600);
    putenv('FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED=yes');
    putenv("FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE={$configurationPath}");
    $contaminations = [
        static function () use (&$callbackCalls): void {
            MockClient::global([
                function () use (&$callbackCalls): MockResponse {
                    $callbackCalls++;

                    return MockResponse::make();
                },
            ]);
        },
        static function () use (&$callbackCalls): void {
            Config::globalMiddleware()->onRequest(function () use (&$callbackCalls): void {
                $callbackCalls++;
            });
        },
        static function () use (&$callbackCalls): void {
            Config::globalMiddleware()->onResponse(function () use (&$callbackCalls): void {
                $callbackCalls++;
            });
        },
        static function () use (&$callbackCalls): void {
            Config::globalMiddleware()->onFatalException(function () use (&$callbackCalls): void {
                $callbackCalls++;
            });
        },
        static function () use (&$callbackCalls): void {
            Config::setSenderResolver(function () use (&$callbackCalls): KsefDemoInMemoryTransport {
                $callbackCalls++;

                return s04Transport();
            });
        },
        static function (): void {
            Config::$defaultSender = KsefDemoInMemoryTransport::class;
        },
        static function (): void {
            Config::$defaultTlsMethod = 0;
        },
        static function (): void {
            Config::$defaultConnectionTimeout = 11;
        },
        static function (): void {
            Config::$defaultRequestTimeout = 31;
        },
    ];

    try {
        foreach ($contaminations as $contaminate) {
            s04ResetSaloonRuntime();
            $contaminate();

            try {
                KsefDemoContractProbe::runConfigured();
                $exception = null;
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            expect($exception)->toBeInstanceOf(RuntimeException::class)
                ->and($exception?->getMessage())->toBe('The global Saloon runtime is contaminated; the KSeF transport is disabled.')
                ->and($exception === null
                    ? false
                    : s04ThrowableTraceContainsSensitiveValue($exception, [$sentinelToken]))->toBeFalse();
        }
    } finally {
        s04ResetSaloonRuntime();
        $originalEnabled === false
            ? putenv('FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED')
            : putenv("FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED={$originalEnabled}");
        $originalPath === false
            ? putenv('FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE')
            : putenv("FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE={$originalPath}");
        unlink($configurationPath);
    }

    expect($callbackCalls)->toBe(0)
        ->and(MockClient::getGlobal())->toBeNull()
        ->and(Config::$defaultSender)->toBe(GuzzleSender::class)
        ->and(Config::$defaultTlsMethod)->toBe(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)
        ->and(Config::$defaultConnectionTimeout)->toBe(10)
        ->and(Config::$defaultRequestTimeout)->toBe(30);
});

it('redacts a secret captured by the test clock when the Saloon guard rejects construction', function (): void {
    $sentinel = 'S04_CLOCK_CAPTURE_SENTINEL_TOKEN';
    $clock = static function () use ($sentinel): DateTimeImmutable {
        throw new RuntimeException($sentinel);
    };
    Config::globalMiddleware()->onRequest(static function (): void {});

    try {
        KsefDemoContractProbe::forTesting(
            s04Configuration(),
            s04Transport(),
            clock: $clock,
        );
        $exception = null;
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        s04ResetSaloonRuntime();
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception === null
            ? false
            : s04ThrowableTraceContainsSensitiveValue($exception, [$sentinel]))->toBeFalse();
});

it('pins the live connector sender and every security-relevant Guzzle default', function (): void {
    $connector = new KsefDemoConnector(
        'https://s04-demo-explicit-block.fakturownia.pl',
        connectTimeoutMs: 1_234,
        requestTimeoutMs: 5_678,
    );
    $sender = new ReflectionProperty(KsefDemoConnector::class, 'defaultSender');
    $defaultConfig = new ReflectionMethod(KsefDemoConnector::class, 'defaultConfig');
    $config = $defaultConfig->invoke($connector);

    expect($sender->getValue($connector))->toBe(GuzzleSender::class)
        ->and($config)->toBe([
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::CONNECT_TIMEOUT => 1.234,
            RequestOptions::TIMEOUT => 5.678,
            RequestOptions::VERIFY => true,
            RequestOptions::PROXY => '',
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::STREAM => false,
            RequestOptions::CRYPTO_METHOD => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ]);
});

it('rechecks signed authorization at every write boundary without invoking expired transport', function (): void {
    $configuration = s04SignedConfiguration();
    $profile = $configuration->profiles['explicit_block'];
    $validNow = new DateTimeImmutable('2026-08-25T08:00:01+00:00');
    $expiredNow = new DateTimeImmutable('2026-08-26T00:00:00+00:00');
    $clockCalls = 0;
    $transport = s04Transport(
        s04Responses(CreateKsefDemoInvoiceRequest::class, s04Response(['id' => '100'], 201)),
        s04Responses(SendKsefDemoInvoiceRequest::class, s04Response(['id' => '100', 'gov_status' => 'demo_processing'])),
        s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [s04Response(['id' => '100', 'gov_status' => null])],
            endpoint: '/invoices/100.json',
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting(
        $configuration,
        $transport,
        clock: function () use (&$clockCalls, $expiredNow, $validNow): DateTimeImmutable {
            $clockCalls++;

            return $clockCalls === 1 ? $validNow : $expiredNow;
        },
    );
    $send = new ReflectionMethod(KsefDemoContractProbe::class, 'send');
    $ensureAccepted = new ReflectionMethod(KsefDemoContractProbe::class, 'ensureAccepted');
    $send->invoke(
        $probe,
        $profile,
        new CreateKsefDemoInvoiceRequest($profile->endpoint->token, s04InvoiceTemplate()),
        'create failed',
    );

    expect(fn () => $ensureAccepted->invoke($probe, $profile, '100', 'not_sent'))
        ->toThrow(InvalidArgumentException::class)
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(0)
        ->and($clockCalls)->toBe(2);
});

it('enforces authorization write boundaries to the exact microsecond', function (): void {
    $profile = s04SignedConfiguration()->profiles['explicit_block'];
    $profile->assertWriteAuthorizedAt(new DateTimeImmutable('2026-08-25T23:59:59.000000+00:00'), 1);

    expect(fn () => $profile->assertWriteAuthorizedAt(
        new DateTimeImmutable('2026-08-25T23:59:59.000001+00:00'),
        1,
    ))->toThrow(RuntimeException::class, 'expires before');
});

it('rejects an operator authorization TTL exceeding 24 hours by one microsecond', function (): void {
    $profile = s04Profile('explicit_block', KsefOwnership::ExplicitSdk, KsefValidationMode::BlockInvalid);
    $issuedAt = new DateTimeImmutable('2026-08-25T00:00:00.000000+00:00');
    $expiresAt = new DateTimeImmutable('2026-08-26T00:00:00.000001+00:00');

    expect(fn () => new KsefDemoProfile(
        $profile->key,
        $profile->ownership,
        $profile->validationMode,
        $profile->endpoint,
        $profile->validInvoice,
        $profile->invalidInvoice,
        $profile->expectedValidationField,
        $profile->expectedKsefEnvironment,
        $profile->expectedGovAutoSendMode,
        $profile->expectedValidateInvoicesForGov,
        $profile->expectedBuyerCompany,
        $profile->expectedThrowawayTenant,
        $profile->expectedEmailDeliveryDisabled,
        $profile->expectedPaymentsDisabled,
        $profile->expectedWebhooksDisabled,
        $issuedAt,
        $expiresAt,
        KsefDemoProfile::settingsChecksumFor(
            $profile->endpoint,
            $profile->ownership,
            $profile->validationMode,
            $profile->expectedKsefEnvironment,
            $profile->expectedGovAutoSendMode,
            $profile->expectedValidateInvoicesForGov,
            $profile->expectedBuyerCompany,
            $profile->expectedThrowawayTenant,
            $profile->expectedEmailDeliveryDisabled,
            $profile->expectedPaymentsDisabled,
            $profile->expectedWebhooksDisabled,
            $issuedAt,
            $expiresAt,
        ),
    ))->toThrow(InvalidArgumentException::class, 'at most 24 hours');
});

it('rejects non-owner-only and symlinked configuration files at the credential boundary', function (): void {
    $originalPath = getenv('FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE');
    $configurationPath = tempnam(sys_get_temp_dir(), 'fakturownia-s04-config-');

    if (! is_string($configurationPath)) {
        throw new RuntimeException('Could not create a temporary configuration file.');
    }

    $canonicalPath = realpath($configurationPath);

    if (! is_string($canonicalPath)) {
        throw new RuntimeException('Could not canonicalize a temporary configuration file.');
    }

    $symlinkPath = "{$canonicalPath}.link";
    file_put_contents($canonicalPath, '{"profiles":{}}');
    chmod($canonicalPath, 0644);
    putenv("FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE={$canonicalPath}");

    try {
        expect(fn () => KsefDemoProbeConfiguration::assertSecureConfigurationFileForTesting())
            ->toThrow(InvalidArgumentException::class, 'KSEF_DEMO_CONFIG_FILE_INSECURE_METADATA');

        chmod($canonicalPath, 0600);

        if (! symlink($canonicalPath, $symlinkPath)) {
            throw new RuntimeException('Could not create a temporary configuration symlink.');
        }

        putenv("FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE={$symlinkPath}");

        expect(fn () => KsefDemoProbeConfiguration::assertSecureConfigurationFileForTesting())
            ->toThrow(InvalidArgumentException::class, 'KSEF_DEMO_CONFIG_FILE_NOT_CANONICAL');
    } finally {
        $originalPath === false
            ? putenv('FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE')
            : putenv("FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE={$originalPath}");

        if (is_link($symlinkPath)) {
            unlink($symlinkPath);
        }

        if (is_file($canonicalPath)) {
            unlink($canonicalPath);
        }
    }

    expect(is_file($canonicalPath))->toBeFalse();
});

it('loads only a canonical owner-only S0.4 authorization binding key', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fakturownia-s04-binding-');

    if (! is_string($path)) {
        throw new RuntimeException('Could not create the temporary binding-key fixture.');
    }

    $canonicalPath = realpath($path);

    if (! is_string($canonicalPath)) {
        throw new RuntimeException('Could not canonicalize the temporary binding-key fixture.');
    }

    $bindingKey = random_bytes(32);
    $hardlinkPath = "{$canonicalPath}.hardlink";
    file_put_contents($canonicalPath, base64_encode($bindingKey));
    chmod($canonicalPath, 0600);
    try {
        KsefDemoProbeConfiguration::assertSecureBindingKeyFileForTesting($canonicalPath);

        if (! link($canonicalPath, $hardlinkPath)) {
            throw new RuntimeException('Could not create the temporary binding-key hardlink.');
        }

        expect(fn () => KsefDemoProbeConfiguration::assertSecureBindingKeyFileForTesting($canonicalPath))
            ->toThrow(InvalidArgumentException::class, 'NOT_CANONICAL');

        unlink($hardlinkPath);

        chmod($canonicalPath, 0644);

        expect(fn () => KsefDemoProbeConfiguration::assertSecureBindingKeyFileForTesting($canonicalPath))
            ->toThrow(InvalidArgumentException::class, 'INSECURE_METADATA');
    } finally {
        if (is_file($hardlinkPath)) {
            unlink($hardlinkPath);
        }

        if (is_file($canonicalPath)) {
            unlink($canonicalPath);
        }
    }
});

it('rejects downgraded live probe limits before profile parsing or transport', function (): void {
    $limits = s04LiveLimits();
    $limits['visibility_window_ms'] = 1;

    expect(fn () => KsefDemoProbeConfiguration::assertSafeLiveLimits($limits))
        ->toThrow(InvalidArgumentException::class, 'outside the bounded safety policy');
});

it('normalizes only known DEMO statuses and validates PDF bytes', function (): void {
    $transport = s04Transport(
        s04Responses(
            DownloadKsefDemoPdfRequest::class,
            s04Response(s04Pdf(), 200, ['Content-Type' => 'application/pdf; charset=binary']),
            s04Response('<html>login</html>', 200, ['Content-Type' => 'text/html']),
            s04Response("%PDF-\n%%EOF", 200, ['Content-Type' => 'application/pdf']),
        ),
    );
    $connector = new KsefDemoConnector(
        'https://s04-demo-explicit-block.fakturownia.pl',
        inMemoryTransport: $transport,
    );
    $valid = $connector->send(new DownloadKsefDemoPdfRequest('token', '10'));
    $invalid = $connector->send(new DownloadKsefDemoPdfRequest('token', '10'));
    $pseudoPdf = $connector->send(new DownloadKsefDemoPdfRequest('token', '10'));

    expect(KsefDemoContractProbe::normalizeKsefStatus(null))->toBe('not_sent')
        ->and(KsefDemoContractProbe::normalizeKsefStatus(''))->toBe('unknown')
        ->and(KsefDemoContractProbe::normalizeKsefStatus('demo_processing'))->toBe('demo_processing')
        ->and(KsefDemoContractProbe::normalizeKsefStatus('ok'))->toBe('unknown')
        ->and(KsefDemoContractProbe::describePdf($valid))->toMatchArray(['mime' => 'application/pdf', 'size' => strlen(s04Pdf())])
        ->and(fn () => KsefDemoContractProbe::describePdf($invalid))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoContractProbe::describePdf($pseudoPdf))->toThrow(RuntimeException::class);
});

it('binds security-critical harness builtins to the global namespace', function (): void {
    $files = [
        realpath(__DIR__.'/../Contract/Support/KsefDemoProbeConfiguration.php') => [
            'getenv',
            'hash',
            'hash_equals',
            'hash_hmac',
            'parse_url',
            'realpath',
        ],
        realpath(__DIR__.'/../Contract/Support/KsefDemoContractProbe.php') => [
            'hash',
            'hash_equals',
            'hash_hmac',
            'hrtime',
            'json_decode',
            'random_bytes',
            'sodium_memzero',
        ],
    ];

    foreach ($files as $path => $functions) {
        if (! is_string($path)) {
            throw new RuntimeException('Could not resolve a security-critical KSeF harness source file.');
        }

        $source = file_get_contents($path);

        if (! is_string($source)) {
            throw new RuntimeException('Could not read a security-critical KSeF harness source file.');
        }

        foreach ($functions as $function) {
            expect($source)
                ->toContain("\\{$function}(")
                ->not->toContain("use function {$function};");
        }
    }

    if (! function_exists('Cieplik206\\Fakturownia\\Tests\\Contract\\Support\\hash')) {
        eval('namespace Cieplik206\\Fakturownia\\Tests\\Contract\\Support; function hash(string $algorithm, string $value): string { return str_repeat("0", 64); } function random_bytes(int $length): string { return str_repeat("\\0", $length); }');
    }

    $host = 's04-demo-global-binding.fakturownia.pl';
    $expectedFingerprint = \hash('sha256', "fakturownia-s0.4|explicit_block|{$host}|1001");
    $endpoint = new KsefDemoEndpoint('explicit_block', "https://{$host}", 'token', $expectedFingerprint);
    $settingsChecksum = KsefDemoProfile::settingsChecksumFor(
        $endpoint,
        KsefOwnership::ExplicitSdk,
        KsefValidationMode::BlockInvalid,
        'demo',
        null,
        true,
        true,
        true,
        true,
        true,
        true,
        s04AttestedAt(),
        s04AttestationExpiresAt(),
    );

    expect(KsefDemoEndpoint::fingerprintFor('explicit_block', $host, '1001'))->toBe($expectedFingerprint)
        ->and($settingsChecksum)->not->toBe(str_repeat('0', 64));
});

it('keeps the capability closed unless every exact profile proof passes', function (): void {
    $result = s04SafeResult();
    $unsafeProfiles = $result['profiles'];
    $unsafeProfiles['auto_block']['send_count'] = 1;
    $unsafe = KsefDemoContractProbe::resolveCapabilityPolicy($unsafeProfiles);

    expect($result['capability_0_2']['matrix_complete'])->toBeTrue()
        ->and($result['capability_0_2']['supported_profile'])->toBe('explicit_sdk+block_invalid')
        ->and($unsafe['matrix_complete'])->toBeFalse()
        ->and($unsafe['supported_profile'])->toBe('none');
});

it('accepts a stable documented auto-persist rejection but not an in-flight timeout', function (): void {
    $rejected = s04SafeResult();
    $invalid = &$rejected['profiles']['auto_persist']['exact_search'];
    $invalid['invalid_ksef_status'] = 'demo_send_error';
    $invalid['invalid_gov_id_present'] = false;
    $invalid['invalid_terminal_stable'] = true;
    $invalid['invalid_terminal_observations'] = 2;
    $invalid['invalid_observations'] = [
        [
            'status' => 'demo_send_error',
            'gov_id_hmac_sha256' => null,
            'validation_error_category' => 'expected_validation_leaf_gov_error',
        ],
        [
            'status' => 'demo_send_error',
            'gov_id_hmac_sha256' => null,
            'validation_error_category' => 'expected_validation_leaf_gov_error',
        ],
    ];
    $invalid['invalid_outcome'] = 'persisted_with_errors_demo_rejected';
    unset($invalid);
    $rejected['capability_0_2'] = KsefDemoContractProbe::resolveCapabilityPolicy($rejected['profiles']);
    KsefDemoFixtureGuard::assertSafe($rejected, []);

    $processing = $rejected;
    $invalid = &$processing['profiles']['auto_persist']['exact_search'];
    $invalid['invalid_ksef_status'] = 'demo_processing';
    $invalid['invalid_terminal_observations'] = 0;
    $invalid['invalid_observations'] = [[
        'status' => 'demo_processing',
        'gov_id_hmac_sha256' => null,
        'validation_error_category' => 'expected_validation_leaf_gov_error',
    ]];
    $invalid['invalid_outcome'] = 'persisted_with_errors';
    unset($invalid);
    $processing['capability_0_2'] = KsefDemoContractProbe::resolveCapabilityPolicy($processing['profiles']);

    expect($rejected['capability_0_2']['matrix_complete'])->toBeTrue()
        ->and($processing['capability_0_2']['matrix_complete'])->toBeFalse()
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($processing, []))->toThrow(RuntimeException::class);
});

it('allows only normalized profile status search and PDF evidence in fixtures', function (): void {
    $result = s04SafeResult();
    KsefDemoFixtureGuard::assertSafe($result, ['never-present-secret']);
    $result['profiles']['explicit_block']['errors'] = ['raw provider message'];

    expect(fn () => KsefDemoFixtureGuard::assertSafe($result, []))->toThrow(RuntimeException::class);
});

it('enforces the exact fixture run-duration boundary to one microsecond', function (): void {
    $exactBoundary = s04SafeResult();
    $exactBoundary['run']['finished_at'] = '2026-08-25T14:00:00.000000Z';
    KsefDemoFixtureGuard::assertSafe($exactBoundary, []);

    $overflow = $exactBoundary;
    $overflow['run']['finished_at'] = '2026-08-25T14:00:00.000001Z';

    expect(fn () => KsefDemoFixtureGuard::assertSafe($overflow, []))
        ->toThrow(RuntimeException::class, 'run boundary');
});

it('rejects forged capability and per-profile evidence', function (): void {
    $sendForgery = s04SafeResult();
    $sendForgery['profiles']['explicit_block']['send_count'] = 0;

    $sendResponseForgery = s04SafeResult();
    $sendResponseForgery['profiles']['explicit_block']['status_codes']['send'] = null;

    $statusForgery = s04SafeResult();
    $statusForgery['profiles']['explicit_block']['status_codes']['valid_issue'] = 100;
    $statusForgery['capability_0_2'] = KsefDemoContractProbe::resolveCapabilityPolicy($statusForgery['profiles']);

    $capabilityForgery = s04SafeResult();
    $capabilityForgery['capability_0_2']['supported_profile'] = 'none';

    $searchForgery = s04SafeResult();
    $searchForgery['profiles']['explicit_block']['exact_search']['valid_count'] = 2;

    $terminalForgery = s04SafeResult();
    $terminalForgery['profiles']['explicit_block']['ksef_statuses']['observed'] = ['not_sent', 'demo_processing'];

    $pdfForgery = s04SafeResult();
    $pdfForgery['profiles']['explicit_block']['pdf']['after']['hmac_sha256'] = hash('sha256', 'forged-pdf');

    $invalidOutcomeForgery = s04SafeResult();
    $invalidOutcomeForgery['profiles']['auto_persist']['exact_search']['invalid_gov_id_present'] = false;

    $invalidSequenceForgery = s04SafeResult();
    $invalidSequenceForgery['profiles']['auto_persist']['exact_search']['invalid_observations'][1]['gov_id_hmac_sha256'] = hash('sha256', 'changed-invalid-gov-id');

    $limitsForgery = s04SafeResult();
    $limitsForgery['probe_limits']['visibility_window_ms'] = 1;

    $validPartialForgery = s04SafeResult();
    $validPartialForgery['profiles']['explicit_block']['status_codes']['valid_issue'] = 206;
    $validPartialForgery['capability_0_2'] = KsefDemoContractProbe::resolveCapabilityPolicy($validPartialForgery['profiles']);

    $persistPartialForgery = s04SafeResult();
    $persistPartialForgery['profiles']['explicit_persist']['status_codes']['invalid_issue'] = 206;
    $persistPartialForgery['capability_0_2'] = KsefDemoContractProbe::resolveCapabilityPolicy($persistPartialForgery['profiles']);

    $sendStatusForgery = s04SafeResult();
    $sendStatusForgery['profiles']['explicit_block']['status_codes']['send'] = 500;
    $sendStatusForgery['capability_0_2'] = KsefDemoContractProbe::resolveCapabilityPolicy($sendStatusForgery['profiles']);

    $issueRegressionForgery = s04SafeResult();
    $issueRegressionForgery['profiles']['auto_block']['ksef_statuses']['issue'] = 'demo_processing';
    $issueRegressionForgery['profiles']['auto_block']['ksef_statuses']['before'] = 'not_sent';
    array_unshift($issueRegressionForgery['profiles']['auto_block']['ksef_statuses']['observed'], 'demo_processing');
    $issueRegressionForgery['capability_0_2'] = KsefDemoContractProbe::resolveCapabilityPolicy($issueRegressionForgery['profiles']);

    $pdfSizeForgery = s04SafeResult();
    $pdfSizeForgery['profiles']['explicit_block']['pdf']['before']['size'] = 8;

    $preSendForgery = s04SafeResult();
    $preSendForgery['profiles']['explicit_block']['ksef_statuses']['pre_send'] = 'demo_processing';
    $preSendForgery['capability_0_2'] = KsefDemoContractProbe::resolveCapabilityPolicy($preSendForgery['profiles']);

    $pdfLimitForgery = s04SafeResult();
    $pdfLimitForgery['probe_limits']['minimum_pdf_size_bytes'] = KsefDemoProbeConfiguration::DefaultMinimumPdfSizeBytes + 1;

    expect(fn () => KsefDemoFixtureGuard::assertSafe($sendForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($sendResponseForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($statusForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($capabilityForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($searchForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($terminalForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($pdfForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($invalidOutcomeForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($invalidSequenceForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($limitsForgery, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($validPartialForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($persistPartialForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($sendStatusForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($issueRegressionForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($pdfSizeForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($preSendForgery, []))->toThrow(RuntimeException::class)
        ->and(fn () => KsefDemoFixtureGuard::assertSafe($pdfLimitForgery, []))->toThrow(RuntimeException::class);
});

it('rejects provider-error and alternate identity shapes on account preflight before writes', function (array $fourthResponse): void {
    $configuration = s04Configuration();
    $transport = s04Transport(
        s04Responses(
            AccountKsefDemoRequest::class,
            s04Response(['id' => '1001']),
            s04Response(['id' => '1002']),
            s04Response(['id' => '1003']),
            s04Response($fourthResponse),
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $probe->collect())
        ->toThrow(RuntimeException::class, 'account preflight')
        ->and($transport->requestCount(AccountKsefDemoRequest::class))->toBe(4)
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(0);
})->with([
    'false success flag' => [['id' => '1004', 'success' => false]],
    'false ok flag' => [['id' => '1004', 'ok' => false]],
    'error message' => [['id' => '1004', 'message' => 'unauthorized']],
    'provider code' => [['id' => '1004', 'code' => 'unauthorized']],
    'nested account identity' => [['account' => ['id' => '1004']]],
    'non-canonical numeric alias' => [['id' => '01004']],
]);

it('fails tenant isolation before writes when different hosts resolve to one account', function (): void {
    $configuration = new KsefDemoProbeConfiguration([
        'explicit_block' => s04Profile('explicit_block', KsefOwnership::ExplicitSdk, KsefValidationMode::BlockInvalid, '9001'),
        'explicit_persist' => s04Profile('explicit_persist', KsefOwnership::ExplicitSdk, KsefValidationMode::PersistWithErrors, '9001'),
        'auto_block' => s04Profile('auto_block', KsefOwnership::ProviderAutoSend, KsefValidationMode::BlockInvalid, '9001'),
        'auto_persist' => s04Profile('auto_persist', KsefOwnership::ProviderAutoSend, KsefValidationMode::PersistWithErrors, '9001'),
    ]);
    $transport = s04Transport(
        s04Route(
            AccountKsefDemoRequest::class,
            [s04Response(['id' => '9001'])],
            repeatLast: true,
        ),
    );
    $probe = KsefDemoContractProbe::forTesting($configuration, $transport);

    expect(fn () => $probe->collect())
        ->toThrow(RuntimeException::class, 'distinct authoritative account ID');

    expect($transport->requestCount(AccountKsefDemoRequest::class))->toBe(4)
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(0);
});

it('fails when token-to-account identity changes after matrix preflight and before create', function (): void {
    $configuration = s04SignedConfiguration(s04Configuration()->evidenceLimits());
    $transport = s04Transport(
        s04Responses(
            AccountKsefDemoRequest::class,
            s04Response(['id' => s04AccountId('explicit_block')]),
            s04Response(['id' => s04AccountId('explicit_persist')]),
            s04Response(['id' => s04AccountId('auto_block')]),
            s04Response(['id' => s04AccountId('auto_persist')]),
            s04Response(['id' => '999999']),
        ),
    );
    $probe = s04AuthorizedProbe($configuration, $transport, true);

    expect(fn () => $probe->collectAuthorizedForTesting())
        ->toThrow(RuntimeException::class)
        ->and($transport->requestCount(AccountKsefDemoRequest::class))->toBe(5)
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(0);
});

it('does not send or follow an injected document ID returned by issue', function (): void {
    $configuration = s04SignedConfiguration(s04Configuration()->evidenceLimits());
    $routes = array_map(
        static fn (string $profileKey): KsefDemoLiteralResponseSequence => s04Route(
            AccountKsefDemoRequest::class,
            [s04Response(['id' => s04AccountId($profileKey)])],
            host: 's04-demo-'.str_replace('_', '-', $profileKey).'.fakturownia.pl',
        ),
        KsefDemoProbeConfiguration::profileKeys(),
    );
    $routes[] = s04Responses(
        CreateKsefDemoInvoiceRequest::class,
        s04Response(['id' => '../account?send_to_ksef=yes'], 201),
    );
    $transport = s04Transport(
        ...$routes,
    );
    $probe = s04AuthorizedProbe($configuration, $transport);

    expect(fn () => $probe->collectAuthorizedForTesting())
        ->toThrow(RuntimeException::class, 'did not return a document ID');

    expect($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(1)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(0)
        ->and($transport->requestCount(ReadKsefDemoInvoiceRequest::class))->toBe(0)
        ->and($transport->requestCount(SearchKsefDemoInvoicesRequest::class))->toBe(0);
});

it('keeps recovered and replayed authority receipts outside every mutating boundary', function (string $mode): void {
    $configuration = s04SignedConfiguration(s04Configuration()->evidenceLimits());
    $transport = s04Transport(
        s04Responses(
            AccountKsefDemoRequest::class,
            s04Response(['id' => s04AccountId('explicit_block')]),
            s04Response(['id' => s04AccountId('explicit_persist')]),
            s04Response(['id' => s04AccountId('auto_block')]),
            s04Response(['id' => s04AccountId('auto_persist')]),
        ),
    );
    $authority = new class($mode) implements LiveEvidenceConsumptionAuthority
    {
        public function __construct(private string $mode) {}

        public function claim(
            array $signedAuthorizations,
            ConsumptionClaimRequest $request,
        ): FreshClaimGrant|RecoveredConsumedProof {
            $runStartedAt = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.u\Z',
                $request->runStartedAt,
                new DateTimeZone('UTC'),
            );

            if (! $runStartedAt instanceof DateTimeImmutable) {
                throw new RuntimeException('The S0.4 replay test claim has an invalid run start.');
            }

            $claimRequest = $request->toArray();
            $disposition = LiveEvidenceAttestationGuard::FreshConsumptionDisposition;

            if ($this->mode === 'replay') {
                $claimRequest = LiveEvidenceAttestationGuard::buildConsumptionClaimRequest(
                    $signedAuthorizations,
                    $runStartedAt,
                    base64_encode(hash('sha256', 's04-stale-process-nonce', true)),
                );
            } else {
                $disposition = LiveEvidenceAttestationGuard::RecoveredConsumptionDisposition;
            }

            $issuedAt = $runStartedAt->modify('+1 microsecond');
            $envelope = LiveEvidenceAttestationGuard::buildConsumptionAuthorityEnvelopeForTesting(
                $signedAuthorizations,
                $claimRequest,
                s04AuthorityId(),
                ['store_id' => s04StoreId(), 'sequence' => '7'],
                $issuedAt,
                $issuedAt->modify('+1 hour'),
                $disposition,
            );
            $receipt = s04SignAuthorityEnvelope($envelope);

            return $this->mode === 'replay'
                ? new FreshClaimGrant($receipt)
                : new RecoveredConsumedProof($receipt);
        }
    };
    $clockTick = 0;
    $probe = KsefDemoContractProbe::forAuthorizedTesting(
        $configuration,
        $transport,
        $authority,
        ['s04-test-operator' => s04SigningMaterial()['public']],
        [s04AuthorityId() => s04AuthoritySigningMaterial()['public']],
        clock: function () use (&$clockTick): DateTimeImmutable {
            $now = (new DateTimeImmutable('2026-08-25T08:00:00.000000Z'))
                ->modify("+{$clockTick} microseconds");
            $clockTick++;

            return $now;
        },
    );

    expect(fn () => $probe->collectAuthorizedForTesting())
        ->toThrow(InvalidArgumentException::class)
        ->and($transport->requestCount(AccountKsefDemoRequest::class))->toBe(4)
        ->and($transport->requestCount(CreateKsefDemoInvoiceRequest::class))->toBe(0);
})->with(['recovered', 'replay']);

it('executes the mocked four-profile matrix with no manual auto-send', function (): void {
    $limits = s04Configuration()->evidenceLimits();
    $limits['visibility_window_ms'] = 500;
    $limits['visibility_poll_interval_ms'] = 10;
    $configuration = s04SignedConfiguration($limits);
    $fixtureDirectory = sys_get_temp_dir().'/fakturownia-s04-'.bin2hex(random_bytes(6));
    $routes = [];
    $profileIndexes = array_flip(KsefDemoProbeConfiguration::profileKeys());

    foreach (KsefDemoProbeConfiguration::profileKeys() as $profileKey) {
        $index = $profileIndexes[$profileKey] + 1;
        $validId = (string) ($index * 100);
        $invalidId = (string) (($index * 100) + 1);
        $host = 's04-demo-'.str_replace('_', '-', $profileKey).'.fakturownia.pl';
        $validOid = s04Oid($profileKey);
        $invalidOid = s04Oid($profileKey, true);
        $isBlock = str_ends_with($profileKey, 'block');
        $isExplicit = str_starts_with($profileKey, 'explicit');

        $routes[] = s04Route(
            AccountKsefDemoRequest::class,
            [s04Response(['id' => s04AccountId($profileKey)])],
            host: $host,
        );
        $routes[] = s04Route(
            CreateKsefDemoInvoiceRequest::class,
            [$isBlock
                ? s04Response(s04BlockInvalidResponse(), 422)
                : s04Response(['id' => $invalidId], 201)],
            host: $host,
            body: ['invoice' => ['oid' => $invalidOid]],
            afterRequestClass: AccountKsefDemoRequest::class,
            minimumRequestCount: 4,
        );
        $routes[] = s04Route(
            CreateKsefDemoInvoiceRequest::class,
            [s04Response(['id' => $validId], 201)],
            host: $host,
            body: ['invoice' => ['oid' => $validOid]],
            afterRequestClass: AccountKsefDemoRequest::class,
            minimumRequestCount: 4,
        );
        $routes[] = s04Route(
            SearchKsefDemoInvoicesRequest::class,
            [s04Response($isBlock ? [] : [['id' => $invalidId, 'oid' => $invalidOid]])],
            host: $host,
            query: ['oid' => $invalidOid],
            repeatLast: true,
        );
        $routes[] = s04Route(
            SearchKsefDemoInvoicesRequest::class,
            [s04Response([['id' => $validId, 'oid' => $validOid]])],
            host: $host,
            query: ['oid' => $validOid],
            repeatLast: true,
        );
        $routes[] = s04Route(
            DownloadKsefDemoPdfRequest::class,
            [s04Response(s04Pdf($profileKey), 200, ['Content-Type' => 'application/pdf'])],
            host: $host,
            repeatLast: true,
        );

        if (! $isBlock) {
            $routes[] = s04Route(
                ReadKsefDemoInvoiceRequest::class,
                [s04Response([
                    'id' => $invalidId,
                    'gov_status' => $isExplicit ? null : 'demo_ok',
                    'gov_id' => $isExplicit ? null : "demo-invalid-{$profileKey}",
                    'gov_error_messages' => ['buyer_tax_no_invalid'],
                ])],
                host: $host,
                endpoint: "/invoices/{$invalidId}.json",
                repeatLast: true,
            );
        }

        if ($isExplicit) {
            $requiredSendCount = $profileKey === 'explicit_block' ? 1 : 2;
            $routes[] = s04Route(
                ReadKsefDemoInvoiceRequest::class,
                [s04Response([
                    'id' => $validId,
                    'gov_status' => 'demo_ok',
                    'gov_id' => "demo-gov-{$profileKey}",
                ])],
                host: $host,
                endpoint: "/invoices/{$validId}.json",
                repeatLast: true,
                afterRequestClass: SendKsefDemoInvoiceRequest::class,
                minimumRequestCount: $requiredSendCount,
            );
            $routes[] = s04Route(
                ReadKsefDemoInvoiceRequest::class,
                [s04Response(['id' => $validId, 'gov_status' => null, 'gov_id' => null])],
                host: $host,
                endpoint: "/invoices/{$validId}.json",
                repeatLast: true,
            );
            $routes[] = s04Route(
                SendKsefDemoInvoiceRequest::class,
                [$profileKey === 'explicit_persist'
                    ? KsefDemoLiteralResponse::fail(KsefDemoLiteralFailure::transport(
                        'simulated ambiguous timeout',
                        28,
                        128,
                    ))
                    : s04Response(['id' => $validId, 'gov_status' => 'demo_processing'])],
                host: $host,
            );

            continue;
        }

        $routes[] = s04Route(
            ReadKsefDemoInvoiceRequest::class,
            [
                s04Response(['id' => $validId, 'gov_status' => 'demo_processing', 'gov_id' => null]),
                s04Response(['id' => $validId, 'gov_status' => 'demo_processing', 'gov_id' => null]),
                s04Response([
                    'id' => $validId,
                    'gov_status' => 'demo_ok',
                    'gov_id' => "demo-gov-{$profileKey}",
                ]),
            ],
            host: $host,
            endpoint: "/invoices/{$validId}.json",
            repeatLast: true,
        );
    }

    $transport = s04Transport(...$routes);

    $clockTick = 0;
    $clock = function () use (&$clockTick): DateTimeImmutable {
        $startedAt = new DateTimeImmutable('2026-08-25T08:00:00.000000Z');
        $now = $startedAt->modify("+{$clockTick} microseconds");
        $clockTick++;

        return $now;
    };
    $probe = KsefDemoContractProbe::forAuthorizedTesting(
        $configuration,
        $transport,
        s04FreshConsumptionAuthority(),
        ['s04-test-operator' => s04SigningMaterial()['public']],
        [s04AuthorityId() => s04AuthoritySigningMaterial()['public']],
        $fixtureDirectory,
        $clock,
    );

    expect(fn () => $probe->run())
        ->toThrow(RuntimeException::class, 'cannot publish canonical live KSeF evidence')
        ->and($transport->requestCount(AccountKsefDemoRequest::class))->toBe(0)
        ->and(is_dir($fixtureDirectory))->toBeFalse();

    $result = $probe->collectAuthorizedForTesting();
    $fixtureJson = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect($transport->requestCount(AccountKsefDemoRequest::class))->toBe(4)
        ->and($transport->requestCount(SendKsefDemoInvoiceRequest::class))->toBe(2)
        ->and($result['profiles']['explicit_block']['send_count'])->toBe(1)
        ->and($result['profiles']['explicit_persist']['send_count'])->toBe(1)
        ->and($result['profiles']['auto_block']['send_count'])->toBe(0)
        ->and($result['profiles']['auto_persist']['send_count'])->toBe(0)
        ->and($result['profiles']['auto_persist']['exact_search']['invalid_gov_errors_present'])->toBeTrue()
        ->and($result['capability_0_2']['matrix_complete'])->toBeTrue()
        ->and($fixtureJson)
        ->not->toContain('token-auto-persist')
        ->not->toContain('s04-demo-auto-persist.fakturownia.pl')
        ->not->toContain('"'.s04AccountId('auto_persist').'"')
        ->not->toContain(hash('sha256', s04Pdf('auto_persist')))
        ->and(is_dir($fixtureDirectory))->toBeFalse();
});
