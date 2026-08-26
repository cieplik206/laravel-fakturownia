<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Contract\Support;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionReceipt;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;
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

use function array_diff;
use function array_diff_key;
use function array_filter;
use function array_intersect;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_shift;
use function array_unique;
use function array_values;
use function base64_decode;
use function base64_encode;
use function basename;
use function ceil;
use function chmod;
use function count;
use function defined;
use function dirname;
use function explode;
use function fclose;
use function fflush;
use function file_exists;
use function file_get_contents;
use function fopen;
use function function_exists;
use function fwrite;
use function get_debug_type;
use function glob;
use function hash;
use function hash_equals;
use function hash_hmac;
use function hrtime;
use function implode;
use function in_array;
use function is_a;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_finite;
use function is_float;
use function is_int;
use function is_link;
use function is_numeric;
use function is_resource;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function link;
use function ltrim;
use function max;
use function mb_strtolower;
use function mb_substr;
use function min;
use function mkdir;
use function preg_match;
use function preg_replace;
use function random_bytes;
use function rtrim;
use function sodium_memzero;
use function sort;
use function str_contains;
use function str_replace;
use function strcmp;
use function strlen;
use function strtolower;
use function substr;
use function tempnam;
use function trim;
use function unlink;
use function usleep;

final class InvoiceIdentityProbe implements LiveProviderTransportOrigin
{
    /** @var list<string> */
    private const RequiredScenarios = [
        'concurrent_same_oid',
        'same_oid_different_payload',
        'lost_response_after_remote_ack',
        'document_kind_scope',
        'department_scope',
        'account_scope',
    ];

    private ProbeFixtureSanitizer $sanitizer;

    private string $payloadComparisonKey;

    public function __construct(#[SensitiveParameter] private ProbeConfiguration $configuration)
    {
        ProbeConfiguration::assertProviderRuntimeIsolated();

        $this->payloadComparisonKey = random_bytes(32);
        $sensitive = [
            $configuration->primary->token,
            $configuration->primary->host,
            $configuration->secondary->token,
            $configuration->secondary->host,
            ...$configuration->sensitivePayloadValues(),
        ];
        $this->sanitizer = new ProbeFixtureSanitizer(array_values(array_unique($sensitive)));
    }

    public function __destruct()
    {
        $this->destroyEphemeralKeys();
    }

    public function assertRealProviderTransportOrigin(): void
    {
        $this->configuration->assertRealProviderTransportOrigin();
    }

    /**
     * @return array{
     *     path: string,
     *     result: array<string, mixed>,
     *     unsigned_attestation_path: string,
     *     unsigned_attestation_payload: array<string, mixed>
     * }
     */
    public function run(): array
    {
        ProbeConfiguration::assertProviderRuntimeIsolated();
        $this->configuration->assertProviderRunAvailable();

        $authorizationCheckedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $operatorAuthorization = $this->configuration->assertTrustedOperatorAuthorization($authorizationCheckedAt);
            $launchManifestSha256 = $this->configuration->authorizationLaunchManifestSha256();
            $accountIdentities = [$this->preflight($this->configuration->primary), $this->preflight($this->configuration->secondary)];

            if (hash_equals($accountIdentities[0], $accountIdentities[1])) {
                throw new RuntimeException('The two DEMO endpoints resolve to the same account in the same environment.');
            }

            $claimStartedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $this->configuration->claimOperatorAuthorization($claimStartedAt);
            $authorizationConsumption = $this->configuration->authorizationConsumptionReceipt();
            $runId = $this->configuration->consumptionClaimRequest()->runId;
            $prefix = "s03-{$runId}";
            $providerRunHandle = $this->configuration->usesOfflineTestAuthoritySeam()
                ? null
                : LiveEvidenceAttestationGuard::beginLiveProviderRun(
                    $this,
                    ProbeConfiguration::EvidenceContract,
                    $this->configuration->primary->environment,
                );
            $providerRun = null;

            try {
                $concurrent = $this->concurrent("{$prefix}-concurrent");
                $scenarios = [
                    'concurrent_same_oid' => $this->withoutOid($concurrent),
                    'same_oid_different_payload' => $this->differentPayload($concurrent['oid'], $concurrent['expected_document_ids']),
                    'lost_response_after_remote_ack' => $this->lostResponse("{$prefix}-lost"),
                    'document_kind_scope' => $this->kindScope("{$prefix}-kind"),
                    'department_scope' => $this->departmentScope("{$prefix}-department"),
                    'account_scope' => $this->accountScope("{$prefix}-account"),
                ];
                $this->configuration->assertExactWriteBudgetConsumed();
            } finally {
                if ($providerRunHandle !== null) {
                    $providerRun = LiveEvidenceAttestationGuard::finishLiveProviderRun($providerRunHandle);
                }
            }

            if ($providerRun === null) {
                $runStartedAt = $claimStartedAt;
                $runFinishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            } else {
                $runWindow = LiveEvidenceAttestationGuard::liveProviderRunWindow($providerRun);
                $runStartedAt = new DateTimeImmutable($runWindow['started_at'], new DateTimeZone('UTC'));
                $runFinishedAt = new DateTimeImmutable($runWindow['finished_at'], new DateTimeZone('UTC'));
            }
            $generatedAt = $runFinishedAt->format('Y-m-d\TH:i:s.uP');
            $evidence = self::resolveVatPilotPolicy($scenarios, $this->configuration->primary->environment);
            $policy = $this->resolveEnvironmentMatrix($evidence, $generatedAt, $runId);
            $result = [
                'probe' => ProbeConfiguration::EvidenceContract,
                'run_id' => $runId,
                'run_started_at' => $runStartedAt->format('Y-m-d\TH:i:s.uP'),
                'run_finished_at' => $generatedAt,
                'generated_at' => $generatedAt,
                'environment' => $this->configuration->primary->environment,
                'launch_manifest_sha256' => $launchManifestSha256,
                'probe_limits' => $this->configuration->probeLimits(),
                'operator_authorization' => $operatorAuthorization,
                'authorization_consumption' => $authorizationConsumption,
                'write_attempts' => $this->configuration->consumedWriteAttempts(),
                'tenant_preflights' => [
                    'verified' => true,
                    'distinct' => true,
                    'identity_basis' => 'environment_account_id',
                ],
                'scenarios' => $scenarios,
                'vat_fixture_evidence' => $evidence,
                'vat_pilot_policy' => $policy,
            ];
            $result['evidence_commitments'] = LiveEvidenceAttestationGuard::evidenceCommitments(
                [$operatorAuthorization],
                $result,
                ProbeConfiguration::EvidenceContract,
            );
            $written = $this->writeFixture($result, $runId, $runStartedAt, $runFinishedAt, $providerRun);

            return [
                'path' => $written['path'],
                'result' => $result,
                'unsigned_attestation_path' => $written['unsigned_attestation_path'],
                'unsigned_attestation_payload' => $written['unsigned_attestation_payload'],
            ];
        } finally {
            $this->configuration->destroyBindingKey();
            $this->destroyEphemeralKeys();
        }
    }

    private function destroyEphemeralKeys(): void
    {
        $payloadComparisonKey = $this->payloadComparisonKey;
        $this->payloadComparisonKey = '';

        if ($payloadComparisonKey !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($payloadComparisonKey);
        }
        $this->sanitizer->destroyKeys();
    }

