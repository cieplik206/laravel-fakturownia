<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Contract\Support;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredExecutionRequiredException;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionClaimRequest;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request as GuzzlePsrRequest;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use JsonException;
use LogicException;
use ReflectionProperty;
use RuntimeException;
use Saloon\Config;
use Saloon\Contracts\Body\HasBody;
use Saloon\Contracts\Sender;
use Saloon\Data\FactoryCollection;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Helpers\MiddlewarePipeline;
use Saloon\Http\Connector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Http\Senders\Factories\GuzzleMultipartBodyFactory;
use Saloon\Http\Senders\GuzzleSender;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;
use SensitiveParameter;
use Throwable;

use function array_all;
use function array_column;
use function array_is_list;
use function array_key_exists;
use function array_key_last;
use function array_keys;
use function array_unique;
use function array_unshift;
use function array_values;
use function array_walk_recursive;
use function bin2hex;
use function ceil;
use function chmod;
use function count;
use function dirname;
use function explode;
use function fclose;
use function fflush;
use function fopen;
use function fsync;
use function fwrite;
use function gmdate;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_dir;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function max;
use function min;
use function mkdir;
use function preg_match;
use function realpath;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unlink;
use function usleep;

final class KsefDemoContractProbe implements LiveProviderTransportOrigin
{
    private const int MinimumBoundedRequestStartBudgetNanoseconds = 1_000_000;

    /** @var list<string> */
    private const KnownStatuses = [
        'not_sent',
        'demo_processing',
        'demo_ok',
        'demo_send_error',
        'demo_server_error',
        'demo_not_applicable',
        'demo_not_connected',
        'unknown',
    ];

    /** @var list<string> */
    private const TerminalStatuses = [
        'demo_ok',
        'demo_send_error',
        'demo_server_error',
        'demo_not_applicable',
        'demo_not_connected',
    ];

    private ?VerifiedFreshClaimGrant $verifiedFreshClaimGrant = null;

    private ?ConsumptionClaimRequest $consumptionClaimRequest = null;

    /**
     * @param  array<string, string>|null  $testTrustedOperatorSigners
     * @param  array<string, string>|null  $testTrustedConsumptionAuthorities
     */
    private function __construct(
        #[SensitiveParameter] private KsefDemoProbeConfiguration $configuration,
        private ?string $fixtureDirectory = null,
        #[SensitiveParameter] private ?KsefDemoInMemoryTransport $inMemoryTransport = null,
        #[SensitiveParameter] private ?Closure $testClock = null,
        private bool $testEffectIdentityChecks = false,
        #[SensitiveParameter] private ?LiveEvidenceConsumptionAuthority $testConsumptionAuthority = null,
        #[SensitiveParameter] private ?array $testTrustedOperatorSigners = null,
        #[SensitiveParameter] private ?array $testTrustedConsumptionAuthorities = null,
        private ?string $testRepositoryRoot = null,
    ) {}

    public static function forTesting(
        #[SensitiveParameter] KsefDemoProbeConfiguration $configuration,
        #[SensitiveParameter] KsefDemoInMemoryTransport $inMemoryTransport,
        ?string $fixtureDirectory = null,
        #[SensitiveParameter] ?Closure $clock = null,
        bool $verifyEffectAccountIdentity = false,
    ): self {
        KsefDemoSaloonRuntimeGuard::assertClean();

        return new self($configuration, $fixtureDirectory, $inMemoryTransport, $clock, $verifyEffectAccountIdentity);
    }

    /**
     * Production-adjacent offline seam. The sealed literal-response transport
     * has no fallback sender, fixture recorder, callable, filesystem, or network path.
     *
     * @param  array<string, string>  $trustedOperatorSigners
     * @param  array<string, string>  $trustedConsumptionAuthorities
     */
    public static function forAuthorizedTesting(
        #[SensitiveParameter] KsefDemoProbeConfiguration $configuration,
        #[SensitiveParameter] KsefDemoInMemoryTransport $inMemoryTransport,
        #[SensitiveParameter] LiveEvidenceConsumptionAuthority $consumptionAuthority,
        #[SensitiveParameter] array $trustedOperatorSigners,
        #[SensitiveParameter] array $trustedConsumptionAuthorities,
        ?string $fixtureDirectory = null,
        #[SensitiveParameter] ?Closure $clock = null,
        bool $verifyEffectAccountIdentity = false,
        ?string $repositoryRoot = null,
    ): self {
        KsefDemoSaloonRuntimeGuard::assertClean();

        return new self(
            $configuration,
            $fixtureDirectory,
            $inMemoryTransport,
            $clock,
            $verifyEffectAccountIdentity,
            $consumptionAuthority,
            $trustedOperatorSigners,
            $trustedConsumptionAuthorities,
            $repositoryRoot,
        );
    }

    /**
     * @return array{
     *     path: string,
     *     result: array<string, mixed>,
     *     unsigned_attestation_path: ?string,
     *     unsigned_attestation_envelope: ?array<string, mixed>
     * }
     */
    public static function runConfigured(): array
    {
        KsefDemoSaloonRuntimeGuard::assertClean();
        VerifiedKsefDemoLiveAuthorization::fromEnvironment();
    }

    public function assertRealProviderTransportOrigin(): void
    {
        KsefDemoSaloonRuntimeGuard::assertClean();

        throw new BrokeredExecutionRequiredException('brokered_effect_execution_unavailable');
    }

    /**
     * @return array{
     *     path: string,
     *     result: array<string, mixed>,
     *     unsigned_attestation_path: ?string,
     *     unsigned_attestation_envelope: ?array<string, mixed>
     * }
     */
    public function run(): array
    {
        KsefDemoSaloonRuntimeGuard::assertClean();

        if ($this->inMemoryTransport !== null) {
            throw new RuntimeException('An in-memory authority grant cannot publish canonical live KSeF evidence.');
        }

        throw new BrokeredExecutionRequiredException('brokered_effect_execution_unavailable');
    }

    /**
     * Executes the complete effect flow only against the sealed literal-response transport.
     * The result is deliberately in-memory and cannot be published as canonical live evidence.
     *
     * @return array<string, mixed>
     */
    public function collectAuthorizedForTesting(): array
    {
        if (! $this->inMemoryTransport instanceof KsefDemoInMemoryTransport
            || ! $this->testConsumptionAuthority instanceof LiveEvidenceConsumptionAuthority
            || $this->testTrustedOperatorSigners === null
            || $this->testTrustedConsumptionAuthorities === null) {
            throw new RuntimeException('An authorized offline run requires the sealed literal-response authority seam.');
        }

        $runStartedAt = $this->currentUtc();

        try {
            $accountPreflights = $this->preflightAccounts();
            $signedAuthorizations = $this->configuration->signedAuthorizations();
            $claimNonce = base64_encode(\random_bytes(32));
            $claimRequestArray = LiveEvidenceAttestationGuard::buildConsumptionClaimRequest(
                $signedAuthorizations,
                $runStartedAt,
                $claimNonce,
            );
            $this->consumptionClaimRequest = ConsumptionClaimRequest::fromArray($claimRequestArray);
            $this->verifiedFreshClaimGrant = LiveEvidenceAttestationGuard::claimAuthorizationSignaturesWithAuthorityNow(
                $signedAuthorizations,
                $runStartedAt,
                $this->currentUtc(),
                KsefDemoProbeConfiguration::MaximumAuthorizationTtlSeconds,
                KsefDemoProbeConfiguration::MaximumAuthorizationTtlSeconds,
                $this->testConsumptionAuthority,
                $claimNonce,
                $this->testTrustedOperatorSigners,
                $this->testTrustedConsumptionAuthorities,
            );

            return $this->collectAfterPreflight($runStartedAt, $accountPreflights);
        } finally {
            $this->verifiedFreshClaimGrant = null;
            $this->consumptionClaimRequest = null;
            $this->configuration->destroyBindingKeys();
        }
    }

    /** @return array<string, mixed> */
    public function collect(?DateTimeImmutable $runStartedAt = null): array
    {
        $runStartedAt ??= $this->currentUtc();
        $accountPreflights = $this->preflightAccounts();

        if (! $this->verifiedFreshClaimGrant instanceof VerifiedFreshClaimGrant
            || ! $this->consumptionClaimRequest instanceof ConsumptionClaimRequest) {
            throw new RuntimeException('The KSeF effect phase requires a fresh externally verified single-use claim grant.');
        }

        return $this->collectAfterPreflight($runStartedAt, $accountPreflights);
    }

    /** @return array<string, array{status: int, account_id: string}> */
    private function preflightAccounts(): array
    {
        $accountPreflights = [];

        foreach ($this->configuration->profiles as $key => $profile) {
            $accountPreflights[$key] = $this->preflight($profile);
        }

        $accountIds = array_column($accountPreflights, 'account_id');

        if (count(array_unique($accountIds)) !== count($accountIds)) {
            throw new RuntimeException('Every KSeF DEMO profile must resolve to a distinct authoritative account ID before any write.');
        }

        return $accountPreflights;
    }

    /**
     * @param  array<string, array{status: int, account_id: string}>  $accountPreflights
     * @return array<string, mixed>
     */
    private function collectAfterPreflight(
        DateTimeImmutable $runStartedAt,
        #[SensitiveParameter] array $accountPreflights,
    ): array {

        $runEvidenceKey = \random_bytes(32);

        try {
            $profiles = [];

            foreach ($this->configuration->profiles as $key => $profile) {
                $profiles[$key] = $this->profileEvidence(
                    $profile,
                    $accountPreflights[$key]['status'],
                    $runEvidenceKey,
                );
            }

            $capability = self::resolveCapabilityPolicy($profiles);

            if (($capability['matrix_complete'] ?? false) !== true) {
                throw new RuntimeException('The KSeF DEMO matrix is incomplete; no fixture will be written.');
            }

            $runFinishedAt = $this->currentUtc();

            if (self::instantMicroseconds($runFinishedAt) <= self::instantMicroseconds($runStartedAt)) {
                $runFinishedAt = $runStartedAt->modify('+1 microsecond');
            }

            $result = [
                'contract' => KsefDemoProbeConfiguration::EvidenceContract,
                'run' => [
                    'started_at' => $runStartedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                    'finished_at' => $runFinishedAt->format('Y-m-d\TH:i:s.u\Z'),
                    'environment' => 'ksef_demo',
                    'launch_manifest_sha256' => $this->configuration->launchManifestSha256(),
                ],
                'probe_limits' => $this->configuration->evidenceLimits(),
                'profiles' => $profiles,
                'capability_0_2' => $capability,
            ];

            self::strictUtcFixtureDate($result['run']['started_at']);
            self::strictUtcFixtureDate($result['run']['finished_at']);

            if ($this->inMemoryTransport === null) {
                KsefDemoFixtureGuard::assertSafe($result, $this->sensitiveValues());
            } else {
                KsefDemoFixtureGuard::assertSafeForTesting($result, $this->sensitiveValues());
            }

            return $result;
        } finally {
            if (\function_exists('sodium_memzero')) {
                \sodium_memzero($runEvidenceKey);
            }
        }
    }