    /**
     * @param  array<string, array<string, mixed>>  $scenarios
     * @return array<string, mixed>
     */
    public static function resolveVatPilotPolicy(#[SensitiveParameter] array $scenarios, string $environment): array
    {
        $incomplete = array_filter(self::RequiredScenarios, fn (string $key): bool => ($scenarios[$key]['complete'] ?? false) !== true);
        $unsafe = array_filter(self::RequiredScenarios, fn (string $key): bool => ($scenarios[$key]['safe'] ?? false) !== true);
        $complete = $incomplete === [];
        $safe = $complete && $unsafe === [];

        return [
            'complete' => $complete,
            'safe' => $safe,
            'remote_identity_policy_candidate' => $safe ? 'business_oid' : 'no_remote_uniqueness',
            'failure_disposition' => $complete ? 'resolved' : 'inconclusive_manual_review',
            'blind_retry_after_lost_response' => false,
            'scope' => [
                'account' => $scenarios['account_scope']['scope'] ?? 'inconclusive',
                'department' => $scenarios['department_scope']['scope'] ?? 'inconclusive',
                'document_kind' => $scenarios['document_kind_scope']['scope'] ?? 'inconclusive',
                'environment_fixture' => $environment,
            ],
            'required_environment_fixtures' => ['demo_pl', 'demo_regional'],
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function resolveEnvironmentMatrix(#[SensitiveParameter] array $current, string $generatedAt, string $runId): array
    {
        $fixturePackages = [];
        $repositoryRoot = dirname(__DIR__, 3);

        foreach (glob(dirname(__DIR__, 2).'/Fixtures/Contract/invoice-identity-*.json') ?: [] as $path) {
            $basename = basename($path);
            if (preg_match('/^invoice-identity-(?:demo_pl|demo_regional)-[a-f0-9]{32}\.json$/', $basename) !== 1) {
                continue;
            }

            $attestationPath = substr($path, 0, -5).'.attestation.json';
            if (is_link($path) || is_link($attestationPath) || ! is_file($attestationPath)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true);
            $signedEvidence = json_decode((string) file_get_contents($attestationPath), true);
            if (is_array($decoded) && is_array($signedEvidence)) {
                $fixturePackages[] = [
                    'fixture_path' => 'tests/Fixtures/Contract/'.$basename,
                    'fixture' => $decoded,
                    'signed_evidence' => $signedEvidence,
                ];
            }
        }

        $evidence = self::latestEnvironmentEvidence($fixturePackages, repositoryRoot: $repositoryRoot);
        self::recordNewerEnvironmentEvidence(
            $evidence,
            $this->configuration->primary->environment,
            $generatedAt,
            $runId,
            $current,
        );

        return self::aggregateEnvironmentEvidence(array_map(
            static fn (array $candidate): array => $candidate['evidence'],
            $evidence,
        ));
    }

    /**
     * @param  list<array{fixture_path?: mixed, fixture?: mixed, signed_evidence?: mixed}>  $fixturePackages
     * @param  array<string, string>|null  $trustedSigners
     * @param  array<string, string>|null  $trustedConsumptionAuthorities
     * @return array<string, mixed>
     */
    public static function aggregateEnvironmentFixtures(
        #[SensitiveParameter] array $fixturePackages,
        #[SensitiveParameter] ?array $trustedSigners = null,
        #[SensitiveParameter] ?array $trustedConsumptionAuthorities = null,
        ?string $repositoryRoot = null,
    ): array {
        $evidence = self::latestEnvironmentEvidence(
            $fixturePackages,
            $trustedSigners,
            $trustedConsumptionAuthorities,
            $repositoryRoot ?? dirname(__DIR__, 3),
        );

        return self::aggregateEnvironmentEvidence(array_map(
            static fn (array $candidate): array => $candidate['evidence'],
            $evidence,
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $evidence
     * @return array<string, mixed>
     */
    public static function aggregateEnvironmentEvidence(#[SensitiveParameter] array $evidence): array
    {
        $required = ['demo_pl', 'demo_regional'];
        $missing = array_filter($required, static function (string $environment) use ($evidence): bool {
            $candidate = $evidence[$environment] ?? null;

            if (! is_array($candidate)) {
                return true;
            }

            $scope = $candidate['scope'] ?? null;

            return ($candidate['complete'] ?? false) !== true
                || ($candidate['safe'] ?? false) !== true
                || ! is_array($scope)
                || ($scope['environment_fixture'] ?? null) !== $environment;
        });
        $complete = $missing === [];

        return [
            'complete' => $complete,
            'safe' => $complete,
            'remote_identity_policy' => $complete ? 'business_oid' : 'no_remote_uniqueness',
            'failure_disposition' => $complete ? 'resolved' : 'waiting_for_complete_environment_matrix',
            'blind_retry_after_lost_response' => false,
            'required_environment_fixtures' => $required,
            'missing_or_unsafe_environments' => array_values($missing),
        ];
    }

    /** @param array<string, mixed> $fixture */
    public static function fixtureEvidenceIsValid(#[SensitiveParameter] array $fixture): bool
    {
        $environment = $fixture['environment'] ?? null;
        $evidence = $fixture['vat_fixture_evidence'] ?? null;
        $scenarios = $fixture['scenarios'] ?? null;
        $pilotPolicy = $fixture['vat_pilot_policy'] ?? null;
        $tenantPreflights = $fixture['tenant_preflights'] ?? null;
        $probeLimits = $fixture['probe_limits'] ?? null;
        $operatorAuthorization = $fixture['operator_authorization'] ?? null;
        $authorizationConsumption = $fixture['authorization_consumption'] ?? null;
        $evidenceCommitments = $fixture['evidence_commitments'] ?? null;
        $launchManifestSha256 = $fixture['launch_manifest_sha256'] ?? null;
        $runStartedAt = self::strictFixtureDate($fixture['run_started_at'] ?? null);
        $runFinishedAt = self::strictFixtureDate($fixture['run_finished_at'] ?? null);

        if (! self::hasExactKeys($fixture, [
            'probe',
            'run_id',
            'run_started_at',
            'run_finished_at',
            'generated_at',
            'environment',
            'launch_manifest_sha256',
            'probe_limits',
            'operator_authorization',
            'authorization_consumption',
            'write_attempts',
            'evidence_commitments',
            'tenant_preflights',
            'scenarios',
            'vat_fixture_evidence',
            'vat_pilot_policy',
        ])
            || ! is_array($evidence)
            || ! is_array($scenarios)
            || ! is_array($pilotPolicy)
            || ! is_array($tenantPreflights)
            || ! is_array($probeLimits)
            || ! is_array($operatorAuthorization)
            || ! is_array($authorizationConsumption)
            || ! is_array($evidenceCommitments)
            || ! is_string($launchManifestSha256)
            || preg_match('/^[a-f0-9]{64}$/', $launchManifestSha256) !== 1
            || $runStartedAt === null
            || $runFinishedAt === null) {
            return false;
        }

        try {
            $expectedEvidenceCommitments = LiveEvidenceAttestationGuard::evidenceCommitments(
                [$operatorAuthorization],
                $fixture,
                ProbeConfiguration::EvidenceContract,
            );
        } catch (Throwable) {
            return false;
        }

        $runDurationMicroseconds = self::instantMicroseconds($runFinishedAt) - self::instantMicroseconds($runStartedAt);
        if (($fixture['probe'] ?? null) !== ProbeConfiguration::EvidenceContract
            || ! in_array($environment, ['demo_pl', 'demo_regional'], true)
            || ! is_string($fixture['run_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/', $fixture['run_id']) !== 1
            || ! self::isCanonicalTimestamp($fixture['generated_at'] ?? null)
            || $fixture['run_finished_at'] !== $fixture['generated_at']
            || $runDurationMicroseconds < 1_000_000
            || $runDurationMicroseconds > ProbeConfiguration::MaximumRunDurationSeconds * 1_000_000
            || ! self::probeLimitsAreValid($probeLimits)
            || ($fixture['write_attempts'] ?? null) !== ProbeConfiguration::ExactWriteAttemptBudget
            || ! self::commitmentsAreValid($evidenceCommitments)
            || ! self::operatorAuthorizationIsStructurallyValid(
                $operatorAuthorization,
                $environment,
                $probeLimits,
                $fixture['run_id'],
                $launchManifestSha256,
                $runStartedAt,
                $runFinishedAt,
            )
            || ! self::authorizationConsumptionIsStructurallyValid(
                $authorizationConsumption,
                $operatorAuthorization,
                $fixture['run_id'],
                $runStartedAt,
                $runFinishedAt,
            )
            || $evidenceCommitments !== $expectedEvidenceCommitments
            || ! self::hasExactKeys($tenantPreflights, ['verified', 'distinct', 'identity_basis'])
            || ($tenantPreflights['verified'] ?? null) !== true
            || ($tenantPreflights['distinct'] ?? null) !== true
            || ($tenantPreflights['identity_basis'] ?? null) !== 'environment_account_id'
            || ! self::hasExactKeys($scenarios, self::RequiredScenarios)) {
            return false;
        }

        foreach (self::RequiredScenarios as $scenario) {
            if (! self::scenarioEvidenceIsStructurallyValid(
                $scenario,
                $scenarios[$scenario] ?? null,
                $probeLimits['visibility_window_ms'],
            )) {
                return false;
            }
        }

        return self::resolvedEvidenceMatchesScenarios($scenarios, $environment, $evidence)
            && self::matrixPolicyIsStructurallyValid($pilotPolicy)
            && self::matrixPolicyMatchesLocalEvidence($pilotPolicy, $environment, $evidence);
    }

    /** @param array<string, mixed> $fixture */
    public static function fixtureEvidenceIsSafe(#[SensitiveParameter] array $fixture): bool
    {
        return self::fixtureEvidenceIsValid($fixture)
            && ($fixture['vat_fixture_evidence']['safe'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>  $signedEvidence
     * @param  array<string, string>|null  $trustedSigners
     * @param  array<string, string>|null  $trustedConsumptionAuthorities
     */
    public static function fixtureEvidenceHasTrustedProvenance(
        #[SensitiveParameter] array $fixture,
        #[SensitiveParameter] string $fixturePath,
        #[SensitiveParameter] array $signedEvidence,
        #[SensitiveParameter] ?array $trustedSigners = null,
        #[SensitiveParameter] ?array $trustedConsumptionAuthorities = null,
        ?string $repositoryRoot = null,
    ): bool {
        if (! self::fixtureEvidenceIsValid($fixture)) {
            return false;
        }

        $runStartedAt = self::strictFixtureDate($fixture['run_started_at'] ?? null);
        $runFinishedAt = self::strictFixtureDate($fixture['run_finished_at'] ?? null);
        $operatorAuthorization = $fixture['operator_authorization'] ?? null;
        $commitments = $fixture['evidence_commitments'] ?? null;
        if ($runStartedAt === null
            || $runFinishedAt === null
            || ! is_array($operatorAuthorization)
            || ! is_array($commitments)) {
            return false;
        }

        try {
            $envelope = $trustedSigners === null
                ? LiveEvidenceAttestationGuard::assertHistoricalEvidence(
                    $signedEvidence,
                    [$operatorAuthorization],
                    $fixture,
                    $repositoryRoot ?? dirname(__DIR__, 3),
                    $runStartedAt,
                    $runFinishedAt,
                    ProbeConfiguration::MaximumRunDurationSeconds,
                    ProbeConfiguration::MaximumAttestationTtlSeconds,
                    ProbeConfiguration::MaximumEvidenceAttestationTtlSeconds,
                    ProbeConfiguration::MaximumEvidenceSigningDelaySeconds,
                )
                : LiveEvidenceAttestationGuard::assertHistoricalTestEvidenceSignatures(
                    $signedEvidence,
                    [$operatorAuthorization],
                    $fixture,
                    $runStartedAt,
                    $runFinishedAt,
                    ProbeConfiguration::MaximumRunDurationSeconds,
                    ProbeConfiguration::MaximumAttestationTtlSeconds,
                    ProbeConfiguration::MaximumEvidenceAttestationTtlSeconds,
                    ProbeConfiguration::MaximumEvidenceSigningDelaySeconds,
                    $trustedSigners,
                    $trustedConsumptionAuthorities ?? [],
                );
            $fixtureJson = json_encode(
                $fixture,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL;

            return ($envelope['evidence']['contract'] ?? null) === ProbeConfiguration::EvidenceContract
                && ($envelope['evidence']['fixture_path'] ?? null) === $fixturePath
                && is_string($envelope['evidence']['fixture_sha256'] ?? null)
                && hash_equals($envelope['evidence']['fixture_sha256'], hash('sha256', $fixtureJson))
                && ($envelope['run']['environment'] ?? null) === $fixture['environment']
                && ($envelope['run']['launch_manifest_sha256'] ?? null) === $fixture['launch_manifest_sha256']
                && is_array($envelope['consumption'] ?? null)
                && hash_equals(
                    LiveEvidenceAttestationGuard::canonicalJson($fixture['authorization_consumption']),
                    LiveEvidenceAttestationGuard::canonicalJson($envelope['consumption']),
                )
                && is_array($envelope['commitments'] ?? null)
                && hash_equals(
                    LiveEvidenceAttestationGuard::canonicalJson($commitments),
                    LiveEvidenceAttestationGuard::canonicalJson($envelope['commitments']),
                );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fixturePackages
     * @param  array<string, string>|null  $trustedSigners
     * @param  array<string, string>|null  $trustedConsumptionAuthorities
     * @return array<string, array{recorded_at: string, run_id: string, evidence: array<string, mixed>}>
     */
    private static function latestEnvironmentEvidence(
        #[SensitiveParameter] array $fixturePackages,
        #[SensitiveParameter] ?array $trustedSigners = null,
        #[SensitiveParameter] ?array $trustedConsumptionAuthorities = null,
        ?string $repositoryRoot = null,
    ): array {
        $latest = [];

        foreach ($fixturePackages as $package) {
            $fixture = $package['fixture'] ?? null;
            $fixturePath = $package['fixture_path'] ?? null;
            $signedEvidence = $package['signed_evidence'] ?? null;
            if (! is_array($fixture)
                || ! is_string($fixturePath)
                || ! is_array($signedEvidence)
                || ! self::fixtureEvidenceHasTrustedProvenance(
                    $fixture,
                    $fixturePath,
                    $signedEvidence,
                    $trustedSigners,
                    $trustedConsumptionAuthorities,
                    $repositoryRoot,
                )) {
                continue;
            }

            self::recordNewerEnvironmentEvidence(
                $latest,
                (string) $fixture['environment'],
                (string) $fixture['generated_at'],
                (string) $fixture['run_id'],
                $fixture['vat_fixture_evidence'],
            );
        }

        return $latest;
    }

    /**
     * @param  array<string, array{recorded_at: string, run_id: string, evidence: array<string, mixed>}>  $latest
     * @param  array<string, mixed>  $evidence
     */
    private static function recordNewerEnvironmentEvidence(
        #[SensitiveParameter] array &$latest,
        string $environment,
        string $generatedAt,
        string $runId,
        #[SensitiveParameter] array $evidence,
    ): void {
        $recordedAt = self::timestampSortKey($generatedAt);
        if ($recordedAt === null) {
            return;
        }

        $existing = $latest[$environment] ?? null;
        $isNewer = $existing === null
            || strcmp($recordedAt, $existing['recorded_at']) > 0
            || ($recordedAt === $existing['recorded_at'] && strcmp($runId, $existing['run_id']) > 0);

        if (! $isNewer) {
            return;
        }

        $latest[$environment] = [
            'recorded_at' => $recordedAt,
            'run_id' => $runId,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);

        return count($keys) === count($expected)
            && array_diff($keys, $expected) === []
            && array_diff($expected, $keys) === [];
    }

    private static function isCanonicalTimestamp(mixed $value): bool
    {
        return self::timestampSortKey($value) !== null;
    }

    private static function strictFixtureDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s.uP') !== $value) {
            return null;
        }

        return $date;
    }

    private static function strictAttestationDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            return null;
        }

        return $date;
    }

    private static function timestampSortKey(mixed $value): ?string
    {
        return self::strictFixtureDate($value)?->format('U.u');
    }

    private static function instantMicroseconds(DateTimeImmutable $date): int
    {
        return ((int) $date->format('U') * 1_000_000) + (int) $date->format('u');
    }

    /** @param array<string, mixed> $limits */
    private static function probeLimitsAreValid(array $limits): bool
    {
        if (! self::hasExactKeys($limits, [
            'visibility_window_ms',
            'poll_interval_ms',
            'max_search_pages',
            'lost_response_timeout_ms',
            'connect_timeout_ms',
            'request_timeout_ms',
            'write_attempt_budget',
        ])) {
            return false;
        }

        foreach ($limits as $limit) {
            if (! is_int($limit)) {
                return false;
            }
        }

        if ($limits['write_attempt_budget'] !== ProbeConfiguration::ExactWriteAttemptBudget) {
            return false;
        }

        try {
            ProbeConfiguration::assertLiveLimits(
                $limits['visibility_window_ms'],
                $limits['poll_interval_ms'],
                $limits['max_search_pages'],
                $limits['lost_response_timeout_ms'],
                $limits['connect_timeout_ms'],
                $limits['request_timeout_ms'],
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $commitments */
    private static function commitmentsAreValid(array $commitments): bool
    {
        if (! self::hasExactKeys($commitments, [
            'scheme',
            'target_set_sha256',
            'configuration_set_sha256',
            'policy_set_sha256',
            'safety_set_sha256',
            'templates_set_sha256',
            'limits_set_sha256',
            'fixture_policy_sha256',
        ])
            || ($commitments['scheme'] ?? null) !== LiveEvidenceAttestationGuard::EvidenceCommitmentScheme) {
            return false;
        }

        foreach (array_diff_key($commitments, ['scheme' => true]) as $commitment) {
            if (! is_string($commitment) || preg_match('/^[a-f0-9]{64}$/', $commitment) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $signedAuthorization
     * @param  array<string, mixed>  $limits
     */
    private static function operatorAuthorizationIsStructurallyValid(
        #[SensitiveParameter] array $signedAuthorization,
        string $environment,
        #[SensitiveParameter] array $limits,
        string $runId,
        string $launchManifestSha256,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        ?VerifiedLiveProviderRun $providerRun = null,
    ): bool {
        if (! self::hasExactKeys($signedAuthorization, ['envelope', 'signature'])) {
            return false;
        }

        $envelope = $signedAuthorization['envelope'] ?? null;
        $signature = $signedAuthorization['signature'] ?? null;
        if (! is_array($envelope)
            || array_is_list($envelope)
            || ! is_string($signature)
            || ! self::isCanonicalSignature($signature)
            || ! self::hasExactKeys($envelope, [
                'contract',
                'version',
                'algorithm',
                'signer_id',
                'issued_at',
                'expires_at',
                'evidence_contract',
                'challenge',
                'harness',
                'target',
                'commitments',
                'consumption',
                'limits',
            ])) {
            return false;
        }

        $harness = $envelope['harness'] ?? null;
        $target = $envelope['target'] ?? null;
        $actualCommitments = $envelope['commitments'] ?? null;
        $consumption = $envelope['consumption'] ?? null;
        $actualLimits = $envelope['limits'] ?? null;
        $issuedAt = self::strictAttestationDate($envelope['issued_at'] ?? null);
        $expiresAt = self::strictAttestationDate($envelope['expires_at'] ?? null);

        if (($envelope['contract'] ?? null) !== LiveEvidenceAttestationGuard::AuthorizationContract
            || ($envelope['version'] ?? null) !== LiveEvidenceAttestationGuard::Version
            || ($envelope['algorithm'] ?? null) !== LiveEvidenceAttestationGuard::Algorithm
            || ! is_string($envelope['signer_id'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $envelope['signer_id']) !== 1
            || ($envelope['evidence_contract'] ?? null) !== ProbeConfiguration::EvidenceContract
            || ! self::isCanonicalChallenge($envelope['challenge'] ?? null)
            || $issuedAt === null
            || $expiresAt === null
            || self::instantMicroseconds($expiresAt) - self::instantMicroseconds($issuedAt) < 1_000_000
            || self::instantMicroseconds($expiresAt) - self::instantMicroseconds($issuedAt) > ProbeConfiguration::MaximumAttestationTtlSeconds * 1_000_000
            || $runStartedAt < $issuedAt
            || $runFinishedAt >= $expiresAt
            || ! is_array($harness)
            || ! self::hasExactKeys($harness, ['repository_commit', 'code_sha256', 'launch_manifest_sha256'])
            || ! is_string($harness['code_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $harness['code_sha256']) !== 1
            || ! is_string($harness['repository_commit'] ?? null)
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $harness['repository_commit']) !== 1
            || ($harness['launch_manifest_sha256'] ?? null) !== $launchManifestSha256
            || ! is_array($target)
            || ! self::hasExactKeys($target, ['environment', 'profile', 'tenant_hmac_sha256', 'account_hmac_sha256'])
            || ($target['environment'] ?? null) !== $environment
            || ($target['profile'] ?? null) !== ProbeConfiguration::AuthorizationProfile
            || ! is_string($target['tenant_hmac_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $target['tenant_hmac_sha256']) !== 1
            || ! is_string($target['account_hmac_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $target['account_hmac_sha256']) !== 1
            || ! is_array($actualCommitments)
            || ! self::hasExactKeys($actualCommitments, [
                'scheme',
                'configuration_hmac_sha256',
                'policy_hmac_sha256',
                'safety_hmac_sha256',
                'templates_hmac_sha256',
            ])
            || ($actualCommitments['scheme'] ?? null) !== LiveEvidenceAttestationGuard::CommitmentScheme
            || ! is_array($consumption)
            || ! self::hasExactKeys($consumption, ['authority_id', 'authority_policy_sha256', 'store_id', 'store_identity_sha256', 'run_id', 'replay_policy'])
            || ($consumption['authority_id'] ?? null) !== ProbeConfiguration::ConsumptionAuthorityId
            || ($consumption['authority_policy_sha256'] ?? null) !== hash('sha256', 's03-external-atomic-cas-policy-v1')
            || ($consumption['store_id'] ?? null) !== ProbeConfiguration::ConsumptionStoreId
            || ($consumption['store_identity_sha256'] ?? null) !== hash('sha256', 's03-external-atomic-cas-store-v1')
            || ($consumption['run_id'] ?? null) !== $runId
            || ($consumption['replay_policy'] ?? null) !== LiveEvidenceAttestationGuard::ConsumptionReplayPolicy
            || ! is_array($actualLimits)) {
            return false;
        }

        foreach (array_diff_key($actualCommitments, ['scheme' => true]) as $commitment) {
            if (! is_string($commitment) || preg_match('/^[a-f0-9]{64}$/', $commitment) !== 1) {
                return false;
            }
        }

        return hash_equals(
            LiveEvidenceAttestationGuard::canonicalJson($limits),
            LiveEvidenceAttestationGuard::canonicalJson($actualLimits),
        );
    }

    /**
     * @param  array<string, mixed>  $consumption
     * @param  array<string, mixed>  $signedAuthorization
     */
    private static function authorizationConsumptionIsStructurallyValid(
        #[SensitiveParameter] array $consumption,
        #[SensitiveParameter] array $signedAuthorization,
        string $runId,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
    ): bool {
        if (! self::hasExactKeys($consumption, ['local_claim', 'authority_receipt', 'effect_execution_receipts'])
            || ! is_array($consumption['local_claim'] ?? null)
            || ! is_array($consumption['authority_receipt'] ?? null)
            || ($consumption['effect_execution_receipts'] ?? null) !== []) {
            return false;
        }

        $localClaim = $consumption['local_claim'];
        if (! self::hasExactKeys($localClaim, [
            'contract',
            'version',
            'store_identity_sha256',
            'run_id',
            'claimed_at',
            'authorization_set_sha256',
            'challenge_set_sha256',
            'harness',
            'configuration_set_sha256',
            'replay_policy',
        ])) {
            return false;
        }

        $claimedAt = self::strictAttestationDate($localClaim['claimed_at'] ?? null);
        if (($localClaim['contract'] ?? null) !== LiveEvidenceAttestationGuard::ConsumptionReceiptContract
            || ($localClaim['version'] ?? null) !== LiveEvidenceAttestationGuard::Version
            || ($localClaim['run_id'] ?? null) !== $runId
            || ($localClaim['replay_policy'] ?? null) !== LiveEvidenceAttestationGuard::ConsumptionReplayPolicy
            || ! is_string($localClaim['store_identity_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $localClaim['store_identity_sha256']) !== 1
            || $claimedAt === null
            || $claimedAt > $runStartedAt) {
            return false;
        }

        foreach (['authorization_set_sha256', 'challenge_set_sha256', 'configuration_set_sha256'] as $key) {
            if (! is_string($localClaim[$key] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $localClaim[$key]) !== 1) {
                return false;
            }
        }

        try {
            $expectedLocalClaim = LiveEvidenceAttestationGuard::buildConsumptionReceipt([$signedAuthorization], $claimedAt);
            $authorityReceipt = ConsumptionReceipt::fromArray($consumption['authority_receipt']);
            $claimRequest = $authorityReceipt->envelope->claimRequest;
            $claimRunStartedAt = self::strictAttestationDate($claimRequest->runStartedAt);
            if ($claimRunStartedAt === null) {
                return false;
            }
            $expectedClaimRequest = LiveEvidenceAttestationGuard::buildConsumptionClaimRequest(
                [$signedAuthorization],
                $claimRunStartedAt,
                $claimRequest->claimNonce,
            );
            $issuedAt = self::strictAttestationDate($authorityReceipt->envelope->issuedAt);
            $expiresAt = self::strictAttestationDate($authorityReceipt->envelope->expiresAt);

            return $issuedAt !== null
                && $expiresAt !== null
                && $claimedAt->format('U.u') === $claimRunStartedAt->format('U.u')
                && $claimRunStartedAt <= $runStartedAt
                && $issuedAt >= $claimRunStartedAt
                && $issuedAt <= $runStartedAt
                && $expiresAt >= $runFinishedAt
                && $claimRequest->runId === $runId
                && $authorityReceipt->envelope->disposition->value === LiveEvidenceAttestationGuard::FreshConsumptionDisposition
                && hash_equals(
                    LiveEvidenceAttestationGuard::canonicalJson($expectedLocalClaim),
                    LiveEvidenceAttestationGuard::canonicalJson($localClaim),
                )
                && hash_equals(
                    LiveEvidenceAttestationGuard::canonicalJson($expectedClaimRequest),
                    LiveEvidenceAttestationGuard::canonicalJson($claimRequest->toArray()),
                );
        } catch (Throwable) {
            return false;
        }
    }

    private static function isCanonicalSignature(string $signature): bool
    {
        if (! defined('SODIUM_CRYPTO_SIGN_BYTES')) {
            return false;
        }

        $decoded = base64_decode($signature, true);

        return is_string($decoded)
            && strlen($decoded) === SODIUM_CRYPTO_SIGN_BYTES
            && base64_encode($decoded) === $signature;
    }

    private static function isCanonicalChallenge(mixed $challenge): bool
    {
        if (! is_string($challenge)) {
            return false;
        }

        $decoded = base64_decode($challenge, true);

        return is_string($decoded)
            && strlen($decoded) === 32
            && base64_encode($decoded) === $challenge;
    }

    /**
     * @param  array<string, mixed>  $scenarios
     * @param  array<string, mixed>  $evidence
     */
    private static function resolvedEvidenceMatchesScenarios(
        #[SensitiveParameter] array $scenarios,
        string $environment,
        #[SensitiveParameter] array $evidence,
    ): bool {
        $resolved = self::resolveVatPilotPolicy($scenarios, $environment);

        return self::canonicalizeFixtureValue($resolved) === self::canonicalizeFixtureValue($evidence);
    }

    private static function canonicalizeFixtureValue(#[SensitiveParameter] mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalizeFixtureValue(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::canonicalizeFixtureValue(...), $value);
    }

    /** @param array<string, mixed> $policy */
    private static function matrixPolicyIsStructurallyValid(#[SensitiveParameter] array $policy): bool
    {
        if (! self::hasExactKeys($policy, ['complete', 'safe', 'remote_identity_policy', 'failure_disposition', 'blind_retry_after_lost_response', 'required_environment_fixtures', 'missing_or_unsafe_environments'])
            || ! is_bool($policy['complete'] ?? null)
            || ! is_bool($policy['safe'] ?? null)
            || ($policy['blind_retry_after_lost_response'] ?? null) !== false
            || ($policy['required_environment_fixtures'] ?? null) !== ['demo_pl', 'demo_regional']
            || ! is_array($policy['missing_or_unsafe_environments'] ?? null)
            || ! array_is_list($policy['missing_or_unsafe_environments'])) {
            return false;
        }

        $missing = $policy['missing_or_unsafe_environments'];
        foreach ($missing as $environment) {
            if (! is_string($environment) || ! in_array($environment, ['demo_pl', 'demo_regional'], true)) {
                return false;
            }
        }

        if (array_unique($missing) !== $missing
            || array_diff($missing, ['demo_pl', 'demo_regional']) !== []) {
            return false;
        }

        $complete = $policy['complete'];

        return $policy['safe'] === $complete
            && $policy['remote_identity_policy'] === ($complete ? 'business_oid' : 'no_remote_uniqueness')
            && $policy['failure_disposition'] === ($complete ? 'resolved' : 'waiting_for_complete_environment_matrix')
            && ($missing === []) === $complete;
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $evidence
     */
    private static function matrixPolicyMatchesLocalEvidence(
        #[SensitiveParameter] array $policy,
        string $environment,
        #[SensitiveParameter] array $evidence,
    ): bool {
        if (($evidence['complete'] ?? false) === true && ($evidence['safe'] ?? false) === true) {
            return true;
        }

        return ($policy['complete'] ?? true) === false
            && in_array($environment, $policy['missing_or_unsafe_environments'] ?? [], true);
    }

    private static function scenarioEvidenceIsStructurallyValid(
        string $name,
        #[SensitiveParameter] mixed $scenario,
        int $visibilityWindowMs,
    ): bool {
        if (! is_array($scenario)
            || ! is_bool($scenario['complete'] ?? null)
            || ! is_bool($scenario['safe'] ?? null)
            || ($scenario['safe'] && ! $scenario['complete'])) {
            return false;
        }

        return match ($name) {
            'concurrent_same_oid' => self::concurrentEvidenceIsValid($scenario, $visibilityWindowMs),
            'same_oid_different_payload' => self::differentPayloadEvidenceIsValid($scenario),
            'lost_response_after_remote_ack' => self::lostResponseEvidenceIsValid($scenario, $visibilityWindowMs),
            'document_kind_scope' => self::scopeEvidenceIsValid($scenario, 3, 'kind'),
            'department_scope' => self::scopeEvidenceIsValid($scenario, 2, 'department'),
            'account_scope' => self::accountScopeEvidenceIsValid($scenario, $visibilityWindowMs),
            default => false,
        };
    }

    /** @param array<string, mixed> $scenario */
    private static function concurrentEvidenceIsValid(#[SensitiveParameter] array $scenario, int $visibilityWindowMs): bool
    {
        $classifications = $scenario['classifications'] ?? null;
        $envelopes = $scenario['response_envelopes'] ?? null;
        $visibility = $scenario['visibility'] ?? null;
        $distinct = $scenario['distinct_documents'] ?? null;

        if (! self::hasExactKeys($scenario, ['complete', 'safe', 'response_envelopes', 'classifications', 'successful_response_document_ids', 'distinct_documents', 'stored_payload_relation', 'visibility'])
            || ! self::classificationsAreValid($classifications, ['first', 'second'])
            || ! self::envelopesAreValid($envelopes, ['first', 'second'])
            || ! self::visibilityEvidenceIsValid($visibility, $visibilityWindowMs)
            || ! is_int($distinct)
            || $distinct < 0
            || ! is_int($scenario['successful_response_document_ids'] ?? null)
            || $scenario['successful_response_document_ids'] < 0
            || $scenario['successful_response_document_ids'] > 2
            || ! in_array($scenario['stored_payload_relation'] ?? null, ['matches_submitted_payload', 'unverified'], true)
            || $visibility['distinct_documents'] !== $distinct) {
            return false;
        }

        foreach (['first', 'second'] as $key) {
            if (! self::classificationMatchesEnvelope($classifications[$key], $envelopes[$key])) {
                return false;
            }
        }

        $successCount = count(array_filter(
            $classifications,
            static fn (string $classification): bool => $classification === 'success',
        ));
        $successfulResponseIds = $scenario['successful_response_document_ids'];
        if (($successCount === 0 && $successfulResponseIds !== 0)
            || ($successCount > 0 && ($successfulResponseIds < 1
                || $successfulResponseIds > $successCount
                || $visibility['final_exact_matches_expected_ids'] !== true))) {
            return false;
        }

        if ($scenario['complete']
            && (in_array('transport_error', $classifications, true)
                || in_array('other_error', $classifications, true)
                || ! $visibility['complete']
                || ! $visibility['exact_not_partial']
                || $scenario['stored_payload_relation'] !== 'matches_submitted_payload'
                || $successfulResponseIds > $distinct)) {
            return false;
        }

        $expectedSafe = $scenario['complete']
            && $successCount > 0
            && $distinct === 1;

        return $scenario['safe'] === $expectedSafe;
    }

    /** @param array<string, mixed> $scenario */
    private static function differentPayloadEvidenceIsValid(#[SensitiveParameter] array $scenario): bool
    {
        $visibilityRelation = $scenario['stored_payload_relation'] ?? null;

        if (! self::hasExactKeys($scenario, ['complete', 'safe', 'classification', 'response_envelope', 'stored_payload_relation', 'distinct_documents'])
            || ! self::classificationIsValid($scenario['classification'] ?? null)
            || ! self::envelopeIsValid($scenario['response_envelope'] ?? null)
            || ! self::classificationMatchesEnvelope($scenario['classification'], $scenario['response_envelope'])
            || ! is_int($scenario['distinct_documents'] ?? null)
            || $scenario['distinct_documents'] < 0
            || ! in_array($visibilityRelation, ['matches_original_conflicts_variant', 'ambiguous_matches_both', 'matches_variant_not_original', 'unverified'], true)) {
            return false;
        }

        if ($scenario['complete']
            && (in_array($scenario['classification'], ['transport_error', 'other_error'], true)
                || ! in_array($visibilityRelation, ['matches_original_conflicts_variant', 'ambiguous_matches_both'], true))) {
            return false;
        }

        $expectedSafe = $scenario['complete']
            && $scenario['distinct_documents'] === 1
            && $visibilityRelation === 'matches_original_conflicts_variant';

        return $scenario['safe'] === $expectedSafe;
    }

    /** @param array<string, mixed> $scenario */
    private static function lostResponseEvidenceIsValid(#[SensitiveParameter] array $scenario, int $visibilityWindowMs): bool
    {
        $visibility = $scenario['visibility'] ?? null;
        $envelope = $scenario['outcome_envelope'] ?? null;

        if (! self::hasExactKeys($scenario, ['complete', 'safe', 'failure_mode', 'transport_timeout_observed', 'transport_failure_kind', 'write_attempts', 'outcome_envelope', 'document_visible_after_loss', 'distinct_documents', 'visibility'])
            || ($scenario['failure_mode'] ?? null) !== 'transport_timeout_after_single_write_attempt'
            || ($scenario['write_attempts'] ?? null) !== 1
            || ! is_bool($scenario['transport_timeout_observed'] ?? null)
            || ! in_array($scenario['transport_failure_kind'] ?? null, ['timeout_errno_28', 'other_transport_failure', 'response_received'], true)
            || ! is_bool($scenario['document_visible_after_loss'] ?? null)
            || ! is_int($scenario['distinct_documents'] ?? null)
            || $scenario['distinct_documents'] < 0
            || ! self::visibilityEvidenceIsValid($visibility, $visibilityWindowMs)
            || ! is_array($envelope)
            || ! self::envelopeIsValid($envelope)
            || $visibility['distinct_documents'] !== $scenario['distinct_documents']
            || $visibility['found'] !== $scenario['document_visible_after_loss']) {
            return false;
        }

        if ($scenario['transport_timeout_observed']) {
            if ($scenario['transport_failure_kind'] !== 'timeout_errno_28'
                || ($envelope['classification'] ?? null) !== 'transport_error'
                || ($envelope['transport'] ?? null) !== 'exception'
                || ($envelope['exception_class'] ?? null) !== FatalRequestException::class) {
                return false;
            }
        } elseif (($envelope['transport'] ?? null) === 'response' && $scenario['transport_failure_kind'] !== 'response_received') {
            return false;
        } elseif (($envelope['transport'] ?? null) === 'exception' && $scenario['transport_failure_kind'] !== 'other_transport_failure') {
            return false;
        }

        if ($scenario['complete']
            && (! $scenario['transport_timeout_observed']
                || ! $visibility['complete']
                || ! $visibility['exact_not_partial']
                || ! $scenario['document_visible_after_loss'])) {
            return false;
        }

        $expectedSafe = $scenario['complete']
            && $scenario['distinct_documents'] === 1;

        return $scenario['safe'] === $expectedSafe;
    }

    /** @param array<string, mixed> $scenario */
    private static function scopeEvidenceIsValid(#[SensitiveParameter] array $scenario, int $outcomeCount, string $dimension): bool
    {
        $classifications = $scenario['classifications'] ?? null;
        $envelopes = $scenario['response_envelopes'] ?? null;
        $expectedKeys = $outcomeCount === 3 ? ['vat', 'proforma', 'correction'] : ['primary', 'secondary'];
        $allowedScopes = ['inconclusive', "shared_across_{$dimension}s", "per_{$dimension}", "mixed_by_{$dimension}"];

        if (! self::hasExactKeys($scenario, ['complete', 'safe', 'scope', 'classifications', 'response_envelopes', 'distinct_documents'])
            || ! self::classificationsAreValid($classifications, $expectedKeys)
            || ! self::envelopesAreValid($envelopes, $expectedKeys)
            || ! in_array($scenario['scope'] ?? null, $allowedScopes, true)
            || ! is_int($scenario['distinct_documents'] ?? null)
            || $scenario['distinct_documents'] < 0) {
            return false;
        }

        foreach ($expectedKeys as $key) {
            if (! self::classificationMatchesEnvelope($classifications[$key], $envelopes[$key])) {
                return false;
            }
        }

        $successCount = count(array_filter(
            $classifications,
            static fn (string $classification): bool => $classification === 'success',
        ));
        $expectedScope = match (true) {
            ! $scenario['complete'] => 'inconclusive',
            $successCount === 1 => "shared_across_{$dimension}s",
            $successCount === $outcomeCount => "per_{$dimension}",
            default => "mixed_by_{$dimension}",
        };

        if ($scenario['complete']
            && ($successCount < 1
                || in_array('transport_error', $classifications, true)
                || in_array('other_error', $classifications, true)
                || $scenario['distinct_documents'] !== $successCount)) {
            return false;
        }

        return $scenario['safe'] === $scenario['complete']
            && $scenario['scope'] === $expectedScope;
    }

    /** @param array<string, mixed> $scenario */
    private static function accountScopeEvidenceIsValid(#[SensitiveParameter] array $scenario, int $visibilityWindowMs): bool
    {
        $envelopes = $scenario['response_envelopes'] ?? null;
        $primaryVisibility = $scenario['primary_visibility'] ?? null;
        $secondaryVisibility = $scenario['secondary_visibility'] ?? null;

        if (! self::hasExactKeys($scenario, ['complete', 'safe', 'scope', 'response_envelopes', 'primary_visibility', 'secondary_visibility'])
            || ! self::envelopesAreValid($envelopes, ['primary', 'secondary'])
            || ! self::visibilityEvidenceIsValid($primaryVisibility, $visibilityWindowMs)
            || ! self::visibilityEvidenceIsValid($secondaryVisibility, $visibilityWindowMs)
            || ! in_array($scenario['scope'] ?? null, ['per_account', 'inconclusive'], true)) {
            return false;
        }

        foreach (['primary', 'secondary'] as $key) {
            if (($envelopes[$key]['classification'] ?? null) !== 'success') {
                if ($scenario['safe']) {
                    return false;
                }
            }
        }

        if ($scenario['complete']
            && ($scenario['scope'] !== 'per_account'
                || ! $primaryVisibility['complete']
                || ! $secondaryVisibility['complete']
                || ! $primaryVisibility['exact_not_partial']
                || ! $secondaryVisibility['exact_not_partial']
                || $primaryVisibility['final_exact_matches_expected_ids'] !== true
                || $secondaryVisibility['final_exact_matches_expected_ids'] !== true
                || ! $primaryVisibility['found']
                || ! $secondaryVisibility['found'])) {
            return false;
        }

        if (! $scenario['complete'] && $scenario['scope'] !== 'inconclusive') {
            return false;
        }

        $bothSuccessful = ($envelopes['primary']['classification'] ?? null) === 'success'
            && ($envelopes['secondary']['classification'] ?? null) === 'success';
        $expectedSafe = $scenario['complete']
            && $bothSuccessful
            && $primaryVisibility['distinct_documents'] === 1
            && $secondaryVisibility['distinct_documents'] === 1;

        return $scenario['safe'] === $expectedSafe;
    }

    /** @param list<string> $expectedKeys */
    private static function classificationsAreValid(#[SensitiveParameter] mixed $classifications, array $expectedKeys): bool
    {
        if (! is_array($classifications) || ! self::hasExactKeys($classifications, $expectedKeys)) {
            return false;
        }

        foreach ($classifications as $classification) {
            if (! self::classificationIsValid($classification)) {
                return false;
            }
        }

        return true;
    }

    private static function classificationIsValid(#[SensitiveParameter] mixed $classification): bool
    {
        return in_array($classification, ['success', 'duplicate', 'transport_error', 'other_error'], true);
    }

    private static function classificationMatchesEnvelope(
        #[SensitiveParameter] mixed $classification,
        #[SensitiveParameter] mixed $envelope,
    ): bool {
        return self::classificationIsValid($classification)
            && self::envelopeIsValid($envelope)
            && is_array($envelope)
            && ($envelope['classification'] ?? null) === $classification;
    }

    /** @param list<string> $expectedKeys */
    private static function envelopesAreValid(#[SensitiveParameter] mixed $envelopes, array $expectedKeys): bool
    {
        if (! is_array($envelopes) || ! self::hasExactKeys($envelopes, $expectedKeys)) {
            return false;
        }

        foreach ($envelopes as $envelope) {
            if (! self::envelopeIsValid($envelope)) {
                return false;
            }
        }

        return true;
    }

    private static function envelopeIsValid(#[SensitiveParameter] mixed $envelope): bool
    {
        if (! is_array($envelope) || ! self::classificationIsValid($envelope['classification'] ?? null)) {
            return false;
        }

        if (($envelope['transport'] ?? null) === 'exception') {
            return self::hasExactKeys($envelope, ['classification', 'transport', 'exception_class'])
                && ($envelope['classification'] ?? null) === 'transport_error'
                && is_string($envelope['exception_class'] ?? null)
                && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $envelope['exception_class']) === 1;
        }

        if (($envelope['transport'] ?? null) !== 'response'
            || ($envelope['classification'] ?? null) === 'transport_error'
            || ! self::hasExactKeys($envelope, ['classification', 'transport', 'http_status', 'content_type', 'request_ids', 'body', 'normalized_body_sha256'])
            || ! is_int($envelope['http_status'] ?? null)
            || $envelope['http_status'] < 100
            || $envelope['http_status'] > 599
            || (! is_string($envelope['content_type'] ?? null) && $envelope['content_type'] !== null)
            || ! is_array($envelope['request_ids'] ?? null)
            || ! self::hasExactKeys($envelope['request_ids'], ['x-request-id', 'x-correlation-id', 'traceparent'])
            || ! self::safeResponseBodyIsValid($envelope['body'] ?? null)
            || ! is_string($envelope['normalized_body_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $envelope['normalized_body_sha256']) !== 1
            || ! hash_equals(
                $envelope['normalized_body_sha256'],
                hash('sha256', json_encode($envelope['body'], JSON_THROW_ON_ERROR)),
            )) {
            return false;
        }

        foreach ($envelope['request_ids'] as $requestId) {
            if (! is_array($requestId)
                || ! self::hasExactKeys($requestId, ['present', 'keyed_digest'])
                || ! is_bool($requestId['present'] ?? null)) {
                return false;
            }

            if ($requestId['present'] === true
                && (! is_string($requestId['keyed_digest'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/', $requestId['keyed_digest']) !== 1)) {
                return false;
            }

            if ($requestId['present'] === false && $requestId['keyed_digest'] !== null) {
                return false;
            }
        }

        return self::responseEnvelopeMatchesClassification($envelope);
    }

    /** @param array<string, mixed> $envelope */
    private static function responseEnvelopeMatchesClassification(#[SensitiveParameter] array $envelope): bool
    {
        $classification = $envelope['classification'];
        $status = $envelope['http_status'];
        $body = $envelope['body'];

        if ($classification === 'success') {
            return $status >= 200
                && $status < 300
                && in_array('id', $body['keys'] ?? [], true)
                && in_array('oid', $body['keys'] ?? [], true)
                && ($body['id'] ?? null) === '<document-id>'
                && ($body['oid'] ?? null) === '<probe-oid>'
                && ($body['error_fields'] ?? null) === []
                && ! self::safeBodyContainsErrorSignals($body);
        }

        if ($classification === 'duplicate') {
            $signals = $body['duplicate_signals'] ?? null;

            return in_array($status, [409, 422], true)
                && is_array($signals)
                && in_array('oid', $signals, true)
                && count($signals) > 1
                && ($body['error_fields'] ?? []) !== [];
        }

        return $classification === 'other_error';
    }

    /** @param array<string, mixed> $body */
    private static function safeBodyContainsErrorSignals(#[SensitiveParameter] array $body): bool
    {
        $status = $body['status'] ?? null;
        if (is_string($status)
            && preg_match('/^(?:[45][0-9]{2}|error|failed|failure|invalid|unprocessable|unauthorized|forbidden|rejected|denied)$/i', trim($status)) === 1) {
            return true;
        }

        $code = $body['code'] ?? null;

        return is_string($code)
            && preg_match('/^(?:[45][0-9]{2}|.*(?:error|fail|invalid|unauthorized|forbidden|reject|denied).*)$/i', trim($code)) === 1;
    }

    private static function safeResponseBodyIsValid(#[SensitiveParameter] mixed $body): bool
    {
        if (! is_array($body)) {
            return false;
        }

        if (self::hasExactKeys($body, ['type'])) {
            return is_string($body['type'] ?? null)
                && preg_match('/^[A-Za-z0-9_\\-]{1,64}$/', $body['type']) === 1;
        }

        $required = ['keys', 'id', 'oid', 'error_fields', 'duplicate_signals'];
        $allowed = [...$required, 'kind', 'status', 'code'];
        if (array_diff($required, array_keys($body)) !== []
            || array_diff(array_keys($body), $allowed) !== []
            || ! self::isUniqueAllowedStringList($body['keys'] ?? null, ['id', 'oid', 'kind', 'status', 'code', 'error', 'errors', 'message', 'base'])
            || ! in_array($body['id'] ?? null, [null, '<document-id>'], true)
            || ! in_array($body['oid'] ?? null, [null, '<probe-oid>'], true)
            || ! self::isUniqueAllowedStringList($body['error_fields'] ?? null, ['error', 'errors', 'message', 'base'])
            || ! self::isUniqueAllowedStringList($body['duplicate_signals'] ?? null, ['oid', 'unique', 'unikal', 'duplicate', 'duplik', 'already exists', 'już istnieje'])) {
            return false;
        }

        foreach (['kind', 'status', 'code'] as $key) {
            if (! array_key_exists($key, $body)) {
                continue;
            }

            $value = $body[$key];
            if (! is_string($value)
                || ($value !== '<non-code>'
                    && $value !== '<redacted>'
                    && preg_match('/^[a-z0-9_.-]{1,64}$/i', $value) !== 1)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $allowed */
    private static function isUniqueAllowedStringList(mixed $value, array $allowed): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                return false;
            }
        }

        return array_unique($value) === $value;
    }

    private static function visibilityEvidenceIsValid(#[SensitiveParameter] mixed $visibility, int $visibilityWindowMs): bool
    {
        if (! is_array($visibility)
            || ! self::hasExactKeys($visibility, ['complete', 'found', 'distinct_documents', 'exact_not_partial', 'first_visible_after_ms', 'visibility_window_ms', 'observation_elapsed_ms', 'final_boundary_started_after_ms', 'final_boundary_finished_after_ms', 'deadline_exhausted', 'polls', 'exact_pages', 'partial_pages', 'last_http_status', 'final_exact_contains_all_observed', 'final_exact_matches_expected_ids'])
            || ! is_bool($visibility['complete'] ?? null)
            || ! is_bool($visibility['found'] ?? null)
            || ! is_bool($visibility['exact_not_partial'] ?? null)
            || ! is_bool($visibility['deadline_exhausted'] ?? null)
            || ! is_bool($visibility['final_exact_contains_all_observed'] ?? null)
            || (! is_bool($visibility['final_exact_matches_expected_ids'] ?? null) && $visibility['final_exact_matches_expected_ids'] !== null)
            || ! is_int($visibility['distinct_documents'] ?? null)
            || $visibility['distinct_documents'] < 0
            || (! is_int($visibility['first_visible_after_ms'] ?? null) && $visibility['first_visible_after_ms'] !== null)
            || (is_int($visibility['first_visible_after_ms'] ?? null) && $visibility['first_visible_after_ms'] < 0)
            || ! is_int($visibility['visibility_window_ms'] ?? null)
            || $visibility['visibility_window_ms'] !== $visibilityWindowMs
            || ! is_int($visibility['observation_elapsed_ms'] ?? null)
            || $visibility['observation_elapsed_ms'] < 0
            || ! is_int($visibility['final_boundary_started_after_ms'] ?? null)
            || $visibility['final_boundary_started_after_ms'] < 0
            || ! is_int($visibility['final_boundary_finished_after_ms'] ?? null)
            || $visibility['final_boundary_finished_after_ms'] < $visibility['final_boundary_started_after_ms']
            || $visibility['observation_elapsed_ms'] < $visibility['final_boundary_finished_after_ms']
            || ($visibility['deadline_exhausted'] && $visibility['observation_elapsed_ms'] < $visibilityWindowMs)
            || ! is_int($visibility['polls'] ?? null)
            || $visibility['polls'] < 1
            || ! is_int($visibility['exact_pages'] ?? null)
            || $visibility['exact_pages'] < 0
            || ! is_int($visibility['partial_pages'] ?? null)
            || $visibility['partial_pages'] < 0
            || (! is_int($visibility['last_http_status'] ?? null) && $visibility['last_http_status'] !== null)
            || (is_int($visibility['last_http_status'] ?? null) && ($visibility['last_http_status'] < 100 || $visibility['last_http_status'] > 599))) {
            return false;
        }

        return $visibility['found'] === ($visibility['distinct_documents'] > 0)
            && $visibility['found'] === ($visibility['first_visible_after_ms'] !== null)
            && (! $visibility['exact_not_partial'] || $visibility['complete'])
            && $visibility['final_exact_matches_expected_ids'] !== false
            && (! $visibility['complete'] || ($visibility['final_exact_contains_all_observed']
                && $visibility['exact_pages'] >= 1
                && $visibility['partial_pages'] >= 1
                && $visibility['final_boundary_finished_after_ms'] <= $visibilityWindowMs
                && is_int($visibility['last_http_status'])
                && $visibility['last_http_status'] >= 200
                && $visibility['last_http_status'] < 300));
    }

    private function preflight(#[SensitiveParameter] ProbeEndpoint $endpoint): string
    {
        try {
            $response = $this->connector($endpoint)->send(new AccountProbeRequest($endpoint->token));
        } catch (Throwable) {
            throw new RuntimeException('The DEMO account preflight failed before an account ID could be verified.');
        }
        try {
            $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = null;
        }
        $accountId = is_array($body) ? ($body['id'] ?? null) : null;
        $nestedAccount = is_array($body) ? ($body['account'] ?? null) : null;
        $nestedAccountId = is_array($nestedAccount) ? ($nestedAccount['id'] ?? null) : null;
        $hasNestedAccount = is_array($body) && array_key_exists('account', $body);

        if ($response->status() !== 200
            || ! is_array($body)
            || array_is_list($body)
            || self::containsErrorEnvelopeSignals($body)
            || (! is_int($accountId) && ! is_string($accountId))
            || preg_match('/^[1-9][0-9]{0,18}$/', (string) $accountId) !== 1
            || ($hasNestedAccount
                && (! is_array($nestedAccount)
                    || array_is_list($nestedAccount)
                    || (! is_int($nestedAccountId) && ! is_string($nestedAccountId))
                    || preg_match('/^[1-9][0-9]{0,18}$/', (string) $nestedAccountId) !== 1
                    || (string) $nestedAccountId !== (string) $accountId))) {
            throw new RuntimeException('The DEMO account preflight did not return an account ID.');
        }

        return $endpoint->verifyAccountId((string) $accountId);
    }

    /** @return array<string, mixed> */
    private function concurrent(#[SensitiveParameter] string $oid): array
    {
        $invoice = $this->invoice($oid);
        $connector = $this->connector($this->configuration->primary);
        $responses = [];
        $failures = [];
        $startedAt = hrtime(true);
        $this->configuration->assertEffectAuthorizedNow(2);
        $connector->pool(
            ['first' => new CreateProbeInvoiceRequest($this->configuration->primary->token, $invoice), 'second' => new CreateProbeInvoiceRequest($this->configuration->primary->token, $invoice)],
            2,
            function (#[SensitiveParameter] Response $response, string|int $key) use (&$responses): void {
                $responses[(string) $key] = $response;
            },
            function (#[SensitiveParameter] mixed $reason, string|int $key) use (&$failures): void {
                $failures[(string) $key] = $reason instanceof Throwable ? $reason : new RuntimeException('Unknown pool failure.');
            },
        )->send()->wait();
        $outcomes = ['first' => $responses['first'] ?? $failures['first'] ?? new RuntimeException('Missing pool outcome.'), 'second' => $responses['second'] ?? $failures['second'] ?? new RuntimeException('Missing pool outcome.')];
        $classes = array_map(fn (#[SensitiveParameter] Response|Throwable $outcome): string => self::classify($outcome), $outcomes);
        $successfulResponseIds = array_values(array_filter(array_map(self::responseDocumentId(...), $outcomes)));
        $expectedDocumentIds = array_values(array_unique($successfulResponseIds));
        $visibility = $this->observe(
            $this->configuration->primary,
            $oid,
            $startedAt,
            $expectedDocumentIds === [] ? null : $expectedDocumentIds,
        );
        $proof = $this->storedProof($visibility, [$invoice]);
        $complete = $this->classified($classes) && $visibility['complete'] && $proof
            && self::responseIdsMatchVisibility($outcomes, $visibility['documents']);

        return [
            'oid' => $oid,
            'expected_document_ids' => $expectedDocumentIds,
            'complete' => $complete,
            'safe' => $complete && in_array('success', $classes, true) && $visibility['distinct_documents'] === 1 && $visibility['exact_not_partial'],
            'response_envelopes' => array_map(fn (#[SensitiveParameter] Response|Throwable $outcome): array => $this->envelope($outcome, $oid), $outcomes),
            'classifications' => $classes,
            'successful_response_document_ids' => count(array_unique($successfulResponseIds)),
            'distinct_documents' => $visibility['distinct_documents'],
            'stored_payload_relation' => $proof ? 'matches_submitted_payload' : 'unverified',
            'visibility' => $this->visibleEvidence($visibility),
        ];
    }

    /**
     * @param  list<string>  $expectedDocumentIds
     * @return array<string, mixed>
     */
    private function differentPayload(
        #[SensitiveParameter] string $oid,
        #[SensitiveParameter] array $expectedDocumentIds,
    ): array {
        $original = $this->invoice($oid);
        $variant = [...$original, 'buyer_name' => (string) ($original['buyer_name'] ?? '').' S03 VARIANT'];
        $startedAt = hrtime(true);
        $outcome = $this->send($this->configuration->primary, $variant);
        $visibility = $this->observe(
            $this->configuration->primary,
            $oid,
            $startedAt,
            $expectedDocumentIds === [] ? null : $expectedDocumentIds,
        );
        $classification = self::classify($outcome);
        $matchesOriginal = $this->storedProof($visibility, [$original]);
        $matchesVariant = $this->storedProof($visibility, [$variant]);
        $storedPayloadRelation = match ([$matchesOriginal, $matchesVariant]) {
            [true, false] => 'matches_original_conflicts_variant',
            [true, true] => 'ambiguous_matches_both',
            [false, true] => 'matches_variant_not_original',
            default => 'unverified',
        };
        $complete = $this->classified([$classification]) && $visibility['complete'] && $matchesOriginal
            && self::responseIdsMatchVisibility([$outcome], $visibility['documents']);

        return [
            'complete' => $complete,
            'safe' => $complete && $visibility['distinct_documents'] === 1 && ! $matchesVariant,
            'classification' => $classification,
            'response_envelope' => $this->envelope($outcome, $oid),
            'stored_payload_relation' => $storedPayloadRelation,
            'distinct_documents' => $visibility['distinct_documents'],
        ];
    }

    /** @return array<string, mixed> */
    private function lostResponse(#[SensitiveParameter] string $oid): array
    {
        $invoice = $this->invoice($oid);
        $startedAt = hrtime(true);
        $this->configuration->assertEffectAuthorizedNow();
        try {
            $outcome = $this->connector($this->configuration->primary)->send(new CreateTimedProbeInvoiceRequest(
                $this->configuration->primary->token,
                $invoice,
                $this->configuration->connectTimeoutMs,
                $this->configuration->lostResponseTimeoutMs,
            ));
        } catch (Throwable $exception) {
            $outcome = $exception;
        }
        $visibility = $this->observe($this->configuration->primary, $oid, $startedAt);
        $timedOut = self::isTimeoutOutcome($outcome);
        $complete = $timedOut && $visibility['complete'] && $this->storedProof($visibility, [$invoice]);

        return [
            'complete' => $complete,
            'safe' => $complete && $visibility['distinct_documents'] === 1 && $visibility['exact_not_partial'],
            'failure_mode' => 'transport_timeout_after_single_write_attempt',
            'transport_timeout_observed' => $timedOut,
            'transport_failure_kind' => $timedOut
                ? 'timeout_errno_28'
                : ($outcome instanceof Response ? 'response_received' : 'other_transport_failure'),
            'write_attempts' => 1,
            'outcome_envelope' => $this->envelope($outcome, $oid),
            'document_visible_after_loss' => $visibility['found'],
            'distinct_documents' => $visibility['distinct_documents'],
            'visibility' => $this->visibleEvidence($visibility),
        ];
    }

    /** @return array<string, mixed> */
    private function kindScope(#[SensitiveParameter] string $oid): array
    {
        $vat = $this->invoice($oid, 'vat', 'vat');
        $proforma = $this->invoice($oid, 'proforma', 'proforma');
        $startedAt = hrtime(true);
        $outcomes = ['vat' => $this->send($this->configuration->primary, $vat), 'proforma' => $this->send($this->configuration->primary, $proforma)];
        $vatId = $outcomes['vat'] instanceof Response ? $outcomes['vat']->json('id') : null;
        $correction = $this->invoice($oid, 'correction', 'correction', 'correction_invoice');

        if (self::classify($outcomes['vat']) === 'success' && is_scalar($vatId)) {
            $correction['invoice_id'] = $vatId;
            $correction['from_invoice_id'] = $vatId;
            $outcomes['correction'] = $this->send($this->configuration->primary, $correction);
        } else {
            $outcomes['correction'] = new RuntimeException('VAT response did not contain an ID.');
        }

        return $this->scopeResult(
            $outcomes,
            ['vat' => $vat, 'proforma' => $proforma, 'correction' => $correction],
            $this->observe($this->configuration->primary, $oid, $startedAt, self::successfulDocumentIds($outcomes)),
            'kind',
        );
    }

    /** @return array<string, mixed> */
    private function departmentScope(#[SensitiveParameter] string $oid): array
    {
        $primary = $this->invoice($oid, marker: 'department-primary');
        $secondary = [...$this->invoice($oid, marker: 'department-secondary'), 'department_id' => $this->configuration->payload['secondary_department_id']];
        $startedAt = hrtime(true);
        $outcomes = ['primary' => $this->send($this->configuration->primary, $primary), 'secondary' => $this->send($this->configuration->primary, $secondary)];

        return $this->scopeResult(
            $outcomes,
            ['primary' => $primary, 'secondary' => $secondary],
            $this->observe($this->configuration->primary, $oid, $startedAt, self::successfulDocumentIds($outcomes)),
            'department',
        );
    }

    /** @return array<string, mixed> */
    private function accountScope(#[SensitiveParameter] string $oid): array
    {
        $primary = $this->invoice($oid, marker: 'account', template: 'invoice');
        $secondary = $this->invoice($oid, marker: 'account', template: 'secondary_account_invoice');
        $startedAt = hrtime(true);
        $outcomes = ['primary' => $this->send($this->configuration->primary, $primary), 'secondary' => $this->send($this->configuration->secondary, $secondary)];
        $primaryExpectedIds = self::successfulDocumentIds(['primary' => $outcomes['primary']]);
        $secondaryExpectedIds = self::successfulDocumentIds(['secondary' => $outcomes['secondary']]);
        $primaryVisibility = $this->observe($this->configuration->primary, $oid, $startedAt, $primaryExpectedIds);
        $secondaryVisibility = $this->observe($this->configuration->secondary, $oid, $startedAt, $secondaryExpectedIds);
        $classes = array_map(fn (#[SensitiveParameter] Response|Throwable $outcome): string => self::classify($outcome), $outcomes);
        $complete = $this->classified($classes)
            && $this->storedProof($primaryVisibility, [$primary]) && $this->storedProof($secondaryVisibility, [$secondary])
            && self::responseIdsMatchVisibility([$outcomes['primary']], $primaryVisibility['documents'])
            && self::responseIdsMatchVisibility([$outcomes['secondary']], $secondaryVisibility['documents']);
        $bothWritesSucceeded = $classes === ['primary' => 'success', 'secondary' => 'success'];

        return [
            'complete' => $complete,
            'safe' => $complete && $bothWritesSucceeded
                && $primaryVisibility['distinct_documents'] === 1 && $secondaryVisibility['distinct_documents'] === 1
                && $primaryVisibility['exact_not_partial'] && $secondaryVisibility['exact_not_partial'],
            'scope' => $complete ? 'per_account' : 'inconclusive',
            'response_envelopes' => array_map(fn (#[SensitiveParameter] Response|Throwable $outcome): array => $this->envelope($outcome, $oid), $outcomes),
            'primary_visibility' => $this->visibleEvidence($primaryVisibility),
            'secondary_visibility' => $this->visibleEvidence($secondaryVisibility),
        ];
    }

    /**
     * @param  array<string, Response|Throwable>  $outcomes
     * @param  array<string, array<string, mixed>>  $payloads
     * @param  array<string, mixed>  $visibility
     * @return array<string, mixed>
     */
    private function scopeResult(
        #[SensitiveParameter] array $outcomes,
        #[SensitiveParameter] array $payloads,
        #[SensitiveParameter] array $visibility,
        string $dimension,
    ): array {
        $classes = array_map(fn (#[SensitiveParameter] Response|Throwable $outcome): string => self::classify($outcome), $outcomes);
        $successfulPayloads = array_values(array_filter($payloads, fn (string $key): bool => ($classes[$key] ?? null) === 'success', ARRAY_FILTER_USE_KEY));
        $successes = count($successfulPayloads);
        $complete = $this->classified($classes) && $visibility['complete'] && $this->storedProof($visibility, $successfulPayloads)
            && self::responseIdsMatchVisibility($outcomes, $visibility['documents'])
            && $visibility['distinct_documents'] === $successes;
        $scope = match (true) {
            ! $complete => 'inconclusive',
            $successes === 1 => "shared_across_{$dimension}s",
            $successes === count($outcomes) => "per_{$dimension}",
            default => "mixed_by_{$dimension}",
        };

        return [
            'complete' => $complete,
            'safe' => $complete,
            'scope' => $scope,
            'classifications' => $classes,
            'response_envelopes' => array_map(fn (#[SensitiveParameter] Response|Throwable $outcome): array => $this->envelope($outcome, ''), $outcomes),
            'distinct_documents' => $visibility['distinct_documents'],
        ];
    }

    /**
     * @param  list<string>|null  $expectedDocumentIds
     * @return array<string, mixed>
     */
    private function observe(
        #[SensitiveParameter] ProbeEndpoint $endpoint,
        #[SensitiveParameter] string $oid,
        int $effectStartedAt,
        #[SensitiveParameter] ?array $expectedDocumentIds = null,
    ): array {
        $observationStartedAt = hrtime(true);
        $deadline = $observationStartedAt + ($this->configuration->visibilityWindowMs * 1_000_000);
        $polls = 0;
        $exact = ['complete' => false, 'documents' => [], 'unexpected' => 0, 'pages' => 0, 'status' => null];
        $partial = ['complete' => false, 'documents' => [], 'unexpected' => 0, 'pages' => 0, 'status' => null];
        $observedDocuments = [];
        $allPollsComplete = true;
        $unexpected = 0;
        $firstVisibleMs = null;
        $finalBoundaryStartedAfterMs = 0;
        $finalBoundaryFinishedAfterMs = 0;

        while (true) {
            $now = hrtime(true);
            if ($now >= $deadline) {
                break;
            }

            $polls++;
            $finalBoundaryStartedAfterMs = (int) (($now - $observationStartedAt) / 1_000_000);
            $exact = $this->searchAll($endpoint, $oid, $oid, $deadline);

            if (! $exact['complete']) {
                $allPollsComplete = false;
                break;
            }

            $unexpected += $exact['unexpected'];

            foreach ($exact['documents'] as $document) {
                if (is_scalar($document['id'] ?? null)) {
                    $observedDocuments[(string) $document['id']] = $document;
                }
            }

            if ($firstVisibleMs === null && $observedDocuments !== []) {
                $firstVisibleMs = (int) ((hrtime(true) - $effectStartedAt) / 1_000_000);
            }

            $partial = $this->searchAll($endpoint, mb_substr($oid, 0, -4), $oid, $deadline);
            $finalBoundaryFinishedAfterMs = (int) ceil((hrtime(true) - $observationStartedAt) / 1_000_000);
            if (! $partial['complete']) {
                $allPollsComplete = false;
                break;
            }

            $now = hrtime(true);
            if ($now >= $deadline) {
                break;
            }

            $remainingMicroseconds = (int) ceil(($deadline - $now) / 1000);
            usleep(min($this->configuration->pollIntervalMs * 1000, max(1, $remainingMicroseconds)));
        }

        $observationElapsedMs = (int) ceil((hrtime(true) - $observationStartedAt) / 1_000_000);
        $documents = $this->uniqueDocuments(array_values($observedDocuments));
        $finalDocumentIds = [];
        foreach ($exact['documents'] as $document) {
            if (is_scalar($document['id'] ?? null)) {
                $finalDocumentIds[] = (string) $document['id'];
            }
        }
        $finalContainsAllObserved = $exact['complete']
            && array_diff(array_keys($observedDocuments), array_unique($finalDocumentIds)) === [];
        sort($finalDocumentIds);
        if ($expectedDocumentIds !== null) {
            $expectedDocumentIds = array_values(array_unique(array_map('strval', $expectedDocumentIds)));
            sort($expectedDocumentIds);
        }
        $finalMatchesExpectedIds = $expectedDocumentIds === null ? null : $finalDocumentIds === $expectedDocumentIds;
        $exactNotPartial = $allPollsComplete && $finalContainsAllObserved && $finalMatchesExpectedIds !== false
            && $unexpected === 0 && $partial['complete']
            && $partial['documents'] === [] && $partial['unexpected'] === 0;

        return [
            'complete' => $allPollsComplete && $finalContainsAllObserved && $finalMatchesExpectedIds !== false && $partial['complete'],
            'found' => $documents !== [],
            'documents' => $documents,
            'distinct_documents' => count($documents),
            'exact_not_partial' => $exactNotPartial,
            'first_visible_after_ms' => $firstVisibleMs,
            'visibility_window_ms' => $this->configuration->visibilityWindowMs,
            'observation_elapsed_ms' => $observationElapsedMs,
            'final_boundary_started_after_ms' => $finalBoundaryStartedAfterMs,
            'final_boundary_finished_after_ms' => $finalBoundaryFinishedAfterMs,
            'deadline_exhausted' => hrtime(true) >= $deadline,
            'polls' => $polls,
            'exact_pages' => $exact['pages'],
            'partial_pages' => $partial['pages'],
            'last_http_status' => $exact['status'],
            'final_exact_contains_all_observed' => $finalContainsAllObserved,
            'final_exact_matches_expected_ids' => $finalMatchesExpectedIds,
        ];
    }

    /** @return array{complete: bool, documents: list<array<string, mixed>>, unexpected: int, pages: int, status: int|null} */
    private function searchAll(
        #[SensitiveParameter] ProbeEndpoint $endpoint,
        #[SensitiveParameter] string $query,
        #[SensitiveParameter] string $expectedOid,
        int $deadline,
    ): array {
        $documents = [];
        $unexpected = 0;
        $status = null;
        $requestedPages = 0;

        for ($page = 1; $page <= $this->configuration->maxSearchPages; $page++) {
            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds < 1_000_000) {
                return ['complete' => false, 'documents' => [], 'unexpected' => $unexpected, 'pages' => $requestedPages, 'status' => $status];
            }

            $remainingMs = max(1, (int) ($remainingNanoseconds / 1_000_000));
            $connectTimeoutMs = min($this->configuration->connectTimeoutMs, $remainingMs);
            $requestTimeoutMs = min($this->configuration->requestTimeoutMs, $remainingMs);

            try {
                $requestedPages++;
                $response = $this->connector($endpoint, $connectTimeoutMs, $requestTimeoutMs)
                    ->send(new SearchProbeInvoicesRequest($endpoint->token, $query, $page));
                $status = $response->status();
                $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return ['complete' => false, 'documents' => [], 'unexpected' => 0, 'pages' => $requestedPages, 'status' => $status];
            }

            if (! $response->successful() || ! is_array($body) || (! array_is_list($body) && ! isset($body['id']))) {
                return ['complete' => false, 'documents' => [], 'unexpected' => 0, 'pages' => $requestedPages, 'status' => $status];
            }

            $pageDocuments = array_is_list($body) ? $body : [$body];

            foreach ($pageDocuments as $document) {
                if (! is_array($document)
                    || array_is_list($document)
                    || ! self::isCanonicalRemoteId($document['id'] ?? null)
                    || self::containsErrorEnvelopeSignals($document)) {
                    return ['complete' => false, 'documents' => [], 'unexpected' => 0, 'pages' => $requestedPages, 'status' => $status];
                }

                if (($document['oid'] ?? null) === $expectedOid) {
                    $documents[] = $document;
                } else {
                    $unexpected++;
                }
            }

            if (count($pageDocuments) < 100) {
                return ['complete' => true, 'documents' => $documents, 'unexpected' => $unexpected, 'pages' => $requestedPages, 'status' => $status];
            }
        }

        return ['complete' => false, 'documents' => [], 'unexpected' => $unexpected, 'pages' => $this->configuration->maxSearchPages, 'status' => $status];
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function uniqueDocuments(#[SensitiveParameter] array $documents): array
    {
        $unique = [];

        foreach ($documents as $document) {
            if (isset($document['id'])) {
                $unique[(string) $document['id']] = $document;
            }
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, mixed>  $visibility
     * @param  list<array<string, mixed>>  $payloads
     */
    private function storedProof(
        #[SensitiveParameter] array $visibility,
        #[SensitiveParameter] array $payloads,
    ): bool {
        if (! $visibility['complete'] || ! $visibility['exact_not_partial'] || $payloads === [] || ! is_array($visibility['documents'])) {
            return false;
        }

        foreach ($payloads as $payload) {
            $expected = $this->fingerprint($payload, $payload);
            if ($expected === null) {
                return false;
            }

            $matched = false;
            foreach ($visibility['documents'] as $document) {
                if (is_array($document) && hash_equals($expected, $this->fingerprint($document, $payload) ?? '')) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function invoice(
        #[SensitiveParameter] string $oid,
        string $kind = 'vat',
        string $marker = 'original',
        string $template = 'invoice',
    ): array {
        /** @var array<string, mixed> $invoice */
        $invoice = $this->configuration->payload[$template];

        return [...$invoice, 'kind' => $kind, 'oid' => $oid, 'oid_unique' => 'yes', 'internal_note' => "s0.3-{$marker}"];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $template
     */
    private function fingerprint(
        #[SensitiveParameter] array $document,
        #[SensitiveParameter] array $template,
    ): ?string {
        $canonical = $this->canonicalizeByTemplate($document, $template);
        if (! is_array($canonical)) {
            return null;
        }

        return hash_hmac(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR),
            $this->payloadComparisonKey,
        );
    }

    private function canonicalizeByTemplate(
        #[SensitiveParameter] mixed $actual,
        #[SensitiveParameter] mixed $template,
        string $key = '',
    ): mixed {
        if (is_array($template)) {
            if (! is_array($actual) || array_is_list($actual) !== array_is_list($template)) {
                return null;
            }

            if (array_is_list($template)) {
                if (count($actual) !== count($template)) {
                    return null;
                }

                $canonical = [];
                foreach ($template as $index => $templateValue) {
                    $value = $this->canonicalizeByTemplate($actual[$index] ?? null, $templateValue, $key);
                    if ($value === null && $templateValue !== null) {
                        return null;
                    }
                    $canonical[] = $value;
                }

                return $canonical;
            }

            $canonical = [];
            foreach ($template as $field => $templateValue) {
                if ($key === '' && $field === 'oid_unique') {
                    continue;
                }
                if (! array_key_exists($field, $actual)) {
                    return null;
                }

                $value = $this->canonicalizeByTemplate($actual[$field], $templateValue, (string) $field);
                if ($value === null && $templateValue !== null) {
                    return null;
                }
                $canonical[(string) $field] = $value;
            }
            ksort($canonical);

            return $canonical;
        }

        if ($template === null) {
            return $actual === null ? null : ['unexpected_non_null' => true];
        }
        if (is_bool($template)) {
            return is_bool($actual) ? $actual : null;
        }
        if (in_array($key, ['quantity', 'price_net', 'price_gross', 'tax', 'discount', 'discount_percent', 'department_id', 'invoice_id', 'from_invoice_id'], true)) {
            return $this->normalizeDecimal($actual);
        }

        return $this->normalizeScalar($actual);
    }

    private function normalizeScalar(#[SensitiveParameter] mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return trim((string) $value);
    }

    private function normalizeDecimal(#[SensitiveParameter] mixed $value): ?string
    {
        $string = $this->normalizeScalar($value);
        if ($string === null || ! is_numeric($value)) {
            return null;
        }
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $string, $matches)) {
            return null;
        }

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($matches[3] ?? '', '0');
        $sign = $matches[1] === '-' && ($integer !== '0' || $fraction !== '') ? '-' : '';

        return $sign.$integer.($fraction === '' ? '' : '.'.$fraction);
    }

    /** @param array<array-key, string> $classes */
    private function classified(array $classes): bool
    {
        return ! in_array('transport_error', $classes, true) && ! in_array('other_error', $classes, true);
    }

    public static function classify(#[SensitiveParameter] Response|Throwable $outcome): string
    {
        if (! $outcome instanceof Response) {
            return 'transport_error';
        }

        try {
            $body = json_decode($outcome->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'other_error';
        }

        $requestBody = $outcome->getPendingRequest()->body()->all();
        $invoice = is_array($requestBody) ? ($requestBody['invoice'] ?? null) : null;
        $expectedOid = is_array($invoice) ? ($invoice['oid'] ?? null) : null;
        $validSuccess = $outcome->successful()
            && is_array($body)
            && ! array_is_list($body)
            && self::isCanonicalRemoteId($body['id'] ?? null)
            && is_string($expectedOid)
            && $expectedOid !== ''
            && ($body['oid'] ?? null) === $expectedOid
            && ! self::containsErrorEnvelopeSignals($body);

        if ($validSuccess) {
            return 'success';
        }

        $duplicate = in_array($outcome->status(), [409, 422], true) && ProbeFixtureSanitizer::isOidDuplicate($body);

        return $duplicate ? 'duplicate' : 'other_error';
    }

    private static function isCanonicalRemoteId(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]{0,18}$/', (string) $value) === 1;
    }

    /** @param array<string, mixed> $body */
    private static function containsErrorEnvelopeSignals(#[SensitiveParameter] array $body, int $depth = 0): bool
    {
        if ($depth > 8) {
            return true;
        }

        foreach (['error', 'errors', 'message', 'base'] as $key) {
            if (array_key_exists($key, $body)) {
                return true;
            }
        }

        foreach (['success', 'ok'] as $key) {
            if (array_key_exists($key, $body) && $body[$key] === false) {
                return true;
            }
        }

        $status = $body['status'] ?? null;
        if ((is_int($status) && $status >= 400)
            || (is_string($status) && preg_match('/^(?:[45][0-9]{2}|error|failed|failure|invalid|unprocessable|unauthorized|forbidden|rejected|denied)$/i', trim($status)) === 1)) {
            return true;
        }

        $code = $body['code'] ?? null;

        if ((is_int($code) && $code >= 400)
            || (is_string($code) && preg_match('/^(?:[45][0-9]{2}|.*(?:error|fail|invalid|unauthorized|forbidden|reject|denied).*)$/i', trim($code)) === 1)) {
            return true;
        }

        foreach ($body as $value) {
            if (is_array($value) && self::containsErrorEnvelopeSignals($value, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private static function responseDocumentId(#[SensitiveParameter] Response|Throwable $outcome): ?string
    {
        if (self::classify($outcome) !== 'success' || ! $outcome instanceof Response) {
            return null;
        }

        $id = $outcome->json('id');

        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * @param  array<array-key, Response|Throwable>  $outcomes
     * @return list<string>
     */
    private static function successfulDocumentIds(#[SensitiveParameter] array $outcomes): array
    {
        return array_values(array_unique(array_filter(array_map(self::responseDocumentId(...), $outcomes))));
    }

    /**
     * @param  array<array-key, Response|Throwable>  $outcomes
     * @param  list<array<string, mixed>>  $documents
     */
    public static function responseIdsMatchVisibility(
        #[SensitiveParameter] array $outcomes,
        #[SensitiveParameter] array $documents,
    ): bool {
        $responseIds = array_values(array_filter(array_map(self::responseDocumentId(...), $outcomes)));
        $visibleIds = array_values(array_filter(array_map(
            static fn (array $document): ?string => is_scalar($document['id'] ?? null) ? (string) $document['id'] : null,
            $documents,
        )));

        return array_diff($responseIds, $visibleIds) === [];
    }

    public static function isTimeoutOutcome(#[SensitiveParameter] Response|Throwable $outcome): bool
    {
        if ($outcome instanceof ProbeTransportException) {
            return $outcome->isTimeout();
        }
        if (! $outcome instanceof FatalRequestException) {
            return false;
        }

        for ($exception = $outcome, $depth = 0; $exception instanceof Throwable && $depth < 8; $exception = $exception->getPrevious(), $depth++) {
            if ($exception instanceof ConnectException) {
                return ($exception->getHandlerContext()['errno'] ?? null) === 28;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $invoice */
    private function send(
        #[SensitiveParameter] ProbeEndpoint $endpoint,
        #[SensitiveParameter] array $invoice,
    ): Response|Throwable {
        $this->configuration->assertEffectAuthorizedNow();

        try {
            return $this->connector($endpoint)->send(new CreateProbeInvoiceRequest($endpoint->token, $invoice));
        } catch (Throwable $exception) {
            return $exception;
        }
    }

    private function connector(
        #[SensitiveParameter] ProbeEndpoint $endpoint,
        ?int $connectTimeoutMs = null,
        ?int $requestTimeoutMs = null,
    ): FakturowniaProbeConnector {
        return $this->configuration->bindTestTransport(new FakturowniaProbeConnector(
            $endpoint->baseUrl,
            $connectTimeoutMs ?? $this->configuration->connectTimeoutMs,
            $requestTimeoutMs ?? $this->configuration->requestTimeoutMs,
        ));
    }

    /** @return array<string, mixed> */
    private function envelope(
        #[SensitiveParameter] Response|Throwable $outcome,
        #[SensitiveParameter] string $oid,
    ): array {
        return $outcome instanceof Response
            ? ['classification' => self::classify($outcome), ...$this->sanitizer->response($outcome, $oid)]
            : [
                'classification' => 'transport_error',
                'transport' => 'exception',
                'exception_class' => self::isTimeoutOutcome($outcome) ? FatalRequestException::class : $outcome::class,
            ];
    }

    /**
     * @param  array<string, mixed>  $visibility
     * @return array<string, mixed>
     */
    private function visibleEvidence(#[SensitiveParameter] array $visibility): array
    {
        unset($visibility['documents'], $visibility['stored_fingerprints']);

        return $visibility;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function withoutOid(#[SensitiveParameter] array $scenario): array
    {
        unset($scenario['oid'], $scenario['expected_document_ids']);

        return $scenario;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{path: string, unsigned_attestation_path: string, unsigned_attestation_payload: array<string, mixed>}
     */
    private function writeFixture(
        #[SensitiveParameter] array $result,
        string $runId,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        ?VerifiedLiveProviderRun $providerRun = null,
    ): array {
        if (! self::fixtureEvidenceIsValid($result)
            || ($result['run_id'] ?? null) !== $runId
            || ($result['environment'] ?? null) !== $this->configuration->primary->environment) {
            throw new RuntimeException('Refusing to write a structurally invalid contract fixture.');
        }

        $directory = dirname(__DIR__, 2).'/Fixtures/Contract';

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the fixture directory.');
        }

        $basename = "invoice-identity-{$this->configuration->primary->environment}-{$runId}";
        $relativePath = "tests/Fixtures/Contract/{$basename}.json";
        $path = "{$directory}/{$basename}.json";
        $authorizationPath = "{$directory}/{$basename}.authorization-".ProbeConfiguration::AuthorizationProfile.'.json';
        $unsignedAttestationPath = "{$directory}/{$basename}.attestation.unsigned.json";
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if (! $this->sanitizer->isSafe($json)) {
            throw new RuntimeException('Refusing to write an unsafe or non-unique contract fixture.');
        }

        if (file_exists($path) || file_exists($authorizationPath) || file_exists($unsignedAttestationPath)) {
            throw new RuntimeException('Refusing to overwrite an existing contract fixture or provenance sidecar.');
        }

        $temporaryPath = tempnam($directory, '.invoice-identity-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Could not create a temporary contract fixture.');
        }

        $handle = null;
        try {
            $handle = fopen($temporaryPath, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Could not open the temporary contract fixture.');
            }

            $written = fwrite($handle, $json);
            if ($written !== strlen($json) || ! fflush($handle)) {
                throw new RuntimeException('Could not completely write the temporary contract fixture.');
            }

            fclose($handle);
            $handle = null;

            if (! chmod($temporaryPath, 0644) || ! link($temporaryPath, $path)) {
                throw new RuntimeException('Could not atomically publish the contract fixture.');
            }
            if (! unlink($temporaryPath)) {
                unlink($path);

                throw new RuntimeException('Could not finalize the atomically published contract fixture.');
            }
            $temporaryPath = null;

            try {
                $unsignedPayload = $this->configuration->buildUnsignedEvidencePayload(
                    $relativePath,
                    hash('sha256', $json),
                    $result,
                    $runStartedAt,
                    $runFinishedAt,
                    $providerRun,
                );
                $published = $this->configuration->publishUnsignedEvidenceSidecar($unsignedPayload, $providerRun);
                $canonicalUnsignedPayload = LiveEvidenceAttestationGuard::canonicalUnsignedEvidencePayload($unsignedPayload);

                if (! $this->sanitizer->isSafe($canonicalUnsignedPayload)) {
                    throw new RuntimeException('Refusing to retain an unsafe unsigned evidence sidecar.');
                }

                return [
                    'path' => $path,
                    'unsigned_attestation_path' => $published['unsigned_path'],
                    'unsigned_attestation_payload' => $unsignedPayload,
                ];
            } catch (Throwable $exception) {
                foreach ([$unsignedAttestationPath, $authorizationPath, $path] as $publishedPath) {
                    if (is_file($publishedPath)) {
                        unlink($publishedPath);
                    }
                }

                throw $exception;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}

final class ProbeTransportException extends RuntimeException
{
    private function __construct(private readonly bool $timeout)
    {
        parent::__construct('The S0.3 provider transport failed without exposing request credentials.');
    }

    public static function failure(): self
    {
        return new self(false);
    }

    public static function timeout(): self
    {
        return new self(true);
    }

    public static function fromThrowable(#[SensitiveParameter] Throwable $exception): self
    {
        if ($exception instanceof self) {
            return new self($exception->isTimeout());
        }

        for ($current = $exception, $depth = 0; $current instanceof Throwable && $depth < 8; $current = $current->getPrevious(), $depth++) {
            if ($current instanceof ConnectException && ($current->getHandlerContext()['errno'] ?? null) === 28) {
                return self::timeout();
            }
        }

        return self::failure();
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }
}

final readonly class ProbeLiteralResponse
{
    /** @var array<array-key, mixed>|string */
    private array|string $body;

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param  array<array-key, mixed>|string  $body
     * @param  array<mixed, mixed>  $headers
     */
    private function __construct(
        #[SensitiveParameter] array|string $body,
        public int $status,
        #[SensitiveParameter] array $headers,
        public ?string $expectedRequestClass,
        public bool $transportTimeout,
        public int $delayMicroseconds,
        public ?float $maximumConnectTimeoutSeconds,
        public ?float $maximumRequestTimeoutSeconds,
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('A literal S0.3 response status must be between 100 and 599.');
        }
        if ($expectedRequestClass !== null && ! is_a($expectedRequestClass, Request::class, true)) {
            throw new InvalidArgumentException('A literal S0.3 response may target only a Saloon request class.');
        }
        if ($delayMicroseconds < 0 || $delayMicroseconds > 1_000_000) {
            throw new InvalidArgumentException('A literal S0.3 response delay must remain within the in-memory test bound.');
        }
        foreach ([$maximumConnectTimeoutSeconds, $maximumRequestTimeoutSeconds] as $maximumTimeout) {
            if ($maximumTimeout !== null && (! is_finite($maximumTimeout) || $maximumTimeout <= 0)) {
                throw new InvalidArgumentException('A literal S0.3 response timeout expectation must be finite and positive.');
            }
        }

        $copiedBody = self::copyLiteralValue($body);
        if (! is_array($copiedBody) && ! is_string($copiedBody)) {
            throw new InvalidArgumentException('A literal S0.3 response body must be an array or string.');
        }

        $copiedHeaders = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name)
                || ! is_string($value)
                || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1
                || preg_match('/[\r\n]/', $value) === 1) {
                throw new InvalidArgumentException('A literal S0.3 response contains an invalid header.');
            }

            $copiedHeaders[$name] = $value;
        }

        $this->body = $copiedBody;
        $this->headers = $copiedHeaders;
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @param  array<string, string>  $headers
     * @param  class-string<Request>|null  $expectedRequestClass
     */
    public static function json(
        #[SensitiveParameter] array $body,
        int $status = 200,
        #[SensitiveParameter] array $headers = [],
        ?string $expectedRequestClass = null,
        int $delayMicroseconds = 0,
        ?float $maximumConnectTimeoutSeconds = null,
        ?float $maximumRequestTimeoutSeconds = null,
    ): self {
        return new self(
            $body,
            $status,
            ['Content-Type' => 'application/json', ...$headers],
            $expectedRequestClass,
            false,
            $delayMicroseconds,
            $maximumConnectTimeoutSeconds,
            $maximumRequestTimeoutSeconds,
        );
    }

    /** @param class-string<Request>|null $expectedRequestClass */
    public static function timeout(?string $expectedRequestClass = null): self
    {
        return new self([], 599, ['Content-Type' => 'application/json'], $expectedRequestClass, true, 0, null, null);
    }

    /** @return array<array-key, mixed>|bool|float|int|string|null */
    private static function copyLiteralValue(#[SensitiveParameter] mixed $value): array|bool|float|int|string|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('A literal S0.3 response cannot contain a non-finite number.');
            }

            return $value;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('A literal S0.3 response cannot contain objects, callables or resources.');
        }

        $copy = [];
        foreach ($value as $key => $nested) {
            $copy[$key] = self::copyLiteralValue($nested);
        }

        return $copy;
    }

    /** @return array<array-key, mixed>|string */
    public function body(): array|string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}

final class ProbeLiteralResponseQueue implements Sender
{
    /** @var list<ProbeLiteralResponse> */
    private array $responses;

    private int $consumedResponses = 0;

    private int $dispatchAttempts = 0;

    private function __construct(#[SensitiveParameter] ProbeLiteralResponse ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public static function from(#[SensitiveParameter] ProbeLiteralResponse ...$responses): self
    {
        return new self(...$responses);
    }

    public function getFactoryCollection(): FactoryCollection
    {
        $factory = new HttpFactory;

        return new FactoryCollection(
            requestFactory: $factory,
            uriFactory: $factory,
            streamFactory: $factory,
            responseFactory: $factory,
            multipartBodyFactory: new GuzzleMultipartBodyFactory,
        );
    }

    public function send(#[SensitiveParameter] PendingRequest $pendingRequest): Response
    {
        $this->dispatchAttempts++;
        $literal = array_shift($this->responses)
            ?? throw ProbeTransportException::failure();
        $this->consumedResponses++;
        $request = $pendingRequest->getRequest();
        $expectedRequestClass = $literal->expectedRequestClass;

        if ($expectedRequestClass !== null && ! $request instanceof $expectedRequestClass) {
            throw new RuntimeException('The sealed S0.3 literal response queue received an unexpected request type.');
        }

        $connectTimeout = $pendingRequest->config()->get(RequestOptions::CONNECT_TIMEOUT);
        $requestTimeout = $pendingRequest->config()->get(RequestOptions::TIMEOUT);
        if (($literal->maximumConnectTimeoutSeconds !== null
                && (! is_float($connectTimeout) || $connectTimeout <= 0 || $connectTimeout > $literal->maximumConnectTimeoutSeconds))
            || ($literal->maximumRequestTimeoutSeconds !== null
                && (! is_float($requestTimeout) || $requestTimeout <= 0 || $requestTimeout > $literal->maximumRequestTimeoutSeconds))) {
            throw new RuntimeException('The S0.3 request exceeded its sealed literal timeout expectation.');
        }

        if ($literal->delayMicroseconds > 0) {
            usleep($literal->delayMicroseconds);
        }

        if ($literal->transportTimeout) {
            throw ProbeTransportException::timeout();
        }

        $psrRequest = $pendingRequest->createPsrRequest();
        $factory = new HttpFactory;
        $literalBody = $literal->body();
        $body = is_string($literalBody)
            ? $literalBody
            : json_encode($literalBody, JSON_THROW_ON_ERROR);
        $psrResponse = $factory->createResponse($literal->status)
            ->withBody($factory->createStream($body));
        foreach ($literal->headers() as $name => $value) {
            $psrResponse = $psrResponse->withHeader($name, $value);
        }

        return Response::fromPsrResponse($psrResponse, $pendingRequest, $psrRequest);
    }

    public function sendAsync(#[SensitiveParameter] PendingRequest $pendingRequest): PromiseInterface
    {
        try {
            return new FulfilledPromise($this->send($pendingRequest));
        } catch (Throwable $exception) {
            return new RejectedPromise($exception);
        }
    }

    public function consumedResponses(): int
    {
        return $this->consumedResponses;
    }

    public function dispatchAttempts(): int
    {
        return $this->dispatchAttempts;
    }
}

final class FakturowniaPinnedGuzzleSender extends GuzzleSender
{
    protected function createGuzzleClient(): GuzzleClient
    {
        $this->handlerStack = HandlerStack::create();

        return new GuzzleClient([
            RequestOptions::CRYPTO_METHOD => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            RequestOptions::CONNECT_TIMEOUT => 5.0,
            RequestOptions::TIMEOUT => 30.0,
            RequestOptions::VERIFY => true,
            RequestOptions::PROXY => '',
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HTTP_ERRORS => true,
            RequestOptions::STREAM => false,
            'handler' => $this->handlerStack,
        ]);
    }
}

final class FakturowniaProbeConnector extends Connector
{
    use AcceptsJson;
    use HasTimeout;

    protected string $defaultSender = FakturowniaPinnedGuzzleSender::class;

    protected float $connectTimeout;

    protected float $requestTimeout;

    public function __construct(
        #[SensitiveParameter] private string $baseUrl,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ) {
        ProbeConfiguration::assertProviderRuntimeIsolated();

        $this->connectTimeout = $connectTimeoutMs / 1_000;
        $this->requestTimeout = $requestTimeoutMs / 1_000;
    }

    public function withLiteralResponseQueue(
        #[SensitiveParameter] ProbeLiteralResponseQueue $responseQueue,
    ): self {
        self::assertMiddlewarePipelineIsEmpty($this->middleware());

        if (isset($this->sender) || $this->hasMockClient()) {
            throw new LogicException('The S0.3 connector transport may be selected only once.');
        }

        $this->sender = $responseQueue;

        return $this;
    }

    public function withMockClient(#[SensitiveParameter] MockClient $mockClient): static
    {
        throw new LogicException('A general Saloon MockClient is forbidden for the S0.3 probe.');
    }

    public function send(
        #[SensitiveParameter] Request $request,
        #[SensitiveParameter] ?MockClient $mockClient = null,
        #[SensitiveParameter] ?callable $handleRetry = null,
    ): Response {
        try {
            return parent::send($request, $mockClient, $handleRetry);
        } catch (Throwable $exception) {
            throw ProbeTransportException::fromThrowable($exception);
        }
    }

    public function sendAsync(
        #[SensitiveParameter] Request $request,
        #[SensitiveParameter] ?MockClient $mockClient = null,
    ): PromiseInterface {
        try {
            return parent::sendAsync($request, $mockClient)->then(
                null,
                static function (#[SensitiveParameter] Throwable $exception): never {
                    throw ProbeTransportException::fromThrowable($exception);
                },
            );
        } catch (Throwable $exception) {
            throw ProbeTransportException::fromThrowable($exception);
        }
    }

    public function createPendingRequest(
        #[SensitiveParameter] Request $request,
        #[SensitiveParameter] ?MockClient $mockClient = null,
    ): PendingRequest {
        ProbeConfiguration::assertProviderRuntimeIsolated();
        self::assertMiddlewarePipelineIsEmpty($this->middleware());
        self::assertMiddlewarePipelineIsEmpty($request->middleware());

        if ($mockClient !== null || $this->hasMockClient() || $request->hasMockClient()) {
            throw new LogicException('A general Saloon MockClient is forbidden for the S0.3 probe.');
        }

        return new PendingRequest($this, $request);
    }

    private static function assertMiddlewarePipelineIsEmpty(
        #[SensitiveParameter] MiddlewarePipeline $middleware,
    ): void {
        if ($middleware->getRequestPipeline()->getPipes() !== []
            || $middleware->getResponsePipeline()->getPipes() !== []
            || $middleware->getFatalPipeline()->getPipes() !== []) {
            throw new LogicException('Caller-supplied Saloon middleware is forbidden for the S0.3 probe.');
        }
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** @return array<string, mixed> */
    protected function defaultConfig(): array
    {
        return [
            RequestOptions::CRYPTO_METHOD => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            RequestOptions::VERIFY => true,
            RequestOptions::PROXY => '',
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HTTP_ERRORS => true,
            RequestOptions::STREAM => false,
        ];
    }
}

final class AccountProbeRequest extends Request
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

final class CreateProbeInvoiceRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /** @param array<string, mixed> $invoice */
    public function __construct(
        #[SensitiveParameter] private string $token,
        #[SensitiveParameter] private array $invoice,
    ) {
        ProbeConfiguration::assertPayloadContainsNoForbiddenFields($invoice);
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

final class CreateTimedProbeInvoiceRequest extends Request implements HasBody
{
    use HasJsonBody;
    use HasTimeout;

    protected Method $method = Method::POST;

    protected float $connectTimeout = 5.0;

    protected float $requestTimeout;

    /** @param array<string, mixed> $invoice */
    public function __construct(
        #[SensitiveParameter] private string $token,
        #[SensitiveParameter] private array $invoice,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ) {
        ProbeConfiguration::assertPayloadContainsNoForbiddenFields($invoice);
        $this->connectTimeout = $connectTimeoutMs / 1_000;
        $this->requestTimeout = $requestTimeoutMs / 1_000;
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

final class SearchProbeInvoicesRequest extends Request
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
        return ['api_token' => $this->token, 'period' => 'all', 'per_page' => 100, 'page' => $this->page, 'include_positions' => true, 'oid' => $this->oid];
    }
}

final class ProbeFixtureSanitizer
{
    private string $requestIdDigestKey;

    /** @param list<string> $sensitive */
    public function __construct(#[SensitiveParameter] private array $sensitive)
    {
        $this->requestIdDigestKey = random_bytes(32);
    }

    public function __destruct()
    {
        $this->destroyKeys();
    }

    public function destroyKeys(): void
    {
        $requestIdDigestKey = $this->requestIdDigestKey;
        $this->requestIdDigestKey = '';

        if ($requestIdDigestKey !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($requestIdDigestKey);
        }
    }

    /** @return array<string, mixed> */
    public function response(
        #[SensitiveParameter] Response $response,
        #[SensitiveParameter] string $oid,
    ): array {
        try {
            $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = null;
        }

        $safeBody = $this->safeBody($body, $oid);
        $requestIds = [];

        foreach (['X-Request-Id', 'X-Correlation-Id', 'Traceparent'] as $header) {
            $value = $response->header($header);
            $raw = is_array($value) ? implode(',', $value) : $value;
            $requestIds[strtolower($header)] = [
                'present' => is_string($raw),
                'keyed_digest' => is_string($raw)
                    ? hash_hmac('sha256', $raw, $this->requestIdDigestKey)
                    : null,
            ];
        }

        return [
            'transport' => 'response',
            'http_status' => $response->status(),
            'content_type' => $this->safeContentType($response->header('Content-Type')),
            'request_ids' => $requestIds,
            'body' => $safeBody,
            'normalized_body_sha256' => hash('sha256', json_encode($safeBody, JSON_THROW_ON_ERROR)),
        ];
    }

    public function isSafe(#[SensitiveParameter] string $json): bool
    {
        $leaks = array_filter($this->sensitive, fn (string $value): bool => $value !== '' && str_contains($json, $value));

        return $leaks === []
            && ! preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $json)
            && ! preg_match('/https?:\/\/[^\s"\]]+/i', $json);
    }

    /** @return array<string, mixed> */
    private function safeBody(
        #[SensitiveParameter] mixed $body,
        #[SensitiveParameter] string $oid,
    ): array {
        if (! is_array($body)) {
            return ['type' => get_debug_type($body)];
        }

        $keys = array_map('strval', array_keys($body));
        $safe = ['keys' => array_values(array_intersect(['id', 'oid', 'kind', 'status', 'code', 'error', 'errors', 'message', 'base'], $keys))];

        foreach (['kind', 'status', 'code'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                $value = (string) $body[$key];
                $safe[$key] = preg_match('/^[a-z0-9_.-]{1,64}$/i', $value) ? $this->redact($value, $oid) : '<non-code>';
            }
        }

        $safe['id'] = array_key_exists('id', $body) ? '<document-id>' : null;
        $safe['oid'] = array_key_exists('oid', $body) ? '<probe-oid>' : null;
        $safe['error_fields'] = array_values(array_filter(['error', 'errors', 'message', 'base'], fn (string $key): bool => array_key_exists($key, $body)));
        $safe['duplicate_signals'] = self::duplicateSignals($body);

        return $safe;
    }

    /** @return list<string> */
    public static function duplicateSignals(#[SensitiveParameter] mixed $body): array
    {
        try {
            $json = mb_strtolower(json_encode($body, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return [];
        }

        $signals = str_contains($json, 'oid') ? ['oid'] : [];
        foreach (['unique', 'unikal', 'duplicate', 'duplik', 'already exists', 'już istnieje'] as $signal) {
            if (str_contains($json, $signal)) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    public static function isOidDuplicate(#[SensitiveParameter] mixed $body): bool
    {
        if (! is_array($body)) {
            return false;
        }

        foreach (['error', 'errors'] as $key) {
            $errors = $body[$key] ?? null;
            if (is_array($errors) && array_key_exists('oid', $errors) && count(self::duplicateSignals(['oid' => $errors['oid']])) > 1) {
                return true;
            }
        }

        foreach (['error', 'message', 'base'] as $key) {
            $value = $body[$key] ?? null;
            if (is_scalar($value) && preg_match('/^\s*oid\b/i', (string) $value) && count(self::duplicateSignals($value)) > 1) {
                return true;
            }
        }

        return false;
    }

    private function redact(
        #[SensitiveParameter] string $value,
        #[SensitiveParameter] string $oid,
    ): string {
        $value = str_replace([...$this->sensitive, $oid], '<redacted>', $value);
        $value = preg_replace(['/https?:\/\/\S+/i', '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '/\b\d{6,}\b/'], '<redacted>', $value) ?? '<redacted>';

        return mb_substr($value, 0, 500);
    }

    private function safeContentType(mixed $contentType): ?string
    {
        if (is_array($contentType)) {
            foreach ($contentType as $value) {
                if (! is_string($value)) {
                    return null;
                }
            }

            $contentType = implode(',', $contentType);
        }
        if (! is_string($contentType)) {
            return null;
        }

        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

        return in_array($mime, ['application/json', 'application/problem+json'], true) ? $mime : '<unsupported-content-type>';
    }
}