    private static function strictUtcFixtureDate(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $value) !== 1) {
            throw new RuntimeException('The KSeF fixture run timestamp is not canonical UTC.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new RuntimeException('The KSeF fixture run timestamp is invalid.');
        }

        return $date;
    }

    /**
     * @param  array<string, array<string, mixed>>  $profiles
     * @return array<string, mixed>
     */
    public static function resolveCapabilityPolicy(array $profiles): array
    {
        $expected = [
            'explicit_block' => ['profile' => 'explicit_sdk+block_invalid', 'send_count' => 1, 'invalid_count' => 0, 'invalid_status' => 422, 'errors' => false, 'invalid_outcomes' => ['rejected_not_persisted']],
            'explicit_persist' => ['profile' => 'explicit_sdk+persist_with_errors', 'send_count' => 1, 'invalid_count' => 1, 'invalid_status' => null, 'errors' => true, 'invalid_outcomes' => ['persisted_with_errors']],
            'auto_block' => ['profile' => 'provider_auto_send+block_invalid', 'send_count' => 0, 'invalid_count' => 0, 'invalid_status' => 422, 'errors' => false, 'invalid_outcomes' => ['rejected_not_persisted']],
            'auto_persist' => ['profile' => 'provider_auto_send+persist_with_errors', 'send_count' => 0, 'invalid_count' => 1, 'invalid_status' => null, 'errors' => true, 'invalid_outcomes' => ['persisted_with_errors_demo_rejected', 'persisted_with_errors_demo_accepted']],
        ];
        $complete = array_keys($profiles) === array_keys($expected);

        foreach ($expected as $key => $requirements) {
            $profile = $profiles[$key] ?? [];
            $statusCodes = is_array($profile['status_codes'] ?? null) ? $profile['status_codes'] : [];
            $ksefStatuses = is_array($profile['ksef_statuses'] ?? null) ? $profile['ksef_statuses'] : [];
            $exactSearch = is_array($profile['exact_search'] ?? null) ? $profile['exact_search'] : [];
            $invalidStatus = $statusCodes['invalid_issue'] ?? null;
            $invalidStatusMatches = $requirements['invalid_status'] === null
                ? $invalidStatus === 201
                : $invalidStatus === $requirements['invalid_status'];
            $derivedInvalidOutcome = self::deriveInvalidOutcome($exactSearch);
            $invalidFinalReadMatches = $requirements['invalid_count'] === 0
                ? ($statusCodes['invalid_final_read'] ?? null) === null
                : ($statusCodes['invalid_final_read'] ?? null) === 200;
            $baseStatusesMatch = ($statusCodes['account_preflight'] ?? null) === 200
                && ($statusCodes['valid_issue'] ?? null) === 201
                && ($statusCodes['preflight_read'] ?? null) === 200
                && ($statusCodes['pdf_before_boundary_read'] ?? null) === 200
                && ($statusCodes['terminal_read'] ?? null) === 200
                && ($statusCodes['pdf_before'] ?? null) === 200
                && ($statusCodes['pdf_after'] ?? null) === 200
                && ($statusCodes['pdf_after_boundary_read'] ?? null) === 200
                && ($statusCodes['final_read'] ?? null) === 200;
            $sendStatus = $statusCodes['send'] ?? null;
            $preSendReadStatus = $statusCodes['pre_send_read'] ?? null;
            $sendStatusMatches = $requirements['send_count'] === 0
                ? $sendStatus === null
                : $sendStatus === null || $sendStatus === 200;
            $preSendReadStatusMatches = $requirements['send_count'] === 0
                ? $preSendReadStatus === null
                : $preSendReadStatus === 200;
            $issueStatus = $ksefStatuses['issue'] ?? null;
            $beforeStatus = $ksefStatuses['before'] ?? null;
            $beforePdfBoundaryStatus = $ksefStatuses['pdf_before_boundary'] ?? null;
            $preSendStatus = $ksefStatuses['pre_send'] ?? null;
            $afterSendStatus = $ksefStatuses['after_send'] ?? null;
            $beforeStatusMatches = $requirements['send_count'] === 1
                ? $beforeStatus === 'not_sent'
                : in_array($beforeStatus, ['not_sent', 'demo_processing'], true);
            $issueStatusMatches = $requirements['send_count'] === 1
                ? in_array($issueStatus, [null, 'not_sent'], true)
                : in_array($issueStatus, [null, 'not_sent', 'demo_processing'], true);
            $issueTransitionMatches = $issueStatus === null
                || self::nonTerminalStatusRank($issueStatus) <= self::nonTerminalStatusRank($beforeStatus);
            $beforePdfBoundaryMatches = $requirements['send_count'] === 1
                ? $beforePdfBoundaryStatus === 'not_sent'
                : in_array($beforePdfBoundaryStatus, ['not_sent', 'demo_processing'], true)
                    && self::nonTerminalStatusRank($beforePdfBoundaryStatus) >= self::nonTerminalStatusRank($beforeStatus);
            $preSendStatusMatches = $requirements['send_count'] === 1
                ? $preSendStatus === 'not_sent'
                : $preSendStatus === null;
            $afterSendStatusMatches = $requirements['send_count'] === 1
                ? in_array($afterSendStatus, [null, 'not_sent', 'demo_processing', 'demo_ok'], true)
                : $afterSendStatus === null;
            $sendResponseEvidenceMatches = $requirements['send_count'] === 0
                ? $sendStatus === null && $afterSendStatus === null
                : ($sendStatus === null) === ($afterSendStatus === null);
            $complete = $complete
                && ($profile['profile'] ?? null) === $requirements['profile']
                && ($profile['send_count'] ?? null) === $requirements['send_count']
                && ($ksefStatuses['terminal'] ?? null) === 'demo_ok'
                && ($ksefStatuses['terminal_gov_id_present'] ?? null) === true
                && ($ksefStatuses['terminal_stable'] ?? null) === true
                && is_int($ksefStatuses['terminal_observations'] ?? null)
                && $ksefStatuses['terminal_observations'] >= 2
                && ($ksefStatuses['pdf_after_boundary'] ?? null) === 'demo_ok'
                && ($ksefStatuses['pdf_after_boundary_gov_id_present'] ?? null) === true
                && ($ksefStatuses['final'] ?? null) === 'demo_ok'
                && ($ksefStatuses['final_gov_id_present'] ?? null) === true
                && self::observedStatusesMatch(
                    $ksefStatuses['observed'] ?? null,
                    $issueStatus,
                    $beforePdfBoundaryStatus,
                    $preSendStatus,
                    $afterSendStatus,
                    $ksefStatuses['terminal_observations'],
                )
                && ($exactSearch['valid_count'] ?? null) === 1
                && ($exactSearch['invalid_count'] ?? null) === $requirements['invalid_count']
                && ($exactSearch['all_results_exact'] ?? null) === true
                && ($exactSearch['invalid_gov_errors_present'] ?? null) === $requirements['errors']
                && ($exactSearch['invalid_explicit_send_count'] ?? null) === 0
                && ($exactSearch['invalid_outcome'] ?? null) === $derivedInvalidOutcome
                && in_array($derivedInvalidOutcome, $requirements['invalid_outcomes'], true)
                && $invalidStatusMatches
                && $baseStatusesMatch
                && $sendStatusMatches
                && $preSendReadStatusMatches
                && $issueStatusMatches
                && $issueTransitionMatches
                && $invalidFinalReadMatches
                && $beforeStatusMatches
                && $beforePdfBoundaryMatches
                && $preSendStatusMatches
                && $afterSendStatusMatches
                && $sendResponseEvidenceMatches
                && self::validPdfEvidence($profile['pdf'] ?? null);
        }

        return [
            'matrix_complete' => $complete,
            'supported_profile' => $complete ? 'explicit_sdk+block_invalid' : 'none',
            'issue_ksef_behavior' => 'never_send',
            'ensure_accepted' => 'separate_operation',
            'provider_auto_send' => 'observe_only',
            'persist_with_errors' => 'recognized_outside_pilot',
            'gov_save_and_send' => 'unsupported_low_level',
            'payments' => 'outside_gate',
            'webhooks' => 'outside_gate',
        ];
    }

    /** @param array<string, mixed> $evidence */
    private static function deriveInvalidOutcome(array $evidence): ?string
    {
        $category = $evidence['invalid_validation_error_category'] ?? null;
        $status = $evidence['invalid_ksef_status'] ?? null;
        $hasGovId = $evidence['invalid_gov_id_present'] ?? null;
        $stable = $evidence['invalid_terminal_stable'] ?? null;
        $terminalObservations = $evidence['invalid_terminal_observations'] ?? null;
        $observations = $evidence['invalid_observations'] ?? null;

        if (($evidence['all_results_exact'] ?? null) !== true
            || ($evidence['invalid_explicit_send_count'] ?? null) !== 0
            || ! is_bool($hasGovId)
            || $stable !== true
            || ! is_int($terminalObservations)
            || $terminalObservations < 0
            || ! is_array($observations)
            || ! array_is_list($observations)) {
            return null;
        }

        if (($evidence['invalid_count'] ?? null) === 0
            && ($evidence['invalid_gov_errors_present'] ?? null) === false
            && $category === null
            && $status === null
            && $hasGovId === false
            && $terminalObservations === 0
            && $observations === []) {
            return 'rejected_not_persisted';
        }

        if (($evidence['invalid_count'] ?? null) !== 1
            || ($evidence['invalid_gov_errors_present'] ?? null) !== true
            || $category !== 'expected_validation_leaf_gov_error'
            || $observations === []) {
            return null;
        }

        $previousRank = -1;
        $previousStatus = null;

        foreach ($observations as $observation) {
            if (! is_array($observation)
                || array_keys($observation) !== ['status', 'gov_id_hmac_sha256', 'validation_error_category']
                || ! in_array($observation['status'] ?? null, ['not_sent', 'demo_processing', 'demo_send_error', 'demo_ok'], true)
                || ! in_array($observation['validation_error_category'] ?? null, [null, 'expected_validation_leaf_gov_error'], true)
                || (($observation['gov_id_hmac_sha256'] ?? null) !== null
                    && (! is_string($observation['gov_id_hmac_sha256'])
                        || preg_match('/^[a-f0-9]{64}$/', $observation['gov_id_hmac_sha256']) !== 1))) {
                return null;
            }

            $rank = ['not_sent' => 0, 'demo_processing' => 1, 'demo_send_error' => 2, 'demo_ok' => 2][$observation['status']];

            if ($rank < $previousRank
                || ($observation['status'] !== 'demo_ok' && $observation['gov_id_hmac_sha256'] !== null)
                || ($previousRank === 2 && $observation['status'] !== $previousStatus)) {
                return null;
            }

            $previousRank = $rank;
            $previousStatus = $observation['status'];
        }

        $lastObservation = $observations[array_key_last($observations)];
        $lastGovIdHmac = $lastObservation['gov_id_hmac_sha256'];
        $stableTerminalObservations = 0;

        if (in_array($lastObservation['status'], ['demo_send_error', 'demo_ok'], true)) {
            for ($index = count($observations) - 1; $index >= 0; $index--) {
                $observation = $observations[$index];

                if ($observation['status'] !== $lastObservation['status']
                    || $observation['validation_error_category'] !== 'expected_validation_leaf_gov_error'
                    || ($lastObservation['status'] === 'demo_ok'
                        && (! is_string($lastGovIdHmac)
                            || ! is_string($observation['gov_id_hmac_sha256'])
                            || ! \hash_equals($lastGovIdHmac, $observation['gov_id_hmac_sha256'])))
                    || ($lastObservation['status'] !== 'demo_ok' && $observation['gov_id_hmac_sha256'] !== null)) {
                    break;
                }

                $stableTerminalObservations++;
            }
        }

        if ($lastObservation['status'] !== $status
            || ($lastObservation['gov_id_hmac_sha256'] !== null) !== $hasGovId
            || $lastObservation['validation_error_category'] !== $category
            || $stableTerminalObservations !== $terminalObservations) {
            return null;
        }

        if (in_array($status, ['not_sent', 'demo_processing'], true)
            && $hasGovId === false
            && $terminalObservations === 0) {
            return 'persisted_with_errors';
        }

        if ($status === 'demo_ok'
            && $hasGovId === true
            && $terminalObservations >= 2
            && is_string($lastGovIdHmac)) {
            return 'persisted_with_errors_demo_accepted';
        }

        if ($status === 'demo_send_error'
            && $hasGovId === false
            && $terminalObservations >= 2
            && $lastGovIdHmac === null) {
            return 'persisted_with_errors_demo_rejected';
        }

        return null;
    }

    private static function nonTerminalStatusRank(mixed $status): int
    {
        return match ($status) {
            'not_sent' => 0,
            'demo_processing' => 1,
            default => -1,
        };
    }

    public static function normalizeKsefStatus(mixed $status): string
    {
        if ($status === null) {
            return 'not_sent';
        }

        if (! is_string($status) || ! in_array($status, self::KnownStatuses, true)) {
            return 'unknown';
        }

        return $status;
    }

    /** @return array{mime: string, size: int, sha256: string} */
    public static function describePdf(
        #[SensitiveParameter] Response $response,
        int $minimumSizeBytes = KsefDemoProbeConfiguration::DefaultMinimumPdfSizeBytes,
    ): array {
        $contentType = $response->header('Content-Type');
        $mime = is_string($contentType) ? strtolower(trim(explode(';', $contentType)[0])) : '';
        $body = $response->body();

        $size = strlen($body);

        if ($response->status() !== 200
            || $mime !== 'application/pdf'
            || ! str_starts_with($body, '%PDF-')
            || ! str_ends_with(rtrim($body), '%%EOF')
            || $size < $minimumSizeBytes
            || $size > 25 * 1024 * 1024) {
            throw new RuntimeException('The KSeF DEMO PDF response is not a valid PDF artifact.');
        }

        return [
            'mime' => $mime,
            'size' => $size,
            'sha256' => \hash('sha256', $body),
        ];
    }

    /** @return array{mime: string, size: int, hmac_sha256: string} */
    private static function describePdfEvidence(
        #[SensitiveParameter] Response $response,
        #[SensitiveParameter] string $evidenceKey,
        int $minimumSizeBytes,
    ): array {
        if (strlen($evidenceKey) !== 32) {
            throw new RuntimeException('The KSeF PDF evidence requires an ephemeral 32-byte binding key.');
        }

        $descriptor = self::describePdf($response, $minimumSizeBytes);

        return [
            'mime' => $descriptor['mime'],
            'size' => $descriptor['size'],
            'hmac_sha256' => \hash_hmac('sha256', $response->body(), $evidenceKey),
        ];
    }

    /** @return array{status: int, account_id: string} */
    private function preflight(#[SensitiveParameter] KsefDemoProfile $profile): array
    {
        try {
            $response = $this->connector($profile)->send(new AccountKsefDemoRequest($profile->endpoint->token));
            $body = self::jsonObject($response);
        } catch (Throwable $exception) {
            throw new RuntimeException('A KSeF DEMO account preflight failed before any write ('.$exception::class.').');
        }

        $canonicalAccountId = array_key_exists('id', $body)
            ? KsefDemoAccountId::fromRemote($body['id'])
            : null;

        if (array_key_exists('account', $body)
            || self::hasProviderEnvelopeError($body, [])
            || $canonicalAccountId === null) {
            throw new RuntimeException('A KSeF DEMO account preflight returned a non-canonical identity shape or provider-error evidence.');
        }

        if ($response->status() !== 200) {
            throw new RuntimeException('A KSeF DEMO account preflight did not return an account ID.');
        }

        $profile->endpoint->verifyAccountId($canonicalAccountId);

        return ['status' => $response->status(), 'account_id' => $canonicalAccountId];
    }

    /** @return array<string, mixed> */
    private function profileEvidence(
        #[SensitiveParameter] KsefDemoProfile $profile,
        int $accountStatus,
        #[SensitiveParameter] string $runEvidenceKey,
    ): array {
        $nonce = $this->inMemoryTransport instanceof KsefDemoInMemoryTransport
            ? KsefDemoInMemoryTransport::DeterministicScenarioNonce
            : bin2hex(\random_bytes(6));
        $validOid = "s04-{$profile->key}-{$nonce}-valid";
        $invalidOid = "s04-{$profile->key}-{$nonce}-invalid";
        $validInvoice = $this->invoice($profile->validInvoice, $validOid, "{$profile->key}-valid");
        $invalidInvoice = $this->invoice($profile->invalidInvoice, $invalidOid, "{$profile->key}-invalid");
        $validIssue = $this->send($profile, new CreateKsefDemoInvoiceRequest($profile->endpoint->token, $validInvoice), 'Valid KSeF DEMO invoice issue failed.');
        $validId = self::documentId($validIssue);

        if ($validIssue->status() !== 201 || $validId === null || self::hasProviderError($validIssue)) {
            throw new RuntimeException('Valid KSeF DEMO invoice issue did not return a document ID.');
        }

        $validIssueSnapshot = self::optionalIssueSnapshot($validIssue, $validId, $profile, false);

        $validSearch = $profile->ownership === KsefOwnership::ExplicitSdk
            ? $this->exactSearch($profile, $validOid, $validId)
            : null;

        if ($validSearch !== null && ($validSearch['count'] !== 1 || ! $validSearch['exact'])) {
            throw new RuntimeException('The KSeF DEMO valid-invoice identity evidence is incomplete.');
        }

        $beforeRead = $this->read($profile, $validId);
        $beforeSnapshot = self::strictSnapshot($beforeRead, $validId);
        $beforeStatus = $beforeSnapshot['status'];

        if ($validIssueSnapshot !== null) {
            self::assertAcceptanceStatusTransition($validIssueSnapshot['status'], $beforeStatus);
        }

        if ($beforeSnapshot['gov_errors_present']) {
            throw new RuntimeException('The valid KSeF DEMO invoice contains validation errors.');
        }

        if ($profile->ownership === KsefOwnership::ExplicitSdk) {
            $beforeRead = $this->observeExplicitUnsent($profile, $validId, $beforeRead);
            $beforeSnapshot = self::strictSnapshot($beforeRead, $validId);
            $beforeStatus = $beforeSnapshot['status'];
        }

        if (in_array($beforeStatus, self::TerminalStatuses, true)) {
            throw new RuntimeException('The pre-acceptance KSeF snapshot was already terminal.');
        }

        $prePdfStatus = $beforeStatus;

        $beforePdfResponse = $this->send($profile, new DownloadKsefDemoPdfRequest($profile->endpoint->token, $validId), 'Pre-acceptance KSeF DEMO PDF download failed.');
        $beforePdf = self::describePdfEvidence(
            $beforePdfResponse,
            $runEvidenceKey,
            $this->configuration->minimumPdfSizeBytes,
        );
        $beforePdfBoundaryRead = $this->read($profile, $validId);
        $beforePdfBoundary = self::strictSnapshot($beforePdfBoundaryRead, $validId);

        if (in_array($beforePdfBoundary['status'], self::TerminalStatuses, true)
            || $beforePdfBoundary['gov_id'] !== null
            || $beforePdfBoundary['gov_errors_present']
            || ($profile->ownership === KsefOwnership::ProviderAutoSend
                && self::nonTerminalStatusRank($beforePdfBoundary['status'])
                    < self::nonTerminalStatusRank($beforeStatus))
            || ($profile->ownership === KsefOwnership::ExplicitSdk && $beforePdfBoundary['status'] !== 'not_sent')) {
            throw new RuntimeException('The KSeF DEMO invoice crossed the acceptance boundary during the pre-acceptance PDF observation.');
        }

        if ($profile->ownership === KsefOwnership::ProviderAutoSend) {
            $validSearch = $this->exactSearch($profile, $validOid, $validId);
        }

        if ($validSearch === null || $validSearch['count'] !== 1 || ! $validSearch['exact']) {
            throw new RuntimeException('The KSeF DEMO valid-invoice identity evidence is incomplete.');
        }

        $validation = $this->validationEvidence($profile, $invalidInvoice, $invalidOid, $runEvidenceKey);
        $acceptance = $this->ensureAccepted($profile, $validId, $beforePdfBoundary['status']);
        $afterPdfResponse = $this->send($profile, new DownloadKsefDemoPdfRequest($profile->endpoint->token, $validId), 'Post-acceptance KSeF DEMO PDF download failed.');
        $afterPdf = self::describePdfEvidence(
            $afterPdfResponse,
            $runEvidenceKey,
            $this->configuration->minimumPdfSizeBytes,
        );
        [$afterPdfBoundaryRead, $afterPdfBoundary] = $this->observePostAcceptancePdfBoundary(
            $profile,
            $validId,
            $acceptance['terminal_gov_id'],
        );

        $acceptance['observed'][] = $afterPdfBoundary['status'];
        $acceptance['terminal_observations']++;
        $validSearch = $this->exactSearch($profile, $validOid, $validId);

        if ($validSearch['count'] !== 1 || ! $validSearch['exact']) {
            throw new RuntimeException('The final KSeF DEMO valid-invoice identity evidence is incomplete.');
        }

        $finalInvalidSearch = $this->exactSearch(
            $profile,
            $invalidOid,
            $validation['expected_document_id'],
        );
        $expectedInvalidCount = $validation['expected_document_id'] === null ? 0 : 1;

        if ($finalInvalidSearch['count'] !== $expectedInvalidCount || ! $finalInvalidSearch['exact']) {
            throw new RuntimeException('The final KSeF DEMO invalid-invoice identity evidence is incomplete or changed after validation.');
        }

        $validation['search'] = $finalInvalidSearch;

        if ($validation['expected_document_id'] !== null) {
            $finalInvalidRead = $this->read($profile, $validation['expected_document_id']);
            $finalInvalidSnapshot = self::strictSnapshot($finalInvalidRead, $validation['expected_document_id']);
            $expectedInvalidSnapshot = $validation['expected_snapshot'];

            if (! is_array($expectedInvalidSnapshot)
                || $finalInvalidSnapshot['status'] !== $expectedInvalidSnapshot['status']
                || $finalInvalidSnapshot['gov_id'] !== $expectedInvalidSnapshot['gov_id']
                || $finalInvalidSnapshot['gov_errors_present'] !== $expectedInvalidSnapshot['gov_errors_present']
                || $finalInvalidSnapshot['gov_errors_expected_validation_field'] !== $expectedInvalidSnapshot['gov_errors_expected_validation_field']
                || $finalInvalidSnapshot['gov_errors_memory_digest'] !== $expectedInvalidSnapshot['gov_errors_memory_digest']) {
                throw new RuntimeException('The final persisted invalid-invoice status, identity or validation evidence changed after validation.');
            }

            $validation['final_read_status'] = $finalInvalidRead->status();
        }

        [$finalRead, $finalSnapshot] = $this->observePostAcceptancePdfBoundary(
            $profile,
            $validId,
            $acceptance['terminal_gov_id'],
        );
        $acceptance['observed'][] = $finalSnapshot['status'];
        $acceptance['terminal_observations']++;

        if ($validIssueSnapshot !== null) {
            array_unshift($acceptance['observed'], $validIssueSnapshot['status']);
        }

        return [
            'profile' => "{$profile->ownership->value}+{$profile->validationMode->value}",
            'status_codes' => [
                'account_preflight' => $accountStatus,
                'valid_issue' => $validIssue->status(),
                'invalid_issue' => $validation['issue_status'],
                'invalid_final_read' => $validation['final_read_status'],
                'preflight_read' => $beforeRead->status(),
                'pdf_before_boundary_read' => $beforePdfBoundaryRead->status(),
                'pre_send_read' => $acceptance['pre_send_read_status'],
                'send' => $acceptance['send_status'],
                'terminal_read' => $acceptance['terminal_read_status'],
                'pdf_before' => $beforePdfResponse->status(),
                'pdf_after' => $afterPdfResponse->status(),
                'pdf_after_boundary_read' => $afterPdfBoundaryRead->status(),
                'final_read' => $finalRead->status(),
            ],
            'ksef_statuses' => [
                'issue' => $validIssueSnapshot['status'] ?? null,
                'before' => $prePdfStatus,
                'pdf_before_boundary' => $beforePdfBoundary['status'],
                'pre_send' => $acceptance['pre_send_status'],
                'after_send' => $acceptance['after_send_status'],
                'terminal' => $acceptance['terminal_status'],
                'terminal_gov_id_present' => $acceptance['terminal_gov_id_present'],
                'terminal_stable' => $acceptance['terminal_stable'],
                'terminal_observations' => $acceptance['terminal_observations'],
                'pdf_after_boundary' => $afterPdfBoundary['status'],
                'pdf_after_boundary_gov_id_present' => true,
                'final' => $finalSnapshot['status'],
                'final_gov_id_present' => true,
                'observed' => $acceptance['observed'],
            ],
            'send_count' => $acceptance['send_count'],
            'exact_search' => [
                'valid_count' => $validSearch['count'],
                'invalid_count' => $validation['search']['count'],
                'all_results_exact' => self::searchResultsExact($validSearch, $validation['search']),
                'invalid_gov_errors_present' => $validation['gov_errors_present'],
                'invalid_validation_error_category' => $validation['gov_errors_present']
                    ? 'expected_validation_leaf_gov_error'
                    : null,
                'invalid_ksef_status' => $validation['ksef_status'],
                'invalid_gov_id_present' => $validation['gov_id_present'],
                'invalid_terminal_stable' => $validation['terminal_stable'],
                'invalid_terminal_observations' => $validation['terminal_observations'],
                'invalid_observations' => $validation['observations'],
                'invalid_explicit_send_count' => $validation['explicit_send_count'],
                'invalid_outcome' => $validation['outcome'],
            ],
            'pdf' => [
                'before' => $beforePdf,
                'after' => $afterPdf,
                'equal' => \hash_equals($beforePdf['hmac_sha256'], $afterPdf['hmac_sha256']),
            ],
        ];
    }

    /**
     * @return array{0: Response, 1: array{status: string, gov_id: ?string, gov_errors_present: bool, gov_errors_expected_validation_field: bool, gov_errors_memory_digest: ?string}}
     */
    private function observePostAcceptancePdfBoundary(
        #[SensitiveParameter] KsefDemoProfile $profile,
        #[SensitiveParameter] string $documentId,
        #[SensitiveParameter] string $expectedGovId,
    ): array {
        $response = $this->read($profile, $documentId);
        $snapshot = self::strictSnapshot($response, $documentId);

        if ($snapshot['status'] !== 'demo_ok'
            || $snapshot['gov_id'] === null
            || ! \hash_equals($expectedGovId, $snapshot['gov_id'])
            || $snapshot['gov_errors_present']) {
            throw new RuntimeException('The KSeF DEMO terminal identity changed during the post-acceptance PDF observation.');
        }

        return [$response, $snapshot];
    }

    /**
     * @param  array<string, mixed>  $invalidInvoice
     * @return array{issue_status: int, final_read_status: ?int, gov_errors_present: bool, gov_id_present: bool, ksef_status: ?string, terminal_stable: bool, terminal_observations: int, observations: list<array{status: string, gov_id_hmac_sha256: ?string, validation_error_category: ?string}>, explicit_send_count: int, outcome: string, expected_document_id: ?string, expected_snapshot: ?array{status: string, gov_id: ?string, gov_errors_present: bool, gov_errors_expected_validation_field: bool, gov_errors_memory_digest: ?string}, search: array{count: int, exact: bool}}
     */
    private function validationEvidence(
        #[SensitiveParameter] KsefDemoProfile $profile,
        #[SensitiveParameter] array $invalidInvoice,
        #[SensitiveParameter] string $invalidOid,
        #[SensitiveParameter] string $runEvidenceKey,
    ): array {
        $response = $this->send($profile, new CreateKsefDemoInvoiceRequest($profile->endpoint->token, $invalidInvoice), 'Invalid KSeF DEMO validation request failed at transport level.');
        $body = self::jsonObject($response);

        if ($profile->validationMode === KsefValidationMode::BlockInvalid) {
            $invoice = self::invoiceObject($body);

            if ($response->status() !== 422
                || ! self::hasStrictExpectedValidationError($body, $profile->expectedValidationField)
                || array_key_exists('id', $invoice)
                || array_key_exists('gov_status', $invoice)
                || array_key_exists('gov_id', $invoice)
                || ($body['success'] ?? null) === true
                || ($body['ok'] ?? null) === true) {
                throw new RuntimeException('BlockInvalid did not return the expected strict validation rejection.');
            }

            $search = $this->exactSearch($profile, $invalidOid);

            if ($search['count'] !== 0 || ! $search['exact']) {
                throw new RuntimeException("BlockInvalid {$profile->key} persisted or incompletely observed an invoice that should have been rejected (count={$search['count']}, exact=".($search['exact'] ? 'yes' : 'no').').');
            }

            return [
                'issue_status' => $response->status(),
                'final_read_status' => null,
                'gov_errors_present' => false,
                'gov_id_present' => false,
                'ksef_status' => null,
                'terminal_stable' => true,
                'terminal_observations' => 0,
                'observations' => [],
                'explicit_send_count' => 0,
                'outcome' => 'rejected_not_persisted',
                'expected_document_id' => null,
                'expected_snapshot' => null,
                'search' => $search,
            ];
        }

        $documentId = self::documentId($response);

        if ($response->status() !== 201
            || $documentId === null
            || self::hasProviderEnvelopeError($body, self::invoiceObject($body))) {
            throw new RuntimeException('PersistWithErrors did not persist the invalid KSeF DEMO invoice.');
        }

        $issueSnapshot = self::optionalIssueSnapshot($response, $documentId, $profile, true);
        $snapshotBeforeSearch = $this->observePersistedValidationErrors($profile, $documentId, $runEvidenceKey, $issueSnapshot);
        $search = $this->exactSearch($profile, $invalidOid, $documentId);
        $snapshot = $this->observePersistedValidationErrors(
            $profile,
            $documentId,
            $runEvidenceKey,
            $snapshotBeforeSearch,
        );

        if (! $snapshot['gov_errors_present'] || $search['count'] !== 1 || ! $search['exact']) {
            throw new RuntimeException('PersistWithErrors did not expose both persistence and KSeF validation errors.');
        }

        if ($profile->ownership === KsefOwnership::ExplicitSdk) {
            if ($snapshot['status'] !== 'not_sent' || $snapshot['gov_id'] !== null) {
                throw new RuntimeException('ExplicitSdk PersistWithErrors unexpectedly reached provider auto-send evidence.');
            }

            $outcome = 'persisted_with_errors';
        } elseif ($snapshot['status'] === 'demo_ok' && $snapshot['gov_id'] !== null) {
            $outcome = 'persisted_with_errors_demo_accepted';
        } elseif ($snapshot['status'] === 'demo_send_error' && $snapshot['gov_id'] === null) {
            $outcome = 'persisted_with_errors_demo_rejected';
        } elseif (in_array($snapshot['status'], ['not_sent', 'demo_processing'], true) && $snapshot['gov_id'] === null) {
            throw new RuntimeException('ProviderAutoSend PersistWithErrors did not reach a stable bounded terminal outcome.');
        } else {
            throw new RuntimeException('ProviderAutoSend PersistWithErrors returned contradictory terminal or gov_id evidence.');
        }

        return [
            'issue_status' => $response->status(),
            'final_read_status' => null,
            'gov_errors_present' => true,
            'gov_id_present' => $snapshot['gov_id'] !== null,
            'ksef_status' => $snapshot['status'],
            'terminal_stable' => $snapshot['terminal_stable'],
            'terminal_observations' => $snapshot['terminal_observations'],
            'observations' => $snapshot['observations'],
            'explicit_send_count' => 0,
            'outcome' => $outcome,
            'expected_document_id' => $documentId,
            'expected_snapshot' => [
                'status' => $snapshot['status'],
                'gov_id' => $snapshot['gov_id'],
                'gov_errors_present' => $snapshot['gov_errors_present'],
                'gov_errors_expected_validation_field' => $snapshot['gov_errors_expected_validation_field'],
                'gov_errors_memory_digest' => $snapshot['gov_errors_memory_digest'],
            ],
            'search' => $search,
        ];
    }

    /**
     * @param  null|array{status: string, gov_id: ?string, gov_errors_present: bool, gov_errors_expected_validation_field: bool, gov_errors_memory_digest: ?string, terminal_stable?: bool, terminal_observations?: int, observations?: list<array{status: string, gov_id_hmac_sha256: ?string, validation_error_category: ?string}>}  $initialSnapshot
     * @return array{status: string, gov_id: ?string, gov_errors_present: bool, gov_errors_expected_validation_field: bool, gov_errors_memory_digest: ?string, terminal_stable: bool, terminal_observations: int, observations: list<array{status: string, gov_id_hmac_sha256: ?string, validation_error_category: ?string}>}
     */
    private function observePersistedValidationErrors(
        #[SensitiveParameter] KsefDemoProfile $profile,
        #[SensitiveParameter] string $documentId,
        #[SensitiveParameter] string $runEvidenceKey,
        #[SensitiveParameter] ?array $initialSnapshot = null,
    ): array {
        $deadline = \hrtime(true) + ($this->configuration->visibilityWindowMs * 1_000_000);
        $snapshot = null;
        $terminalStatus = null;
        $terminalGovId = null;
        $terminalErrorsSha256 = null;
        $terminalObservations = 0;
        $observations = [];
        $previousStatus = null;

        $recordSnapshot = function (#[SensitiveParameter] array $currentSnapshot) use (
            &$observations,
            &$previousStatus,
            &$terminalErrorsSha256,
            &$terminalGovId,
            &$terminalObservations,
            &$terminalStatus,
            $runEvidenceKey,
        ): void {
            if ($currentSnapshot['gov_errors_present'] && ! $currentSnapshot['gov_errors_expected_validation_field']) {
                throw new RuntimeException('PersistWithErrors did not expose an explicit buyer_tax_no validation error.');
            }

            if ($previousStatus !== null) {
                self::assertPersistedValidationStatusTransition($previousStatus, $currentSnapshot['status']);
            }

            $previousStatus = $currentSnapshot['status'];
            $observations[] = [
                'status' => $currentSnapshot['status'],
                'gov_id_hmac_sha256' => $currentSnapshot['gov_id'] === null
                    ? null
                    : \hash_hmac('sha256', 'ksef-invalid-gov-id|'.$currentSnapshot['gov_id'], $runEvidenceKey),
                'validation_error_category' => $currentSnapshot['gov_errors_expected_validation_field']
                    ? 'expected_validation_leaf_gov_error'
                    : null,
            ];

            if ($terminalStatus !== null) {
                if ($currentSnapshot['status'] !== $terminalStatus
                    || ($terminalStatus === 'demo_ok'
                        && ($terminalGovId === null
                            || $currentSnapshot['gov_id'] === null
                            || ! \hash_equals($terminalGovId, $currentSnapshot['gov_id'])))
                    || ($terminalStatus === 'demo_send_error' && $currentSnapshot['gov_id'] !== null)) {
                    throw new RuntimeException('PersistWithErrors terminal evidence regressed or changed identity before the final boundary.');
                }
            } elseif ($currentSnapshot['status'] === 'demo_ok') {
                if ($currentSnapshot['gov_id'] === null) {
                    throw new RuntimeException('PersistWithErrors accepted evidence has no canonical gov_id.');
                }

                $terminalStatus = 'demo_ok';
                $terminalGovId = $currentSnapshot['gov_id'];
            } elseif ($currentSnapshot['status'] === 'demo_send_error') {
                if ($currentSnapshot['gov_id'] !== null) {
                    throw new RuntimeException('PersistWithErrors rejected terminal evidence unexpectedly has a gov_id.');
                }

                $terminalStatus = 'demo_send_error';
            } elseif (in_array($currentSnapshot['status'], self::TerminalStatuses, true)) {
                throw new RuntimeException('PersistWithErrors reached a contradictory non-success terminal state.');
            }

            if ($terminalStatus !== null && $currentSnapshot['gov_errors_memory_digest'] !== null) {
                if ($terminalErrorsSha256 === null) {
                    $terminalErrorsSha256 = $currentSnapshot['gov_errors_memory_digest'];
                } elseif (! \hash_equals($terminalErrorsSha256, $currentSnapshot['gov_errors_memory_digest'])) {
                    throw new RuntimeException('PersistWithErrors validation-error evidence changed during the terminal suffix.');
                }

                $terminalObservations++;
            } elseif ($terminalErrorsSha256 !== null) {
                throw new RuntimeException('PersistWithErrors validation-error evidence disappeared during the terminal suffix.');
            }
        };

        if ($initialSnapshot !== null) {
            $snapshot = $initialSnapshot;
            $recordSnapshot($snapshot);
        }

        while (true) {
            $detail = $this->read($profile, $documentId, $deadline);
            $snapshot = self::strictSnapshot($detail, $documentId);
            $recordSnapshot($snapshot);

            if (! self::hasBoundedRequestStartBudget($deadline)) {
                break;
            }

            self::sleepUntilNextPoll($deadline, $this->configuration->visibilityPollIntervalMs);

            if (! self::hasBoundedRequestStartBudget($deadline)) {
                break;
            }
        }

        if (! $snapshot['gov_errors_present']) {
            throw new RuntimeException('PersistWithErrors did not expose bounded KSeF validation-error evidence.');
        }

        $terminalStable = $profile->ownership === KsefOwnership::ExplicitSdk
            ? $terminalStatus === null
            : $terminalStatus !== null && $terminalObservations >= 2;

        if (! $terminalStable) {
            throw new RuntimeException('PersistWithErrors did not prove a stable terminal suffix.');
        }

        return [
            ...$snapshot,
            'terminal_stable' => $terminalStable,
            'terminal_observations' => $terminalObservations,
            'observations' => $observations,
        ];
    }

    /**
     * @return array{send_count: int, pre_send_read_status: ?int, pre_send_status: ?string, send_status: ?int, after_send_status: ?string, terminal_status: string, terminal_gov_id: string, terminal_gov_id_present: bool, terminal_stable: bool, terminal_observations: int, terminal_read_status: int, observed: list<string>}
     */
    private function ensureAccepted(
        #[SensitiveParameter] KsefDemoProfile $profile,
        #[SensitiveParameter] string $documentId,
        string $beforeStatus,
    ): array {
        $sendCount = 0;
        $preSendReadStatus = null;
        $preSendStatus = null;
        $sendStatus = null;
        $afterSendStatus = null;
        $observed = [$beforeStatus];

        if ($profile->ownership === KsefOwnership::ExplicitSdk) {
            $this->assertCurrentAccountIdentity($profile);
            $preSendRead = $this->read($profile, $documentId);
            $preSendSnapshot = self::strictSnapshot($preSendRead, $documentId);
            $preSendReadStatus = $preSendRead->status();
            $preSendStatus = $preSendSnapshot['status'];

            if ($preSendSnapshot['status'] !== 'not_sent'
                || $preSendSnapshot['gov_id'] !== null
                || $preSendSnapshot['gov_errors_present']) {
                throw new RuntimeException('ExplicitSdk just-in-time pre-send evidence is no longer unsent.');
            }

            if ($this->assertCurrentAccountIdentity($profile)) {
                $preSendRead = $this->read($profile, $documentId);
                $preSendSnapshot = self::strictSnapshot($preSendRead, $documentId);
                $preSendReadStatus = $preSendRead->status();
                $preSendStatus = $preSendSnapshot['status'];

                if ($preSendSnapshot['status'] !== 'not_sent'
                    || $preSendSnapshot['gov_id'] !== null
                    || $preSendSnapshot['gov_errors_present']) {
                    throw new RuntimeException('ExplicitSdk final pre-send evidence is no longer unsent.');
                }

                $this->assertCurrentAccountIdentity($profile);
            }

            $observed[] = $preSendSnapshot['status'];
            $sendCount = 1;
            $send = null;
            $this->assertWriteBoundaryAuthorized($profile);

            try {
                $send = $this->connector($profile)->send(new SendKsefDemoInvoiceRequest($profile->endpoint->token, $documentId));
            } catch (FatalRequestException $exception) {
                if (! self::isAmbiguousSemanticSendFailure($exception)) {
                    throw new RuntimeException('The explicit KSeF DEMO send failed before an ambiguous transport boundary.');
                }

                // An ambiguous transport failure is reconciled by reads only; the write is never retried.
            } catch (Throwable) {
                throw new RuntimeException('The explicit KSeF DEMO send failed at a non-reconcilable local transport boundary.');
            }

            if ($send !== null) {
                if ($send->status() !== 200) {
                    throw new RuntimeException('The explicit KSeF DEMO send did not return the exact 200 status.');
                }

                $sendSnapshot = self::strictSnapshot($send, $documentId);

                if ($sendSnapshot['gov_errors_present']) {
                    throw new RuntimeException('The valid KSeF DEMO send response contains provider validation errors.');
                }

                $sendStatus = $send->status();
                $afterSendStatus = $sendSnapshot['status'];
                self::assertAcceptanceStatusTransition($beforeStatus, $afterSendStatus);
                $observed[] = $afterSendStatus;
            }
        }

        $deadline = \hrtime(true) + ($this->configuration->pollWindowMs * 1_000_000);
        $terminalReadStatus = 0;
        $terminalStatus = 'unknown';
        $terminalGovIdPresent = false;
        $terminalGovId = null;
        $terminalObservations = 0;
        $previousStatus = $afterSendStatus ?? $beforeStatus;

        while (true) {
            $read = $this->read($profile, $documentId, $deadline);
            $snapshot = self::strictSnapshot($read, $documentId);
            $terminalReadStatus = $read->status();
            $terminalStatus = $snapshot['status'];
            $terminalGovIdPresent = $snapshot['gov_id'] !== null;
            self::assertAcceptanceStatusTransition($previousStatus, $terminalStatus);
            $previousStatus = $terminalStatus;
            $observed[] = $terminalStatus;

            if ($snapshot['gov_errors_present']) {
                throw new RuntimeException('The valid KSeF DEMO terminal observation contains validation errors.');
            }

            if ($terminalGovId !== null) {
                if ($terminalStatus !== 'demo_ok'
                    || $snapshot['gov_id'] === null
                    || ! \hash_equals($terminalGovId, $snapshot['gov_id'])) {
                    throw new RuntimeException('The KSeF DEMO terminal status or gov_id was not stable through the final observation boundary.');
                }

                $terminalObservations++;
            } elseif (in_array($terminalStatus, self::TerminalStatuses, true)) {
                if ($terminalStatus !== 'demo_ok' || $snapshot['gov_id'] === null) {
                    throw new RuntimeException('The KSeF DEMO acceptance reached a non-success terminal or missing gov_id.');
                }

                $terminalGovId = $snapshot['gov_id'];
                $terminalObservations = 1;
            }

            if (! self::hasBoundedRequestStartBudget($deadline)) {
                break;
            }

            self::sleepUntilNextPoll($deadline, $this->configuration->pollIntervalMs);

            if (! self::hasBoundedRequestStartBudget($deadline)) {
                break;
            }
        }

        $terminalStable = $terminalGovId !== null && $terminalObservations >= 2;

        if ($sendCount !== $profile->expectedKsefSendCount()
            || $terminalStatus !== 'demo_ok'
            || ! $terminalGovIdPresent
            || $terminalGovId === null
            || ! $terminalStable) {
            throw new RuntimeException('The KSeF DEMO acceptance policy did not prove stable demo_ok with the expected send count.');
        }

        return [
            'send_count' => $sendCount,
            'pre_send_read_status' => $preSendReadStatus,
            'pre_send_status' => $preSendStatus,
            'send_status' => $sendStatus,
            'after_send_status' => $afterSendStatus,
            'terminal_status' => $terminalStatus,
            'terminal_gov_id' => $terminalGovId,
            'terminal_gov_id_present' => $terminalGovIdPresent,
            'terminal_stable' => $terminalStable,
            'terminal_observations' => $terminalObservations,
            'terminal_read_status' => $terminalReadStatus,
            'observed' => $observed,
        ];
    }

    private static function assertAcceptanceStatusTransition(string $previous, string $current): void
    {
        $previousRank = ['not_sent' => 0, 'demo_processing' => 1, 'demo_ok' => 2][$previous] ?? -1;
        $currentRank = ['not_sent' => 0, 'demo_processing' => 1, 'demo_ok' => 2][$current] ?? -1;

        if ($currentRank < $previousRank || $currentRank < 0) {
            throw new RuntimeException('The KSeF DEMO acceptance status regressed or reached a non-success terminal state.');
        }
    }

    private static function assertPersistedValidationStatusTransition(string $previous, string $current): void
    {
        $previousRank = ['not_sent' => 0, 'demo_processing' => 1, 'demo_send_error' => 2, 'demo_ok' => 2][$previous] ?? -1;
        $currentRank = ['not_sent' => 0, 'demo_processing' => 1, 'demo_send_error' => 2, 'demo_ok' => 2][$current] ?? -1;

        if ($currentRank < $previousRank
            || $currentRank < 0
            || ($previousRank === 2 && $current !== $previous)) {
            throw new RuntimeException('PersistWithErrors status regressed or changed terminal outcome before the final boundary.');
        }
    }

    /** @return array{count: int, exact: bool} */
    private function exactSearch(
        #[SensitiveParameter] KsefDemoProfile $profile,
        #[SensitiveParameter] string $oid,
        #[SensitiveParameter] ?string $expectedDocumentId = null,
    ): array {
        $deadline = \hrtime(true) + ($this->configuration->visibilityWindowMs * 1_000_000);
        $exact = true;
        $complete = true;
        $documents = [];
        while (true) {
            $snapshot = $this->searchSnapshot($profile, $oid, $deadline);
            $exact = $exact && $snapshot['exact'];
            $complete = $complete && $snapshot['complete'];

            foreach ($snapshot['documents'] as $document) {
                $documents[$document['id']] = $document;
            }

            if (! self::hasBoundedRequestStartBudget($deadline)) {
                break;
            }

            self::sleepUntilNextPoll($deadline, $this->configuration->visibilityPollIntervalMs);

            if (! self::hasBoundedRequestStartBudget($deadline)) {
                break;
            }
        }

        $finalSnapshotDeadline = \hrtime(true) + (min(
            $this->configuration->visibilityWindowMs,
            $this->configuration->requestTimeoutMs,
        ) * 1_000_000);
        $finalSnapshot = $this->searchSnapshot($profile, $oid, $finalSnapshotDeadline);
        $exact = $exact && $finalSnapshot['exact'];
        $complete = $complete && $finalSnapshot['complete'];

        foreach ($finalSnapshot['documents'] as $document) {
            $documents[$document['id']] = $document;
        }

        $documentIds = array_column(array_values($documents), 'id');
        $finalDocumentIds = array_column($finalSnapshot['documents'], 'id');
        $identityMatches = $expectedDocumentId === null
            ? $finalDocumentIds === []
            : count($documentIds) === 1
                && \hash_equals($expectedDocumentId, $documentIds[0])
                && count($finalDocumentIds) === 1
                && \hash_equals($expectedDocumentId, $finalDocumentIds[0]);

        return ['count' => count($documentIds), 'exact' => $exact && $complete && $identityMatches];
    }

    /**
     * @param  array{count: int, exact: bool}  $validSearch
     * @param  array{count: int, exact: bool}  $invalidSearch
     */
    private static function searchResultsExact(array $validSearch, array $invalidSearch): bool
    {
        return $validSearch['exact'] && $invalidSearch['exact'];
    }

    /** @return array{documents: list<array{id: string, oid: string}>, exact: bool, complete: bool} */
    private function searchSnapshot(
        #[SensitiveParameter] KsefDemoProfile $profile,
        #[SensitiveParameter] string $oid,
        ?int $deadline = null,
    ): array {
        $documents = [];
        $exact = true;
        $complete = false;

        for ($page = 1; $page <= $this->configuration->maxSearchPages; $page++) {
            if ($deadline !== null && ! self::hasBoundedRequestStartBudget($deadline)) {
                break;
            }

            $response = $this->send(
                $profile,
                new SearchKsefDemoInvoicesRequest($profile->endpoint->token, $oid, $page),
                'Exact KSeF DEMO invoice search failed.',
                $deadline,
            );

            if ($response->status() !== 200) {
                throw new RuntimeException('Exact KSeF DEMO invoice search did not return a complete 200 snapshot.');
            }

            $body = self::jsonArray($response);

            foreach ($body as $document) {
                $documentId = is_array($document)
                    ? KsefDemoDocumentId::fromRemote($document['id'] ?? null)
                    : null;

                if (! is_array($document)
                    || $documentId === null
                    || ! is_scalar($document['oid'] ?? null)
                    || (string) $document['oid'] !== $oid
                    || self::hasSearchItemProviderError($document)) {
                    $exact = false;

                    continue;
                }

                $documents[] = ['id' => $documentId, 'oid' => (string) $document['oid']];
            }

            if (count($body) < 100) {
                $complete = true;

                break;
            }
        }

        return ['documents' => $documents, 'exact' => $exact, 'complete' => $complete];
    }

    /** @param array<array-key, mixed> $document */
    private static function hasSearchItemProviderError(array $document): bool
    {
        return array_key_exists('error', $document)
            || array_key_exists('errors', $document)
            || array_key_exists('message', $document)
            || array_key_exists('code', $document)
            || self::isProviderErrorStatus($document['status'] ?? null)
            || (array_key_exists('success', $document) && $document['success'] !== true)
            || (array_key_exists('ok', $document) && $document['ok'] !== true);
    }

    private static function isAmbiguousSemanticSendFailure(#[SensitiveParameter] FatalRequestException $exception): bool
    {
        $previous = $exception->getPrevious();

        if ($previous instanceof GuzzleRequestException && $previous->getResponse() !== null) {
            return false;
        }

        if (! $previous instanceof ConnectException && ! $previous instanceof GuzzleRequestException) {
            return false;
        }

        $context = $previous->getHandlerContext();
        $requestSize = $context['request_size'] ?? null;
        $requestStarted = (is_int($requestSize) || is_float($requestSize)) && $requestSize > 0;

        return ($context['errno'] ?? null) === 28 && $requestStarted;
    }

    /** @return null|array{status: string, gov_id: ?string, gov_errors_present: bool, gov_errors_expected_validation_field: bool, gov_errors_memory_digest: ?string} */
    private static function optionalIssueSnapshot(
        #[SensitiveParameter] Response $response,
        #[SensitiveParameter] string $documentId,
        #[SensitiveParameter] KsefDemoProfile $profile,
        bool $allowExpectedValidationErrors,
    ): ?array {
        $invoice = self::invoiceObject(self::jsonObject($response));
        $hasKsefFields = array_key_exists('gov_status', $invoice)
            || array_key_exists('gov_id', $invoice)
            || array_key_exists('gov_error_messages', $invoice);

        if (! $hasKsefFields) {
            return null;
        }

        $snapshot = self::strictSnapshot($response, $documentId);

        if ($snapshot['gov_errors_present']
            && (! $allowExpectedValidationErrors || ! $snapshot['gov_errors_expected_validation_field'])) {
            throw new RuntimeException('A KSeF DEMO issue response contained unexpected provider validation errors.');
        }

        if ($profile->ownership === KsefOwnership::ExplicitSdk
            && ($snapshot['status'] !== 'not_sent' || $snapshot['gov_id'] !== null)) {
            throw new RuntimeException('ExplicitSdk issue returned evidence of an implicit KSeF send.');
        }

        if (! $allowExpectedValidationErrors
            && (in_array($snapshot['status'], self::TerminalStatuses, true) || $snapshot['gov_id'] !== null)) {
            throw new RuntimeException('The valid KSeF DEMO issue response was already terminal.');
        }

        return $snapshot;
    }

    private function read(
        #[SensitiveParameter] KsefDemoProfile $profile,
        #[SensitiveParameter] string $documentId,
        ?int $deadline = null,
    ): Response {
        $response = $this->send(
            $profile,
            new ReadKsefDemoInvoiceRequest($profile->endpoint->token, $documentId),
            'KSeF DEMO invoice status read failed.',
            $deadline,
        );

        if ($response->status() !== 200) {
            throw new RuntimeException('KSeF DEMO invoice status read did not return the exact 200 status.');
        }

        self::strictSnapshot($response, $documentId);

        return $response;
    }

    private function observeExplicitUnsent(
        #[SensitiveParameter] KsefDemoProfile $profile,
        #[SensitiveParameter] string $documentId,
        #[SensitiveParameter] Response $initial,
    ): Response {
        $deadline = \hrtime(true) + ($this->configuration->preSendObservationWindowMs * 1_000_000);
        $current = $initial;

        while (true) {
            $snapshot = self::strictSnapshot($current, $documentId);

            if ($snapshot['status'] !== 'not_sent' || $snapshot['gov_errors_present']) {
                throw new RuntimeException('ExplicitSdk preflight detected an unexpected KSeF send before ensure_accepted.');
            }

            if (! self::hasBoundedRequestStartBudget($deadline)) {
                break;
            }

            self::sleepUntilNextPoll($deadline, $this->configuration->pollIntervalMs);

            if (! self::hasBoundedRequestStartBudget($deadline)) {
                break;
            }

            $current = $this->read($profile, $documentId, $deadline);
        }

        return $current;
    }

    private static function sleepUntilNextPoll(int $deadline, int $intervalMs): void
    {
        $now = \hrtime(true);

        if (! is_int($now)) {
            throw new RuntimeException('The KSeF DEMO probe requires an integer monotonic clock.');
        }

        $remainingMicroseconds = intdiv(max(0, $deadline - $now), 1_000);
        $sleepMicroseconds = min($intervalMs * 1_000, $remainingMicroseconds);

        if ($sleepMicroseconds > 0) {
            usleep($sleepMicroseconds);
        }
    }

    private static function hasBoundedRequestStartBudget(int $deadline): bool
    {
        $now = \hrtime(true);

        if (! is_int($now)) {
            throw new RuntimeException('The KSeF DEMO probe requires an integer monotonic clock.');
        }

        return $deadline - $now >= self::MinimumBoundedRequestStartBudgetNanoseconds;
    }

    private function connector(#[SensitiveParameter] KsefDemoProfile $profile, ?int $remainingTimeoutMs = null): KsefDemoConnector
    {
        KsefDemoSaloonRuntimeGuard::assertClean();

        $connectTimeoutMs = $remainingTimeoutMs === null
            ? $this->configuration->connectTimeoutMs
            : min($this->configuration->connectTimeoutMs, $remainingTimeoutMs);
        $requestTimeoutMs = $remainingTimeoutMs === null
            ? $this->configuration->requestTimeoutMs
            : min($this->configuration->requestTimeoutMs, $remainingTimeoutMs);
        $connector = new KsefDemoConnector(
            $profile->endpoint->baseUrl,
            $connectTimeoutMs,
            $requestTimeoutMs,
            $this->inMemoryTransport,
        );

        return $connector;
    }

    private function send(
        #[SensitiveParameter] KsefDemoProfile $profile,
        #[SensitiveParameter] Request $request,
        string $failure,
        ?int $deadline = null,
    ): Response {
        if ($request instanceof CreateKsefDemoInvoiceRequest) {
            $this->assertCurrentAccountIdentity($profile);
            $this->assertWriteBoundaryAuthorized($profile);
        }

        $remainingTimeoutMs = null;

        if ($deadline !== null) {
            $remainingNanoseconds = $deadline - \hrtime(true);

            if ($remainingNanoseconds <= 0) {
                throw new RuntimeException('The bounded KSeF DEMO request window elapsed before the next HTTP request.');
            }

            $remainingTimeoutMs = max(1, intdiv($remainingNanoseconds + 999_999, 1_000_000));
        }

        try {
            return $this->connector($profile, $remainingTimeoutMs)->send($request);
        } catch (Throwable) {
            throw new RuntimeException($failure);
        }
    }

    private function assertWriteBoundaryAuthorized(#[SensitiveParameter] KsefDemoProfile $profile): void
    {
        if (! $this->testConsumptionAuthority instanceof LiveEvidenceConsumptionAuthority) {
            if ($this->inMemoryTransport === null) {
                throw new RuntimeException('A fresh external consumption-authority grant is required at every KSeF effect boundary.');
            }

            if ($this->testClock === null) {
                return;
            }

            $profile->assertWriteAuthorizedAt(
                $this->currentUtc(),
                (int) ceil($this->configuration->requestTimeoutMs / 1_000),
            );

            return;
        }

        if (! $this->verifiedFreshClaimGrant instanceof VerifiedFreshClaimGrant
            || ! $this->consumptionClaimRequest instanceof ConsumptionClaimRequest
            || $this->testTrustedOperatorSigners === null
            || $this->testTrustedConsumptionAuthorities === null) {
            throw new RuntimeException('The KSeF effect boundary has no branded fresh authority grant.');
        }

        $now = $this->currentUtc();

        $profile->assertWriteAuthorizedAt(
            $now,
            (int) ceil($this->configuration->requestTimeoutMs / 1_000),
        );

        LiveEvidenceAttestationGuard::assertVerifiedFreshGrantSignaturesAtEffectBoundary(
            $this->verifiedFreshClaimGrant,
            $this->configuration->signedAuthorizations(),
            $this->consumptionClaimRequest,
            $now,
            KsefDemoProbeConfiguration::MaximumAuthorizationTtlSeconds,
            (int) ceil($this->configuration->requestTimeoutMs / 1_000),
            KsefDemoProbeConfiguration::MaximumAuthorizationTtlSeconds,
            $this->testTrustedOperatorSigners,
            $this->testTrustedConsumptionAuthorities,
        );
    }

    private function assertCurrentAccountIdentity(#[SensitiveParameter] KsefDemoProfile $profile): bool
    {
        if ($this->inMemoryTransport instanceof KsefDemoInMemoryTransport && ! $this->testEffectIdentityChecks) {
            return false;
        }

        $this->preflight($profile);

        return true;
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function invoice(
        #[SensitiveParameter] array $template,
        #[SensitiveParameter] string $oid,
        string $marker,
    ): array {
        return [
            ...$template,
            'kind' => 'vat',
            'oid' => $oid,
            'oid_unique' => 'yes',
            'internal_note' => "s0.4-{$marker}",
        ];
    }

    /** @return list<string> */
    private function sensitiveValues(): array
    {
        $values = [];

        foreach ($this->configuration->profiles as $profile) {
            $values[] = $profile->endpoint->token;
            $values[] = $profile->endpoint->host;
            $values[] = $profile->settingsChecksum;

            $collectPii = static function (mixed $value, string|int $key) use (&$values): void {
                if (is_string($key)
                    && preg_match('/(?:buyer|seller|name|tax_(?:no|id)|nip|vat_(?:no|id)|address|street|city|post|phone|email|note)/i', $key) === 1
                    && is_scalar($value)
                    && ! is_bool($value)
                    && strlen(trim((string) $value)) >= 4) {
                    $values[] = trim((string) $value);
                }
            };
            array_walk_recursive($profile->validInvoice, $collectPii);
            array_walk_recursive($profile->invalidInvoice, $collectPii);
        }

        return array_values(array_unique($values));
    }

    /** @param array<string, mixed> $result */
    protected function writeFixture(#[SensitiveParameter] array $result): string
    {
        $directory = $this->fixtureDirectory ?? dirname(__DIR__, 2).'/Fixtures/Contract';

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the KSeF contract fixture directory.');
        }

        $path = $directory.'/ksef-demo-'.gmdate('YmdHis').'-'.bin2hex(\random_bytes(5)).'.json';
        $json = \json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $this->assertSanitizedArtifact($json);
        $handle = fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException('Could not exclusively create the KSeF contract fixture.');
        }

        $written = 0;

        try {
            while ($written < strlen($json)) {
                $bytes = fwrite($handle, substr($json, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('Could not exclusively write the KSeF contract fixture.');
                }

                $written += $bytes;
            }

            if (! fflush($handle)
                || (\function_exists('fsync') && ! fsync($handle))
                || ! chmod($path, 0644)) {
                throw new RuntimeException('Could not durably publish the KSeF contract fixture.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            unlink($path);

            throw $exception;
        }

        fclose($handle);

        return $path;
    }

    protected function relativeFixturePath(string $path): string
    {
        $repositoryRoot = realpath($this->repositoryRoot());
        $fixturePath = realpath($path);

        if ($repositoryRoot === false
            || $fixturePath === false
            || ! str_starts_with($fixturePath, $repositoryRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The live KSeF fixture must be published inside the SDK repository.');
        }

        return substr($fixturePath, strlen($repositoryRoot) + 1);
    }

    private function repositoryRoot(): string
    {
        return $this->testRepositoryRoot ?? dirname(__DIR__, 3);
    }

    private function currentUtc(): DateTimeImmutable
    {
        $now = $this->testClock === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : ($this->testClock)();

        if (! $now instanceof DateTimeImmutable) {
            throw new RuntimeException('The test-only KSeF clock must return DateTimeImmutable.');
        }

        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    private static function instantMicroseconds(DateTimeImmutable $date): int
    {
        return ((int) $date->format('U') * 1_000_000) + (int) $date->format('u');
    }

    private function assertSanitizedArtifact(string $artifact): void
    {
        foreach ($this->sensitiveValues() as $sensitive) {
            if ($sensitive !== '' && str_contains($artifact, $sensitive)) {
                throw new RuntimeException('A KSeF evidence artifact contains a sensitive configured value.');
            }
        }

        if (preg_match('/(?:https?:\/\/|%PDF-|api_token|buyer_tax_no|gov_error_messages|"(?:id|oid|host|url|body|headers?|errors?|messages?)")/i', $artifact) === 1) {
            throw new RuntimeException('A KSeF evidence artifact contains forbidden raw transport or identity data.');
        }
    }

    /** @return array<array-key, mixed> */
    private static function jsonObject(#[SensitiveParameter] Response $response): array
    {
        try {
            $decoded = \json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('A KSeF DEMO response did not contain a JSON object.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('A KSeF DEMO response did not contain a JSON object.');
        }

        return $decoded;
    }

    /** @return list<mixed> */
    private static function jsonArray(#[SensitiveParameter] Response $response): array
    {
        $decoded = self::jsonObject($response);

        if (! array_is_list($decoded)) {
            throw new RuntimeException('A KSeF DEMO search response did not contain a JSON list.');
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<string, mixed>
     */
    private static function invoiceObject(#[SensitiveParameter] array $body): array
    {
        $invoice = $body['invoice'] ?? null;

        if (array_key_exists('invoice', $body) && (! is_array($invoice) || array_is_list($invoice))) {
            throw new RuntimeException('A KSeF DEMO response contained an invalid invoice envelope.');
        }

        if (is_array($invoice)) {
            foreach (['id', 'gov_status', 'gov_id', 'gov_error_messages'] as $field) {
                if (array_key_exists($field, $body)) {
                    throw new RuntimeException('A KSeF DEMO response contained ambiguous direct and nested invoice fields.');
                }
            }

            return $invoice;
        }

        return array_is_list($body) ? [] : $body;
    }

    private static function documentId(#[SensitiveParameter] Response $response): ?string
    {
        $invoice = self::invoiceObject(self::jsonObject($response));

        return KsefDemoDocumentId::fromRemote($invoice['id'] ?? null);
    }

    /** @return array{status: string, gov_id: ?string, gov_errors_present: bool, gov_errors_expected_validation_field: bool, gov_errors_memory_digest: ?string} */
    private static function strictSnapshot(
        #[SensitiveParameter] Response $response,
        #[SensitiveParameter] string $expectedDocumentId,
    ): array {
        $body = self::jsonObject($response);
        $invoice = self::invoiceObject($body);
        $documentId = KsefDemoDocumentId::fromRemote($invoice['id'] ?? null);

        if ($documentId === null
            || ! \hash_equals($expectedDocumentId, $documentId)
            || ! array_key_exists('gov_status', $invoice)
            || self::hasProviderEnvelopeError($body, $invoice)) {
            throw new RuntimeException('A KSeF DEMO status snapshot did not identify the requested invoice and explicit gov_status.');
        }

        $status = self::normalizeKsefStatus($invoice['gov_status']);

        if ($status === 'unknown') {
            throw new RuntimeException('A KSeF DEMO status snapshot contained an unknown gov_status.');
        }

        $rawGovId = $invoice['gov_id'] ?? null;
        $govId = KsefDemoGovId::fromRemote($rawGovId);

        if ($govId === null && $rawGovId !== null && $rawGovId !== '') {
            throw new RuntimeException('A KSeF DEMO status snapshot contained a non-canonical gov_id.');
        }

        if ($status !== 'demo_ok' && $govId !== null) {
            throw new RuntimeException('A KSeF DEMO status snapshot contained gov_id before a successful terminal state.');
        }

        $govErrorEvidence = self::govErrorEvidenceInInvoice($invoice);

        return [
            'status' => $status,
            'gov_id' => $govId,
            'gov_errors_present' => $govErrorEvidence['memory_digest'] !== null,
            'gov_errors_expected_validation_field' => $govErrorEvidence['expected_validation_field'],
            'gov_errors_memory_digest' => $govErrorEvidence['memory_digest'],
        ];
    }

    private static function hasProviderError(#[SensitiveParameter] Response $response): bool
    {
        $body = self::jsonObject($response);
        $invoice = self::invoiceObject($body);

        return self::hasProviderEnvelopeError($body, $invoice)
            || self::govErrorsPresentInInvoice($invoice);
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @param  array<string, mixed>  $invoice
     */
    private static function hasProviderEnvelopeError(
        #[SensitiveParameter] array $body,
        #[SensitiveParameter] array $invoice,
    ): bool {
        foreach ([$body, $invoice] as $data) {
            if (array_key_exists('error', $data)
                || array_key_exists('errors', $data)
                || array_key_exists('message', $data)
                || array_key_exists('code', $data)
                || (array_key_exists('success', $data) && $data['success'] !== true)
                || (array_key_exists('ok', $data) && $data['ok'] !== true)
                || self::isProviderErrorStatus($data['status'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function isProviderErrorStatus(mixed $status): bool
    {
        if ($status === null) {
            return false;
        }

        if (! is_string($status)) {
            return true;
        }

        $normalized = strtolower(trim($status));

        return $normalized !== 'issued';
    }

    /** @param array<string, mixed> $invoice */
    private static function govErrorsPresentInInvoice(#[SensitiveParameter] array $invoice): bool
    {
        return self::govErrorEvidenceInInvoice($invoice)['memory_digest'] !== null;
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array{memory_digest: ?string, expected_validation_field: bool}
     */
    private static function govErrorEvidenceInInvoice(#[SensitiveParameter] array $invoice): array
    {
        if (! array_key_exists('gov_error_messages', $invoice)) {
            return ['memory_digest' => null, 'expected_validation_field' => false];
        }

        $errors = $invoice['gov_error_messages'] ?? null;

        if ($errors === null || $errors === []) {
            return ['memory_digest' => null, 'expected_validation_field' => false];
        }

        if (! is_array($errors) || ! array_is_list($errors)) {
            throw new RuntimeException('A KSeF DEMO response contained malformed gov_error_messages.');
        }

        $normalizedErrors = [];

        foreach ($errors as $error) {
            if (! is_string($error) || trim($error) === '') {
                throw new RuntimeException('A KSeF DEMO response contained malformed gov_error_messages.');
            }

            $normalizedErrors[] = trim($error);
        }

        sort($normalizedErrors, SORT_STRING);
        $encoded = \json_encode(['error_codes' => array_values(array_unique($normalizedErrors))], JSON_THROW_ON_ERROR);

        $expectedValidationField = array_all($normalizedErrors, static function (string $error): bool {
            $normalized = trim($error);
            $exactBuyerTaxCode = preg_match(
                '/\Abuyer[_ -]?tax[_ -]?no(?:[_ .:-](?:blank|invalid|missing|not[_ -]?valid|required|wrong[_ -]?format))?\z/iu',
                $normalized,
            ) === 1;

            return $exactBuyerTaxCode || $normalized === 'NIP nabywcy - nie może być puste';
        });

        return [
            'memory_digest' => \hash('sha256', $encoded),
            'expected_validation_field' => $expectedValidationField,
        ];
    }

    /** @param array<array-key, mixed> $body */
    private static function hasStrictExpectedValidationError(
        #[SensitiveParameter] array $body,
        string $expected,
    ): bool {
        $keys = array_keys($body);
        sort($keys, SORT_STRING);

        if ($keys !== ['code', 'message']
            || $body['code'] !== 'error'
            || ! is_array($body['message'])
            || array_is_list($body['message'])
            || array_keys($body['message']) !== [$expected]) {
            return false;
        }

        return $body['message'][$expected] === ['- nie może być puste'];
    }

    private static function validPdfDescriptor(mixed $descriptor): bool
    {
        return is_array($descriptor)
            && ($descriptor['mime'] ?? null) === 'application/pdf'
            && is_int($descriptor['size'] ?? null)
            && $descriptor['size'] >= KsefDemoProbeConfiguration::DefaultMinimumPdfSizeBytes
            && $descriptor['size'] <= 25 * 1024 * 1024
            && is_string($descriptor['hmac_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $descriptor['hmac_sha256']) === 1;
    }

    private static function validPdfEvidence(mixed $pdf): bool
    {
        if (! is_array($pdf)
            || ! self::validPdfDescriptor($pdf['before'] ?? null)
            || ! self::validPdfDescriptor($pdf['after'] ?? null)
            || ! is_bool($pdf['equal'] ?? null)) {
            return false;
        }

        return $pdf['equal'] === \hash_equals($pdf['before']['hmac_sha256'], $pdf['after']['hmac_sha256']);
    }

    private static function observedStatusesMatch(
        mixed $observed,
        mixed $issue,
        mixed $before,
        mixed $preSend,
        mixed $afterSend,
        mixed $terminalObservations,
    ): bool {
        if (! is_array($observed)
            || ! array_is_list($observed)
            || $observed === []
            || ($issue !== null && ! is_string($issue))
            || ! is_string($before)
            || ($preSend !== null && ! is_string($preSend))
            || ($afterSend !== null && ! is_string($afterSend))
            || ! is_int($terminalObservations)
            || $terminalObservations < 2) {
            return false;
        }

        $statusOrder = ['not_sent' => 0, 'demo_processing' => 1, 'demo_ok' => 2];
        $previousRank = -1;

        foreach ($observed as $status) {
            if (! is_string($status) || ! array_key_exists($status, $statusOrder)) {
                return false;
            }

            $rank = $statusOrder[$status];

            if ($rank < $previousRank) {
                return false;
            }

            $previousRank = $rank;
        }

        $terminalSuffix = 0;

        for ($index = count($observed) - 1; $index >= 0 && $observed[$index] === 'demo_ok'; $index--) {
            $terminalSuffix++;
        }

        $beforeIndex = $issue === null ? 0 : 1;
        $afterSendIndex = $beforeIndex + ($preSend === null ? 1 : 2);

        return ($issue === null || $observed[0] === $issue)
            && ($observed[$beforeIndex] ?? null) === $before
            && ($preSend === null || ($observed[$beforeIndex + 1] ?? null) === $preSend)
            && $observed[count($observed) - 1] === 'demo_ok'
            && $terminalSuffix >= $terminalObservations
            && ($afterSend === null || ($observed[$afterSendIndex] ?? null) === $afterSend);
    }
}

final class KsefDemoFixtureGuard
{
    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $sensitive
     */
    public static function assertSafe(
        #[SensitiveParameter] array $result,
        #[SensitiveParameter] array $sensitive,
    ): void {
        self::assertSafeEvidence($result, $sensitive, true);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $sensitive
     */
    public static function assertSafeForTesting(
        #[SensitiveParameter] array $result,
        #[SensitiveParameter] array $sensitive,
    ): void {
        self::assertSafeEvidence($result, $sensitive, false);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $sensitive
     */
    private static function assertSafeEvidence(
        #[SensitiveParameter] array $result,
        #[SensitiveParameter] array $sensitive,
        bool $requireLiveLimits,
    ): void {
        self::exactKeys($result, ['contract', 'run', 'probe_limits', 'profiles', 'capability_0_2']);

        if (($result['contract'] ?? null) !== KsefDemoProbeConfiguration::EvidenceContract
            || ! is_array($result['run'] ?? null)
            || ! is_array($result['probe_limits'] ?? null)
            || ! is_array($result['profiles'] ?? null)
            || ! is_array($result['capability_0_2'] ?? null)) {
            throw new RuntimeException('The KSeF fixture does not match the allowlisted contract.');
        }

        self::exactKeys($result['run'], ['started_at', 'finished_at', 'environment', 'launch_manifest_sha256']);
        $runStartedAt = self::strictUtcFixtureDate($result['run']['started_at'] ?? null);
        $runFinishedAt = self::strictUtcFixtureDate($result['run']['finished_at'] ?? null);
        $runDurationMicroseconds = self::instantMicroseconds($runFinishedAt) - self::instantMicroseconds($runStartedAt);

        if (($result['run']['environment'] ?? null) !== 'ksef_demo'
            || ! is_string($result['run']['launch_manifest_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $result['run']['launch_manifest_sha256']) !== 1
            || $runDurationMicroseconds <= 0
            || $runDurationMicroseconds > KsefDemoProbeConfiguration::MaximumRunDurationSeconds * 1_000_000) {
            throw new RuntimeException('The KSeF fixture run boundary is invalid.');
        }

        self::validateProbeLimits($result['probe_limits'], $requireLiveLimits);

        self::exactKeys($result['profiles'], KsefDemoProbeConfiguration::profileKeys());

        $expectedProfiles = [
            'explicit_block' => 'explicit_sdk+block_invalid',
            'explicit_persist' => 'explicit_sdk+persist_with_errors',
            'auto_block' => 'provider_auto_send+block_invalid',
            'auto_persist' => 'provider_auto_send+persist_with_errors',
        ];
        $validatedProfiles = [];
        $minimumPdfSizeBytes = $result['probe_limits']['minimum_pdf_size_bytes'] ?? null;

        if (! is_int($minimumPdfSizeBytes)) {
            throw new RuntimeException('The KSeF fixture PDF minimum is malformed.');
        }

        foreach ($result['profiles'] as $key => $profile) {
            if (! is_array($profile)) {
                throw new RuntimeException('The KSeF fixture profile must be an object.');
            }

            self::validateProfile($profile, $minimumPdfSizeBytes);

            if (($profile['profile'] ?? null) !== $expectedProfiles[$key]) {
                throw new RuntimeException('The KSeF fixture profile is assigned to the wrong matrix cell.');
            }

            $validatedProfiles[$key] = $profile;
        }

        self::exactKeys($result['capability_0_2'], [
            'matrix_complete',
            'supported_profile',
            'issue_ksef_behavior',
            'ensure_accepted',
            'provider_auto_send',
            'persist_with_errors',
            'gov_save_and_send',
            'payments',
            'webhooks',
        ]);

        $resolvedCapability = KsefDemoContractProbe::resolveCapabilityPolicy($validatedProfiles);

        if ($result['capability_0_2'] !== $resolvedCapability) {
            throw new RuntimeException('The KSeF fixture capability policy does not match its profile evidence.');
        }

        if ($resolvedCapability !== [
            'matrix_complete' => true,
            'supported_profile' => 'explicit_sdk+block_invalid',
            'issue_ksef_behavior' => 'never_send',
            'ensure_accepted' => 'separate_operation',
            'provider_auto_send' => 'observe_only',
            'persist_with_errors' => 'recognized_outside_pilot',
            'gov_save_and_send' => 'unsupported_low_level',
            'payments' => 'outside_gate',
            'webhooks' => 'outside_gate',
        ]) {
            throw new RuntimeException('The KSeF fixture capability policy is not the approved 0.2 profile.');
        }

        $json = \json_encode($result, JSON_THROW_ON_ERROR);

        foreach ($sensitive as $value) {
            if ($value !== '' && str_contains($json, $value)) {
                throw new RuntimeException('The KSeF fixture contains a sensitive configured value.');
            }
        }

        if (preg_match('/(?:https?:\/\/|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|%PDF-|api_token|buyer_tax_no|gov_error_messages|\"(?:id|oid|host|url|body|headers?|errors?|messages?)\")/i', $json)) {
            throw new RuntimeException('The KSeF fixture contains a forbidden identifier, payload, URL or response detail.');
        }
    }

    /** @param array<string, mixed> $profile */
    private static function validateProfile(#[SensitiveParameter] array $profile, int $minimumPdfSizeBytes): void
    {
        self::exactKeys($profile, ['profile', 'status_codes', 'ksef_statuses', 'send_count', 'exact_search', 'pdf']);

        if (! in_array($profile['profile'] ?? null, [
            'explicit_sdk+block_invalid',
            'explicit_sdk+persist_with_errors',
            'provider_auto_send+block_invalid',
            'provider_auto_send+persist_with_errors',
        ], true)) {
            throw new RuntimeException('The KSeF fixture contains an unknown profile.');
        }

        if (! is_array($profile['status_codes'] ?? null) || ! is_array($profile['ksef_statuses'] ?? null) || ! is_array($profile['exact_search'] ?? null) || ! is_array($profile['pdf'] ?? null)) {
            throw new RuntimeException('The KSeF fixture evidence must use allowlisted objects.');
        }

        self::exactKeys($profile['status_codes'], ['account_preflight', 'valid_issue', 'invalid_issue', 'invalid_final_read', 'preflight_read', 'pdf_before_boundary_read', 'pre_send_read', 'send', 'terminal_read', 'pdf_before', 'pdf_after', 'pdf_after_boundary_read', 'final_read']);

        foreach ($profile['status_codes'] as $status) {
            if ($status !== null && (! is_int($status) || $status < 100 || $status > 599)) {
                throw new RuntimeException('The KSeF fixture contains an invalid HTTP status code.');
            }
        }

        self::exactKeys($profile['ksef_statuses'], ['issue', 'before', 'pdf_before_boundary', 'pre_send', 'after_send', 'terminal', 'terminal_gov_id_present', 'terminal_stable', 'terminal_observations', 'pdf_after_boundary', 'pdf_after_boundary_gov_id_present', 'final', 'final_gov_id_present', 'observed']);
        $statuses = [$profile['ksef_statuses']['before'], $profile['ksef_statuses']['pdf_before_boundary'], $profile['ksef_statuses']['terminal'], $profile['ksef_statuses']['pdf_after_boundary'], $profile['ksef_statuses']['final'], ...($profile['ksef_statuses']['observed'] ?? [])];

        if ($profile['ksef_statuses']['issue'] !== null) {
            $statuses[] = $profile['ksef_statuses']['issue'];
        }

        if ($profile['ksef_statuses']['pre_send'] !== null) {
            $statuses[] = $profile['ksef_statuses']['pre_send'];
        }

        if ($profile['ksef_statuses']['after_send'] !== null) {
            $statuses[] = $profile['ksef_statuses']['after_send'];
        }

        foreach ($statuses as $status) {
            if (! is_string($status) || ! in_array($status, ['not_sent', 'demo_processing', 'demo_ok', 'demo_send_error', 'demo_server_error', 'demo_not_applicable', 'demo_not_connected', 'unknown'], true)) {
                throw new RuntimeException('The KSeF fixture contains a non-allowlisted KSeF status.');
            }
        }

        if (! is_bool($profile['ksef_statuses']['terminal_gov_id_present'] ?? null)) {
            throw new RuntimeException('The KSeF fixture terminal gov_id presence flag must be boolean.');
        }

        if (! is_bool($profile['ksef_statuses']['pdf_after_boundary_gov_id_present'] ?? null)) {
            throw new RuntimeException('The KSeF fixture post-PDF gov_id presence flag must be boolean.');
        }

        if (! is_bool($profile['ksef_statuses']['final_gov_id_present'] ?? null)) {
            throw new RuntimeException('The KSeF fixture final gov_id presence flag must be boolean.');
        }

        if (! is_bool($profile['ksef_statuses']['terminal_stable'] ?? null)
            || ! is_int($profile['ksef_statuses']['terminal_observations'] ?? null)
            || $profile['ksef_statuses']['terminal_observations'] < 0) {
            throw new RuntimeException('The KSeF fixture terminal stability evidence is malformed.');
        }

        if (! in_array($profile['send_count'] ?? null, [0, 1], true)) {
            throw new RuntimeException('The KSeF fixture send count must be zero or one.');
        }

        self::exactKeys($profile['exact_search'], [
            'valid_count',
            'invalid_count',
            'all_results_exact',
            'invalid_gov_errors_present',
            'invalid_validation_error_category',
            'invalid_ksef_status',
            'invalid_gov_id_present',
            'invalid_terminal_stable',
            'invalid_terminal_observations',
            'invalid_observations',
            'invalid_explicit_send_count',
            'invalid_outcome',
        ]);

        if (! is_int($profile['exact_search']['valid_count'] ?? null)
            || ! is_int($profile['exact_search']['invalid_count'] ?? null)
            || ! is_bool($profile['exact_search']['all_results_exact'] ?? null)
            || ! is_bool($profile['exact_search']['invalid_gov_errors_present'] ?? null)
            || ! in_array($profile['exact_search']['invalid_validation_error_category'] ?? null, [null, 'expected_validation_leaf_gov_error'], true)
            || ! in_array($profile['exact_search']['invalid_ksef_status'] ?? null, [null, 'not_sent', 'demo_processing', 'demo_send_error', 'demo_ok'], true)
            || ! is_bool($profile['exact_search']['invalid_gov_id_present'] ?? null)
            || ! is_bool($profile['exact_search']['invalid_terminal_stable'] ?? null)
            || ! is_int($profile['exact_search']['invalid_terminal_observations'] ?? null)
            || $profile['exact_search']['invalid_terminal_observations'] < 0
            || ! is_array($profile['exact_search']['invalid_observations'] ?? null)
            || ! array_is_list($profile['exact_search']['invalid_observations'])
            || ($profile['exact_search']['invalid_explicit_send_count'] ?? null) !== 0
            || ! in_array($profile['exact_search']['invalid_outcome'] ?? null, [
                'rejected_not_persisted',
                'persisted_with_errors',
                'persisted_with_errors_demo_rejected',
                'persisted_with_errors_demo_accepted',
            ], true)) {
            throw new RuntimeException('The KSeF fixture exact-search evidence is malformed.');
        }

        self::exactKeys($profile['pdf'], ['before', 'after', 'equal']);

        foreach (['before', 'after'] as $revision) {
            $descriptor = $profile['pdf'][$revision] ?? null;

            if (! is_array($descriptor)) {
                throw new RuntimeException('The KSeF fixture PDF descriptor is malformed.');
            }

            self::exactKeys($descriptor, ['mime', 'size', 'hmac_sha256']);

            if (($descriptor['mime'] ?? null) !== 'application/pdf'
                || ! is_int($descriptor['size'] ?? null)
                || $descriptor['size'] < $minimumPdfSizeBytes
                || $descriptor['size'] > 25 * 1024 * 1024
                || ! is_string($descriptor['hmac_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $descriptor['hmac_sha256']) !== 1) {
                throw new RuntimeException('The KSeF fixture PDF descriptor is not allowlisted.');
            }
        }

        if (! is_bool($profile['pdf']['equal'] ?? null)) {
            throw new RuntimeException('The KSeF fixture PDF equality result must be boolean.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $expected
     */
    private static function exactKeys(#[SensitiveParameter] array $data, array $expected): void
    {
        if (array_keys($data) !== $expected) {
            throw new RuntimeException('The KSeF fixture contains a non-allowlisted field.');
        }
    }

    /** @param array<string, mixed> $limits */
    private static function validateProbeLimits(array $limits, bool $requireLiveLimits): void
    {
        $expectedKeys = [
            'poll_window_ms',
            'poll_interval_ms',
            'max_search_pages',
            'pre_send_observation_window_ms',
            'visibility_window_ms',
            'visibility_poll_interval_ms',
            'connect_timeout_ms',
            'request_timeout_ms',
            'minimum_pdf_size_bytes',
        ];
        self::exactKeys($limits, $expectedKeys);

        foreach ($limits as $value) {
            if (! is_int($value) || $value < 1) {
                throw new RuntimeException('The KSeF fixture probe-limit evidence is malformed.');
            }
        }

        if ($requireLiveLimits) {
            KsefDemoProbeConfiguration::assertSafeLiveLimits($limits);
        }
    }

    private static function strictUtcFixtureDate(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $value) !== 1) {
            throw new RuntimeException('The KSeF fixture run timestamp is not canonical UTC.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new RuntimeException('The KSeF fixture run timestamp is invalid.');
        }

        return $date;
    }

    private static function instantMicroseconds(DateTimeImmutable $date): int
    {
        return ((int) $date->format('U') * 1_000_000) + (int) $date->format('u');
    }
}

final class KsefDemoSaloonRuntimeGuard
{
    public static function assertClean(): void
    {
        if (MockClient::getGlobal() !== null
            || Config::$defaultSender !== GuzzleSender::class
            || Config::$defaultTlsMethod !== STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            || Config::$defaultConnectionTimeout !== 10
            || Config::$defaultRequestTimeout !== 30
            || self::staticConfigValue('senderResolver') !== null
            || self::hasGlobalMiddleware()) {
            throw new RuntimeException('The global Saloon runtime is contaminated; the KSeF transport is disabled.');
        }
    }

    private static function staticConfigValue(string $property): mixed
    {
        return (new ReflectionProperty(Config::class, $property))->getValue();
    }

    private static function hasGlobalMiddleware(): bool
    {
        $middleware = self::staticConfigValue('globalMiddlewarePipeline');

        if ($middleware === null) {
            return false;
        }

        if (! $middleware instanceof MiddlewarePipeline) {
            return true;
        }

        return $middleware->getRequestPipeline()->getPipes() !== []
            || $middleware->getResponsePipeline()->getPipes() !== []
            || $middleware->getFatalPipeline()->getPipes() !== [];
    }
}

enum KsefDemoLiteralFailureKind: string
{
    case Logic = 'logic';
    case Transport = 'transport';
}

final readonly class KsefDemoLiteralFailure
{
    private function __construct(
        public KsefDemoLiteralFailureKind $kind,
        #[SensitiveParameter] public string $message,
        public int $errno = 0,
        public int $requestSize = 0,
    ) {}

    public static function logic(#[SensitiveParameter] string $message): self
    {
        return new self(KsefDemoLiteralFailureKind::Logic, $message);
    }

    public static function transport(
        #[SensitiveParameter] string $message,
        int $errno,
        int $requestSize,
    ): self {
        if ($errno < 0 || $requestSize < 0) {
            throw new InvalidArgumentException('A literal transport failure requires bounded non-negative transfer evidence.');
        }

        return new self(KsefDemoLiteralFailureKind::Transport, $message, $errno, $requestSize);
    }

    public function throwable(#[SensitiveParameter] PendingRequest $pendingRequest): Throwable
    {
        if ($this->kind === KsefDemoLiteralFailureKind::Logic) {
            return new LogicException($this->message);
        }

        return new FatalRequestException(
            new GuzzleRequestException(
                $this->message,
                new GuzzlePsrRequest('GET', 'https://sealed-literal-transport.invalid/ksef'),
                null,
                null,
                ['errno' => $this->errno, 'request_size' => $this->requestSize],
            ),
            $pendingRequest,
        );
    }
}

final readonly class KsefDemoLiteralResponse
{
    /**
     * @param  array<string|int, mixed>|string  $body
     * @param  array<string, mixed>  $headers
     */
    private function __construct(
        #[SensitiveParameter] public array|string $body,
        public int $status,
        #[SensitiveParameter] public array $headers,
        #[SensitiveParameter] public ?KsefDemoLiteralFailure $failure = null,
        public int $delayMicroseconds = 0,
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('A literal response status must be an HTTP status code.');
        }

        self::assertLiteral($body);

        if ($delayMicroseconds < 0 || $delayMicroseconds > 1_000_000) {
            throw new InvalidArgumentException('A literal response delay exceeds the bounded in-memory limit.');
        }

        foreach ($headers as $name => $value) {
            if ($name === '' || (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value))) {
                throw new InvalidArgumentException('A literal response header is malformed.');
            }
        }
    }

    /**
     * @param  array<string|int, mixed>|string  $body
     * @param  array<string, mixed>  $headers
     */
    public static function make(
        #[SensitiveParameter] array|string $body = [],
        int $status = 200,
        #[SensitiveParameter] array $headers = [],
    ): self {
        return new self($body, $status, $headers);
    }

    public static function fail(#[SensitiveParameter] KsefDemoLiteralFailure $failure): self
    {
        return new self([], 200, [], $failure);
    }

    /**
     * @param  array<string|int, mixed>|string  $body
     * @param  array<string, mixed>  $headers
     */
    public static function delayed(
        #[SensitiveParameter] array|string $body,
        int $delayMicroseconds,
        int $status = 200,
        #[SensitiveParameter] array $headers = [],
    ): self {
        return new self($body, $status, $headers, delayMicroseconds: $delayMicroseconds);
    }

    public function materialize(
        #[SensitiveParameter] PendingRequest $pendingRequest,
        FactoryCollection $factories,
    ): Response {
        if ($this->delayMicroseconds > 0) {
            \usleep($this->delayMicroseconds);
        }

        if ($this->failure instanceof KsefDemoLiteralFailure) {
            throw $this->failure->throwable($pendingRequest);
        }

        $psrResponse = $factories->responseFactory->createResponse($this->status);

        foreach ($this->headers as $name => $value) {
            $psrResponse = $psrResponse->withHeader($name, (string) $value);
        }

        $body = is_array($this->body)
            ? \json_encode($this->body, JSON_THROW_ON_ERROR)
            : $this->body;
        $psrResponse = $psrResponse->withBody($factories->streamFactory->createStream($body));
        $responseClass = $pendingRequest->getResponseClass();

        return $responseClass::fromPsrResponse(
            $psrResponse,
            $pendingRequest,
            $pendingRequest->createPsrRequest(),
        );
    }

    private static function assertLiteral(#[SensitiveParameter] mixed $value, int $depth = 0, int &$nodes = 0): void
    {
        $nodes++;

        if ($depth > 16 || $nodes > 10_000) {
            throw new InvalidArgumentException('A literal response exceeds the bounded in-memory shape.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return;
        }

        if (is_string($value)) {
            if (strlen($value) > 25 * 1024 * 1024) {
                throw new InvalidArgumentException('A literal response string exceeds the in-memory limit.');
            }

            return;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('A literal response may contain only scalar JSON values and arrays.');
        }

        foreach ($value as $item) {
            self::assertLiteral($item, $depth + 1, $nodes);
        }
    }
}

final readonly class KsefDemoLiteralResponseSequence
{
    /** @var class-string<Request> */
    public string $requestClass;

    /** @var non-empty-list<KsefDemoLiteralResponse> */
    public array $responses;

    /** @var array<string, mixed> */
    public array $query;

    /** @var array<string, mixed> */
    public array $body;

    /**
     * @param  array<int, mixed>  $responses
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $requestClass,
        #[SensitiveParameter] array $responses,
        public ?string $host = null,
        public ?string $endpoint = null,
        #[SensitiveParameter] array $query = [],
        #[SensitiveParameter] array $body = [],
        public bool $repeatLast = false,
        public ?string $afterRequestClass = null,
        public int $minimumRequestCount = 0,
    ) {
        if (! \is_a($requestClass, Request::class, true) || $responses === [] || ! array_is_list($responses)) {
            throw new InvalidArgumentException('A literal response sequence requires one request class and a non-empty response list.');
        }

        if (($host !== null && preg_match('/\A[a-z0-9.-]+\z/D', $host) !== 1)
            || ($endpoint !== null && (! str_starts_with($endpoint, '/') || str_contains($endpoint, '?')))
            || array_key_exists('api_token', $query)
            || array_key_exists('integration_token', $query)
            || ($afterRequestClass !== null && ! \is_a($afterRequestClass, Request::class, true))
            || $minimumRequestCount < 0
            || ($afterRequestClass === null && $minimumRequestCount !== 0)) {
            throw new InvalidArgumentException('A literal response route contains an unsafe matcher.');
        }

        foreach ($responses as $response) {
            if (! $response instanceof KsefDemoLiteralResponse) {
                throw new InvalidArgumentException('A literal response sequence cannot contain fixtures or callables.');
            }
        }

        $this->requestClass = $requestClass;
        $this->responses = $responses;
        $this->query = $query;
        $this->body = $body;
    }
}

final class KsefDemoInMemoryTransport implements Sender
{
    public const DeterministicScenarioNonce = '000000000000';

    /**
     * @var list<array{
     *     sequence: KsefDemoLiteralResponseSequence,
     *     responses: list<KsefDemoLiteralResponse>,
     *     last: ?KsefDemoLiteralResponse
     * }>
     */
    private array $routes = [];

    /** @var array<class-string<Request>, int> */
    private array $requestCounts = [];

    private FactoryCollection $factories;

    public function __construct(#[SensitiveParameter] KsefDemoLiteralResponseSequence ...$sequences)
    {
        $httpFactory = new HttpFactory;
        $this->factories = new FactoryCollection(
            requestFactory: $httpFactory,
            uriFactory: $httpFactory,
            streamFactory: $httpFactory,
            responseFactory: $httpFactory,
            multipartBodyFactory: new GuzzleMultipartBodyFactory,
        );

        foreach ($sequences as $sequence) {
            $this->routes[] = [
                'sequence' => $sequence,
                'responses' => $sequence->responses,
                'last' => null,
            ];
        }
    }

    public function send(#[SensitiveParameter] PendingRequest $pendingRequest): Response
    {
        $requestClass = $pendingRequest->getRequest()::class;
        $response = null;

        foreach ($this->routes as &$route) {
            if (! $this->matches($route['sequence'], $pendingRequest)) {
                continue;
            }

            $candidate = \array_shift($route['responses']);

            if ($candidate instanceof KsefDemoLiteralResponse) {
                $route['last'] = $candidate;
                $response = $candidate;

                break;
            }

            if ($route['sequence']->repeatLast && $route['last'] instanceof KsefDemoLiteralResponse) {
                $response = $route['last'];

                break;
            }
        }
        unset($route);

        if (! $response instanceof KsefDemoLiteralResponse) {
            throw new RuntimeException('The sealed in-memory transport has no matching literal response for this request.');
        }

        $this->requestCounts[$requestClass] = ($this->requestCounts[$requestClass] ?? 0) + 1;

        return $response->materialize($pendingRequest, $this->factories);
    }

    public function requestCount(string $requestClass): int
    {
        return $this->requestCounts[$requestClass] ?? 0;
    }

    public function isEmpty(): bool
    {
        foreach ($this->routes as $route) {
            if ($route['responses'] !== []) {
                return false;
            }
        }

        return true;
    }

    public function getFactoryCollection(): FactoryCollection
    {
        return $this->factories;
    }

    public function sendAsync(PendingRequest $pendingRequest): PromiseInterface
    {
        throw new LogicException('The sealed in-memory transport does not permit asynchronous execution.');
    }

    private function matches(
        #[SensitiveParameter] KsefDemoLiteralResponseSequence $sequence,
        #[SensitiveParameter] PendingRequest $pendingRequest,
    ): bool {
        if (! $pendingRequest->getRequest() instanceof $sequence->requestClass) {
            return false;
        }

        if ($sequence->afterRequestClass !== null
            && ($this->requestCounts[$sequence->afterRequestClass] ?? 0) < $sequence->minimumRequestCount) {
            return false;
        }

        if ($sequence->host !== null && \parse_url($pendingRequest->getUrl(), PHP_URL_HOST) !== $sequence->host) {
            return false;
        }

        if ($sequence->endpoint !== null && $pendingRequest->getRequest()->resolveEndpoint() !== $sequence->endpoint) {
            return false;
        }

        if (! self::containsLiteralSubset($pendingRequest->query()->all(), $sequence->query)) {
            return false;
        }

        $body = $pendingRequest->body()?->all() ?? [];

        return self::containsLiteralSubset(is_array($body) ? $body : [], $sequence->body);
    }

    /**
     * @param  array<string|int, mixed>  $actual
     * @param  array<string|int, mixed>  $expected
     */
    private static function containsLiteralSubset(
        #[SensitiveParameter] array $actual,
        #[SensitiveParameter] array $expected,
    ): bool {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }

            if (is_array($value)) {
                if (! is_array($actual[$key]) || ! self::containsLiteralSubset($actual[$key], $value)) {
                    return false;
                }

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                return false;
            }

            if ($actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}

final class KsefDemoConnector extends Connector
{
    use AcceptsJson;
    use HasTimeout;

    protected float $connectTimeout;

    protected float $requestTimeout;

    protected string $defaultSender = GuzzleSender::class;

    public function __construct(
        #[SensitiveParameter] private string $baseUrl,
        int $connectTimeoutMs = KsefDemoProbeConfiguration::DefaultConnectTimeoutMs,
        int $requestTimeoutMs = KsefDemoProbeConfiguration::DefaultRequestTimeoutMs,
        ?KsefDemoInMemoryTransport $inMemoryTransport = null,
    ) {
        if ($connectTimeoutMs < 1 || $requestTimeoutMs < 1) {
            throw new RuntimeException('KSeF DEMO connector timeouts must be positive.');
        }

        $this->connectTimeout = $connectTimeoutMs / 1_000;
        $this->requestTimeout = $requestTimeoutMs / 1_000;

        if ($inMemoryTransport instanceof KsefDemoInMemoryTransport) {
            $this->sender = $inMemoryTransport;
        }
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function defaultConfig(): array
    {
        return [
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
            RequestOptions::TIMEOUT => $this->requestTimeout,
            RequestOptions::VERIFY => true,
            RequestOptions::PROXY => '',
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::STREAM => false,
            RequestOptions::CRYPTO_METHOD => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ];
    }
}

final class AccountKsefDemoRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(#[SensitiveParameter] private string $token) {}

    public function resolveEndpoint(): string
    {
        return '/account.json';
    }

    protected function defaultQuery(): array
    {
        return ['api_token' => $this->token, 'integration_token' => ''];
    }
}

final class KsefDemoAccountId
{
    public static function fromRemote(#[SensitiveParameter] mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }

        if (! is_string($value)
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1) {
            return null;
        }

        return $value;
    }
}

final class KsefDemoDocumentId
{
    public static function fromRemote(#[SensitiveParameter] mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1) {
            return null;
        }

        return $value;
    }

    public static function require(#[SensitiveParameter] string $value): string
    {
        if (self::fromRemote($value) !== $value) {
            throw new RuntimeException('A remote KSeF DEMO document ID must be a canonical positive decimal string.');
        }

        return $value;
    }
}

final class KsefDemoGovId
{
    public static function fromRemote(#[SensitiveParameter] mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,255}\z/D', $value) !== 1) {
            return null;
        }

        return $value;
    }
}

final class CreateKsefDemoInvoiceRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /** @param array<string, mixed> $invoice */
    public function __construct(
        #[SensitiveParameter] private string $token,
        #[SensitiveParameter] private array $invoice,
    ) {
        foreach (['gov_save_and_send', 'send_to_ksef'] as $forbidden) {
            if (array_key_exists($forbidden, $invoice)) {
                throw new RuntimeException("Issue cannot contain {$forbidden}; ensure_accepted is a separate operation.");
            }
        }
    }

    public function resolveEndpoint(): string
    {
        return '/invoices.json';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return ['api_token' => $this->token, 'invoice' => $this->invoice];
    }
}

final class SearchKsefDemoInvoicesRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        #[SensitiveParameter] private string $token,
        #[SensitiveParameter] private string $oid,
        private int $page,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/invoices.json';
    }

    protected function defaultQuery(): array
    {
        return [
            'api_token' => $this->token,
            'oid' => $this->oid,
            'period' => 'all',
            'page' => $this->page,
            'per_page' => 100,
        ];
    }
}

final class ReadKsefDemoInvoiceRequest extends Request
{
    protected Method $method = Method::GET;

    private string $documentId;

    public function __construct(
        #[SensitiveParameter] private string $token,
        #[SensitiveParameter] string $documentId,
    ) {
        $this->documentId = KsefDemoDocumentId::require($documentId);
    }

    public function resolveEndpoint(): string
    {
        return "/invoices/{$this->documentId}.json";
    }

    protected function defaultQuery(): array
    {
        return [
            'api_token' => $this->token,
            'fields[invoice]' => 'id,gov_status,gov_id,gov_error_messages',
        ];
    }
}

final class SendKsefDemoInvoiceRequest extends Request
{
    protected Method $method = Method::GET;

    private string $documentId;

    public function __construct(
        #[SensitiveParameter] private string $token,
        #[SensitiveParameter] string $documentId,
    ) {
        $this->documentId = KsefDemoDocumentId::require($documentId);
    }

    public function resolveEndpoint(): string
    {
        return "/invoices/{$this->documentId}.json";
    }

    protected function defaultQuery(): array
    {
        return [
            'api_token' => $this->token,
            'send_to_ksef' => 'yes',
            'fields[invoice]' => 'id,gov_status,gov_id,gov_error_messages',
        ];
    }
}

final class DownloadKsefDemoPdfRequest extends Request
{
    protected Method $method = Method::GET;

    private string $documentId;

    public function __construct(
        #[SensitiveParameter] private string $token,
        #[SensitiveParameter] string $documentId,
    ) {
        $this->documentId = KsefDemoDocumentId::require($documentId);
    }

    public function resolveEndpoint(): string
    {
        return "/invoices/{$this->documentId}.pdf";
    }

    protected function defaultQuery(): array
    {
        return ['api_token' => $this->token];
    }

    protected function defaultHeaders(): array
    {
        return ['Accept' => 'application/pdf'];
    }
}
