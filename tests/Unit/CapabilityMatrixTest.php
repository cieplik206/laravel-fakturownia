<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\ContractTesting\Evidence\Rt3ReadEvidenceContract;
use Cieplik206\Fakturownia\ContractTesting\Evidence\Rt6ArtifactEvidenceContract;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionClaimRequest;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionReceipt;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\FreshClaimGrant;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\LiveEffectDescriptor;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\RecoveredConsumedProof;
use Cieplik206\Fakturownia\Tests\Contract\Support\InvoiceIdentityProbe;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoContractProbe;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoFixtureGuard;
use Cieplik206\Fakturownia\Tests\Contract\Support\LiveEvidenceAttestationGuard;
use Cieplik206\Fakturownia\Tests\Contract\Support\LiveEvidenceClaimStore;
use Cieplik206\Fakturownia\Tests\Contract\Support\LiveEvidenceConsumptionAuthority;
use Cieplik206\Fakturownia\Tests\Contract\Support\VerifiedFreshClaimGrant;
use Cieplik206\Fakturownia\Tests\Contract\Support\VerifiedLiveProviderRun;

/** @return array<string, mixed> */
function loadFakturowniaCapabilityMatrix(): array
{
    $path = dirname(__DIR__, 2).'/docs/capability-matrix.json';
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Could not read the Fakturownia capability matrix.');
    }

    try {
        $matrix = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('The Fakturownia capability matrix is not valid JSON.', previous: $exception);
    }

    if (! is_array($matrix)) {
        throw new RuntimeException('The Fakturownia capability matrix must be an object.');
    }

    return $matrix;
}

/**
 * @param  array<string, mixed>  $matrix
 * @return array<string, array<string, mixed>>
 */
function fakturowniaCapabilitiesById(array $matrix): array
{
    $capabilities = $matrix['capabilities'] ?? null;

    if (! is_array($capabilities) || ! array_is_list($capabilities)) {
        throw new RuntimeException('The Fakturownia capabilities must be a list.');
    }

    $indexed = [];

    foreach ($capabilities as $capability) {
        if (! is_array($capability) || ! is_string($capability['id'] ?? null)) {
            throw new RuntimeException('Every Fakturownia capability must have a string ID.');
        }

        if (isset($indexed[$capability['id']])) {
            throw new RuntimeException("Duplicate Fakturownia capability ID: {$capability['id']}.");
        }

        $indexed[$capability['id']] = $capability;
    }

    return $indexed;
}

/**
 * @param  array<string, mixed>  $capability
 * @return array<string, mixed>
 */
function fakturowniaCriticalCapabilityContract(array $capability): array
{
    return [
        'milestone' => $capability['milestone'] ?? null,
        'classification' => $capability['classification'] ?? null,
        'transport' => $capability['transport'] ?? null,
        'semantic_writes' => $capability['semantic_writes'] ?? null,
        'identity' => $capability['identity'] ?? null,
        'recovery' => $capability['recovery'] ?? null,
        'policy' => $capability['policy'] ?? null,
        'live_evidence_status' => $capability['live_evidence']['status'] ?? null,
        'blocks_vat_pilot' => $capability['blocks_vat_pilot'] ?? null,
    ];
}

/** @return array<string, string> */
function fakturowniaEvidenceContractAllowlist(): array
{
    return [
        'fakturownia-invoice-identity-s0.3-v1' => 'invoice_identity_fixture_guard',
        'fakturownia-ksef-demo-s0.4-v1' => 'ksef_demo_fixture_guard',
    ];
}

function resolveFakturowniaEvidencePath(string $repositoryRoot, string $relativePath): ?string
{
    if ($relativePath === ''
        || str_starts_with($relativePath, '/')
        || str_contains($relativePath, '\\')
        || preg_match('/[\x00-\x1F\x7F]/', $relativePath) === 1
        || is_link($repositoryRoot)) {
        return null;
    }

    $segments = explode('/', $relativePath);

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return null;
        }
    }

    if (implode('/', $segments) !== $relativePath) {
        return null;
    }

    $root = realpath($repositoryRoot);

    if ($root === false || ! is_dir($root)) {
        return null;
    }

    $candidate = $root;

    foreach ($segments as $segment) {
        $candidate .= DIRECTORY_SEPARATOR.$segment;

        if (is_link($candidate)) {
            return null;
        }
    }

    $resolved = realpath($candidate);

    if ($resolved === false
        || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)
        || ! is_file($resolved)) {
        return null;
    }

    $stat = lstat($resolved);

    if ($stat === false || $stat['nlink'] !== 1) {
        return null;
    }

    return $resolved;
}

/** @return array{path: string, contents: string}|null */
function readFakturowniaEvidenceFile(string $repositoryRoot, string $relativePath): ?array
{
    $path = resolveFakturowniaEvidencePath($repositoryRoot, $relativePath);

    if ($path === null) {
        return null;
    }

    $pathStat = lstat($path);
    $handle = fopen($path, 'rb');

    if ($pathStat === false || $handle === false) {
        return null;
    }

    try {
        $openedStat = fstat($handle);
        $contents = stream_get_contents($handle);
        $finishedStat = fstat($handle);
        $currentPathStat = lstat($path);
    } finally {
        fclose($handle);
    }

    if ($openedStat === false
        || $contents === false
        || $finishedStat === false
        || $currentPathStat === false
        || $openedStat['nlink'] !== 1
        || $finishedStat['nlink'] !== 1
        || $currentPathStat['nlink'] !== 1
        || $pathStat['dev'] !== $openedStat['dev']
        || $pathStat['ino'] !== $openedStat['ino']
        || $openedStat['dev'] !== $finishedStat['dev']
        || $openedStat['ino'] !== $finishedStat['ino']
        || $openedStat['size'] !== $finishedStat['size']
        || $openedStat['dev'] !== $currentPathStat['dev']
        || $openedStat['ino'] !== $currentPathStat['ino']
        || $openedStat['size'] !== strlen($contents)) {
        return null;
    }

    return ['path' => $path, 'contents' => $contents];
}

function isFakturowniaContractEvidencePath(string $relativePath): bool
{
    $segments = explode('/', $relativePath);

    return array_slice($segments, 0, 3) === ['tests', 'Fixtures', 'Contract']
        && count($segments) === 4
        && ! str_contains($relativePath, '*')
        && ! str_contains($relativePath, '?');
}

/**
 * @param  array<string, mixed>  $value
 * @param  list<string>  $expectedKeys
 */
function fakturowniaHasExactKeys(array $value, array $expectedKeys): bool
{
    $keys = array_keys($value);
    sort($keys);
    sort($expectedKeys);

    return $keys === $expectedKeys;
}

function parseFakturowniaStrictUtcDate(mixed $value): ?DateTimeImmutable
{
    if (! is_string($value)
        || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $value) !== 1) {
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

/**
 * @param  array<string, mixed>  $artifact
 * @param  array<string, mixed>  $fixture
 * @param  array<string, string>  $trustedSigners
 * @param  array<string, mixed>  $attestationPolicy
 * @param  array<string, string>  $trustedConsumptionAuthorities
 */
function fakturowniaArtifactAttestationIsValid(
    string $repositoryRoot,
    array $artifact,
    string $fixtureSha256,
    array $fixture,
    array $trustedSigners,
    array $attestationPolicy,
    DateTimeImmutable $now,
    array $trustedConsumptionAuthorities = [],
): bool {
    $attestation = $artifact['attestation'] ?? null;
    $authorizationArtifacts = $artifact['authorizations'] ?? null;

    if (! is_array($attestation)
        || ! fakturowniaHasExactKeys($attestation, ['path', 'sha256'])
        || ! is_string($attestation['path'] ?? null)
        || ! isFakturowniaContractEvidencePath($attestation['path'])
        || ! str_ends_with($attestation['path'], '.attestation.json')
        || ! is_string($attestation['sha256'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/', $attestation['sha256']) !== 1
        || ! is_array($authorizationArtifacts)
        || ! array_is_list($authorizationArtifacts)
        || $authorizationArtifacts === []) {
        return false;
    }

    $contract = $artifact['contract'] ?? null;
    $expectedProfiles = match ($contract) {
        'fakturownia-invoice-identity-s0.3-v1' => ['invoice_identity'],
        'fakturownia-ksef-demo-s0.4-v1' => ['explicit_block', 'explicit_persist', 'auto_block', 'auto_persist'],
        default => null,
    };

    if ($expectedProfiles === null) {
        return false;
    }

    $signedAuthorizations = [];
    $expectedAuthorizationReferences = [];
    $actualProfiles = [];

    foreach ($authorizationArtifacts as $authorizationArtifact) {
        if (! is_array($authorizationArtifact)
            || ! fakturowniaHasExactKeys($authorizationArtifact, ['profile', 'path', 'sha256'])
            || ! is_string($authorizationArtifact['profile'] ?? null)
            || ! is_string($authorizationArtifact['path'] ?? null)
            || ! isFakturowniaContractEvidencePath($authorizationArtifact['path'])
            || ! str_ends_with($authorizationArtifact['path'], '.authorization-'.$authorizationArtifact['profile'].'.json')
            || ! is_string($authorizationArtifact['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $authorizationArtifact['sha256']) !== 1
            || in_array($authorizationArtifact['profile'], $actualProfiles, true)) {
            return false;
        }

        $authorizationFile = readFakturowniaEvidenceFile($repositoryRoot, $authorizationArtifact['path']);

        if ($authorizationFile === null
            || ! hash_equals($authorizationArtifact['sha256'], hash('sha256', $authorizationFile['contents']))) {
            return false;
        }

        try {
            $signedAuthorization = json_decode($authorizationFile['contents'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($signedAuthorization)
            || ! is_array($signedAuthorization['envelope'] ?? null)
            || ($signedAuthorization['envelope']['target']['profile'] ?? null) !== $authorizationArtifact['profile']) {
            return false;
        }

        try {
            if (! hash_equals(
                $authorizationFile['contents'],
                LiveEvidenceAttestationGuard::canonicalJson($signedAuthorization),
            )) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $challenge = $signedAuthorization['envelope']['challenge'] ?? null;

        if (! is_string($challenge)) {
            return false;
        }

        $actualProfiles[] = $authorizationArtifact['profile'];
        $signedAuthorizations[] = $signedAuthorization;
        $expectedAuthorizationReferences[] = [
            'profile' => $authorizationArtifact['profile'],
            'challenge' => $challenge,
            'sha256' => $authorizationArtifact['sha256'],
        ];
    }

    if ($actualProfiles !== $expectedProfiles) {
        return false;
    }

    $attestationFile = readFakturowniaEvidenceFile($repositoryRoot, $attestation['path']);

    if ($attestationFile === null
        || ! hash_equals($attestation['sha256'], hash('sha256', $attestationFile['contents']))) {
        return false;
    }

    try {
        $signedDocument = json_decode($attestationFile['contents'], true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }

    if (! is_array($signedDocument)
        || ! is_array($signedDocument['envelope'] ?? null)
        || ! is_array($signedDocument['envelope']['run'] ?? null)) {
        return false;
    }

    try {
        if (! hash_equals($attestationFile['contents'], LiveEvidenceAttestationGuard::canonicalJson($signedDocument))) {
            return false;
        }
    } catch (Throwable) {
        return false;
    }

    $rawRun = $signedDocument['envelope']['run'];
    $startedAt = parseFakturowniaStrictUtcDate($rawRun['started_at'] ?? null);
    $finishedAt = parseFakturowniaStrictUtcDate($rawRun['finished_at'] ?? null);
    $issuedAt = parseFakturowniaStrictUtcDate($signedDocument['envelope']['issued_at'] ?? null);
    $maximumAuthorizationTtlSeconds = $attestationPolicy['maximum_authorization_ttl_seconds'] ?? null;
    $maximumEvidenceTtlSeconds = $attestationPolicy['maximum_evidence_ttl_seconds'] ?? null;
    $maximumSigningDelaySeconds = $attestationPolicy['maximum_signing_delay_seconds'] ?? null;
    $maxRunSeconds = $attestationPolicy['max_run_seconds'] ?? null;

    if ($startedAt === null
        || $finishedAt === null
        || $issuedAt === null
        || ! is_int($maximumAuthorizationTtlSeconds)
        || ! is_int($maximumEvidenceTtlSeconds)
        || ! is_int($maximumSigningDelaySeconds)
        || ! is_int($maxRunSeconds)) {
        return false;
    }

    try {
        $envelope = LiveEvidenceAttestationGuard::assertHistoricalEvidence(
            $signedDocument,
            $signedAuthorizations,
            $fixture,
            $repositoryRoot,
            $startedAt,
            $finishedAt,
            $maxRunSeconds,
            $maximumAuthorizationTtlSeconds,
            $maximumEvidenceTtlSeconds,
            $maximumSigningDelaySeconds,
            $trustedSigners,
            $trustedConsumptionAuthorities,
        );
    } catch (Throwable) {
        return false;
    }

    if (! fakturowniaHasExactKeys($envelope, [
        'contract',
        'version',
        'algorithm',
        'signer_id',
        'issued_at',
        'expires_at',
        'evidence',
        'probe',
        'run',
        'consumption',
        'authorizations',
        'commitments',
        'origins',
    ])) {
        return false;
    }

    $evidence = $envelope['evidence'] ?? null;
    $probe = $envelope['probe'] ?? null;
    $run = $envelope['run'] ?? null;
    $commitments = $envelope['commitments'] ?? null;
    $origins = $envelope['origins'] ?? null;
    $contract = $artifact['contract'] ?? null;
    $fixturePath = $artifact['path'] ?? null;

    if (! is_array($evidence)
        || ! fakturowniaHasExactKeys($evidence, ['contract', 'fixture_path', 'fixture_sha256'])
        || ($evidence['contract'] ?? null) !== $contract
        || ($evidence['fixture_path'] ?? null) !== $fixturePath
        || ($evidence['fixture_sha256'] ?? null) !== $fixtureSha256
        || ! is_array($probe)
        || ! fakturowniaHasExactKeys($probe, ['repository_commit', 'code_sha256', 'archived_harness'])
        || ! is_string($probe['repository_commit'] ?? null)
        || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $probe['repository_commit']) !== 1
        || ! is_string($probe['code_sha256'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/', $probe['code_sha256']) !== 1
        || ! is_array($probe['archived_harness'] ?? null)
        || ! is_array($run)
        || ! fakturowniaHasExactKeys($run, ['started_at', 'finished_at', 'environment', 'launch_manifest_sha256'])
        || ! is_string($run['environment'] ?? null)
        || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $run['environment']) !== 1
        || ! is_string($run['launch_manifest_sha256'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/', $run['launch_manifest_sha256']) !== 1
        || ! is_array($envelope['authorizations'] ?? null)
        || ! hash_equals(
            LiveEvidenceAttestationGuard::canonicalJson(['value' => $envelope['authorizations']]),
            LiveEvidenceAttestationGuard::canonicalJson(['value' => $expectedAuthorizationReferences]),
        )
        || ! is_array($commitments)
        || ! hash_equals(
            LiveEvidenceAttestationGuard::canonicalJson($commitments),
            LiveEvidenceAttestationGuard::canonicalJson(
                LiveEvidenceAttestationGuard::evidenceCommitments($signedAuthorizations, $fixture, $contract),
            ),
        )
        || ! is_array($origins)
        || ! fakturowniaHasExactKeys($origins, [
            'contract',
            'version',
            'scheme',
            'authority_receipt_sha256',
            'claim_request_sha256',
            'provider_run_sha256',
            'launch_manifest_sha256',
        ])
        || ($origins['contract'] ?? null) !== LiveEvidenceAttestationGuard::LiveRuntimeOriginsContract
        || ($origins['version'] ?? null) !== LiveEvidenceAttestationGuard::Version
        || ($origins['scheme'] ?? null) !== LiveEvidenceAttestationGuard::LiveRuntimeOriginsScheme) {
        return false;
    }

    try {
        $authorityReceipt = ConsumptionReceipt::fromArray($envelope['consumption']['authority_receipt']);
        $expectedProviderRunSha256 = hash('sha256', LiveEvidenceAttestationGuard::canonicalJson([
            'contract' => 'cieplik206.fakturownia.live-provider-run-binding',
            'version' => LiveEvidenceAttestationGuard::Version,
            'evidence_contract' => $contract,
            'started_at' => $run['started_at'],
            'finished_at' => $run['finished_at'],
            'environment' => $run['environment'],
            'launch_manifest_sha256' => $run['launch_manifest_sha256'],
            'fixture_sha256' => $fixtureSha256,
        ]));
    } catch (Throwable) {
        return false;
    }

    if (! is_string($origins['authority_receipt_sha256'] ?? null)
        || ! hash_equals(
            $origins['authority_receipt_sha256'],
            LiveEvidenceAttestationGuard::signedDocumentSha256($envelope['consumption']['authority_receipt']),
        )
        || ! is_string($origins['claim_request_sha256'] ?? null)
        || ! hash_equals($origins['claim_request_sha256'], $authorityReceipt->envelope->claimRequest->sha256())
        || ! is_string($origins['provider_run_sha256'] ?? null)
        || ! hash_equals($origins['provider_run_sha256'], $expectedProviderRunSha256)
        || ! is_string($origins['launch_manifest_sha256'] ?? null)
        || ! hash_equals($origins['launch_manifest_sha256'], $run['launch_manifest_sha256'])
        || ! hash_equals(
            $origins['launch_manifest_sha256'],
            $authorityReceipt->envelope->claimRequest->harness['launch_manifest_sha256'],
        )) {
        return false;
    }

    $futureSkewSeconds = $attestationPolicy['future_skew_seconds'] ?? null;

    if (! is_int($futureSkewSeconds)) {
        return false;
    }

    $nowMicroseconds = ((int) $now->format('U') * 1_000_000) + (int) $now->format('u');
    $futureBoundary = $nowMicroseconds + ($futureSkewSeconds * 1_000_000);
    $finishedMicroseconds = ((int) $finishedAt->format('U') * 1_000_000) + (int) $finishedAt->format('u');
    $issuedMicroseconds = ((int) $issuedAt->format('U') * 1_000_000) + (int) $issuedAt->format('u');

    return $finishedMicroseconds <= $futureBoundary
        && $issuedMicroseconds <= $futureBoundary;
}

/**
 * @param  array<string, mixed>  $artifact
 * @param  array<string, string>  $allowlist
 * @param  array<string, string>  $trustedSigners
 * @param  array<string, mixed>  $attestationPolicy
 * @param  array<string, string>  $trustedConsumptionAuthorities
 * @return array<string, mixed>|null
 */
function validatedFakturowniaEvidenceArtifact(
    string $repositoryRoot,
    array $artifact,
    array $allowlist,
    array $trustedSigners,
    array $attestationPolicy,
    DateTimeImmutable $now,
    array $trustedConsumptionAuthorities = [],
): ?array {
    if (! fakturowniaHasExactKeys($artifact, ['contract', 'path', 'sha256', 'authorizations', 'attestation'])) {
        return null;
    }

    $contract = $artifact['contract'] ?? null;
    $relativePath = $artifact['path'] ?? null;
    $expectedSha256 = $artifact['sha256'] ?? null;

    if (! is_string($contract)
        || ! array_key_exists($contract, $allowlist)
        || ! is_string($relativePath)
        || ! isFakturowniaContractEvidencePath($relativePath)
        || ! is_string($expectedSha256)
        || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
        return null;
    }

    $fixtureFile = readFakturowniaEvidenceFile($repositoryRoot, $relativePath);

    if ($fixtureFile === null
        || ! hash_equals($expectedSha256, hash('sha256', $fixtureFile['contents']))) {
        return null;
    }

    try {
        $fixture = json_decode($fixtureFile['contents'], true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    if (! is_array($fixture)) {
        return null;
    }

    if (! fakturowniaArtifactAttestationIsValid(
        $repositoryRoot,
        $artifact,
        $expectedSha256,
        $fixture,
        $trustedSigners,
        $attestationPolicy,
        $now,
        $trustedConsumptionAuthorities,
    )) {
        return null;
    }

    try {
        $valid = match ($contract) {
            'fakturownia-invoice-identity-s0.3-v1' => InvoiceIdentityProbe::fixtureEvidenceIsValid($fixture),
            'fakturownia-ksef-demo-s0.4-v1' => validateFakturowniaKsefDemoFixture($fixture),
            default => false,
        };
    } catch (Throwable) {
        return null;
    }

    return $valid ? $fixture : null;
}

/** @param array<string, mixed> $fixture */
function validateFakturowniaKsefDemoFixture(array $fixture): bool
{
    class_exists(KsefDemoContractProbe::class);

    if (! class_exists(KsefDemoFixtureGuard::class)) {
        return false;
    }

    try {
        KsefDemoFixtureGuard::assertSafe($fixture, []);
    } catch (Throwable) {
        return false;
    }

    return true;
}

/**
 * @param  array<string, mixed>  $evidence
 * @param  array<string, string>  $allowlist
 * @param  array<string, string>  $trustedSigners
 * @param  array<string, mixed>  $attestationPolicy
 * @param  array<string, string>  $trustedConsumptionAuthorities
 */
function fakturowniaPassedEvidenceIsValid(
    string $repositoryRoot,
    string $capabilityId,
    array $evidence,
    array $allowlist,
    array $trustedSigners,
    array $attestationPolicy,
    DateTimeImmutable $now,
    array $trustedConsumptionAuthorities = [],
): bool {
    if (! fakturowniaHasExactKeys($evidence, ['required', 'status', 'requirements', 'artifacts'])
        || ($evidence['required'] ?? null) !== true
        || ($evidence['status'] ?? null) !== 'passed'
        || ! is_array($evidence['artifacts'] ?? null)
        || $evidence['artifacts'] === []) {
        return false;
    }

    $expectedContract = match ($capabilityId) {
        'invoice.vat.issue' => 'fakturownia-invoice-identity-s0.3-v1',
        'invoice.ksef.ensure_accepted', 'invoice.pdf.ksef_revision.observe' => 'fakturownia-ksef-demo-s0.4-v1',
        default => null,
    };

    if ($expectedContract === null) {
        return false;
    }

    $identityEvidence = [];

    foreach ($evidence['artifacts'] as $artifact) {
        if (! is_array($artifact) || ($artifact['contract'] ?? null) !== $expectedContract) {
            return false;
        }

        $fixture = validatedFakturowniaEvidenceArtifact(
            $repositoryRoot,
            $artifact,
            $allowlist,
            $trustedSigners,
            $attestationPolicy,
            $now,
            $trustedConsumptionAuthorities,
        );

        if ($fixture === null) {
            return false;
        }

        if ($expectedContract !== 'fakturownia-invoice-identity-s0.3-v1') {
            continue;
        }

        $environment = $fixture['environment'] ?? null;
        $fixtureEvidence = $fixture['vat_fixture_evidence'] ?? null;

        if (! is_string($environment)
            || ! is_array($fixtureEvidence)
            || isset($identityEvidence[$environment])) {
            return false;
        }

        $identityEvidence[$environment] = $fixtureEvidence;
    }

    if ($expectedContract === 'fakturownia-ksef-demo-s0.4-v1') {
        return count($evidence['artifacts']) === 1;
    }

    $identityEnvironments = array_keys($identityEvidence);
    sort($identityEnvironments);

    if (count($evidence['artifacts']) !== 2
        || $identityEnvironments !== ['demo_pl', 'demo_regional']) {
        return false;
    }

    $aggregate = InvoiceIdentityProbe::aggregateEnvironmentEvidence($identityEvidence);

    return ($aggregate['complete'] ?? false) === true
        && ($aggregate['safe'] ?? false) === true;
}

/**
 * @param  array<string, array<string, mixed>>  $evidenceByCapability
 * @param  array<string, string>  $allowlist
 * @param  array<string, string>  $trustedSigners
 * @param  array<string, mixed>  $attestationPolicy
 * @param  array<string, string>  $trustedConsumptionAuthorities
 */
function fakturowniaPassedEvidenceSetIsValid(
    string $repositoryRoot,
    array $evidenceByCapability,
    array $allowlist,
    array $trustedSigners,
    array $attestationPolicy,
    DateTimeImmutable $now,
    array $trustedConsumptionAuthorities = [],
): bool {
    $claimCursors = [];
    $runIds = [];
    $claimNonces = [];

    foreach ($evidenceByCapability as $capabilityId => $evidence) {
        if (! fakturowniaPassedEvidenceIsValid(
            $repositoryRoot,
            $capabilityId,
            $evidence,
            $allowlist,
            $trustedSigners,
            $attestationPolicy,
            $now,
            $trustedConsumptionAuthorities,
        )) {
            return false;
        }

        foreach ($evidence['artifacts'] as $artifact) {
            $attestationPath = $artifact['attestation']['path'] ?? null;
            $attestationFile = is_string($attestationPath)
                ? readFakturowniaEvidenceFile($repositoryRoot, $attestationPath)
                : null;

            try {
                $signedEvidence = $attestationFile === null
                    ? null
                    : json_decode($attestationFile['contents'], true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return false;
            }

            $receipt = is_array($signedEvidence)
                ? ($signedEvidence['envelope']['consumption']['authority_receipt']['envelope'] ?? null)
                : null;
            $cursor = is_array($receipt) ? ($receipt['claim_cursor'] ?? null) : null;
            $request = is_array($receipt) ? ($receipt['claim_request'] ?? null) : null;
            $runId = is_array($request) ? ($request['run_id'] ?? null) : null;
            $claimNonce = is_array($request) ? ($request['claim_nonce'] ?? null) : null;

            if (! is_array($cursor)
                || ! is_string($cursor['store_id'] ?? null)
                || ! is_string($cursor['sequence'] ?? null)
                || ! is_string($runId)
                || ! is_string($claimNonce)) {
                return false;
            }

            $cursorKey = $cursor['store_id'].':'.$cursor['sequence'];

            if (isset($claimCursors[$cursorKey])
                || isset($runIds[$runId])
                || isset($claimNonces[$claimNonce])) {
                return false;
            }

            $claimCursors[$cursorKey] = true;
            $runIds[$runId] = true;
            $claimNonces[$claimNonce] = true;
        }
    }

    return true;
}

function fakturowniaIsUniqueNonEmptyStringList(mixed $value, bool $allowEmpty = false): bool
{
    if (! is_array($value) || ! array_is_list($value)) {
        return false;
    }

    if ($value === []) {
        return $allowEmpty;
    }

    foreach ($value as $item) {
        if (! is_string($item) || trim($item) === '') {
            return false;
        }
    }

    return count($value) === count(array_unique($value));
}

/** @return list<string> */
function fakturowniaSensitiveMatrixPaths(mixed $value, string $path = '$'): array
{
    $findings = [];

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $keyString = (string) $key;
            $itemPath = $path.'.'.$keyString;

            if (is_string($key)
                && $key !== 'authorization_contract'
                && preg_match('/(?:^|_)(?:api_?token|access_?token|refresh_?token|client_?secret|private_?key|password|bearer|cookie|credential|base_?url|tenant_?url|account_?id|host)(?:$|_)/i', $key) === 1
                    || is_string($key) && strtolower($key) === 'authorization') {
                $findings[] = $itemPath;
            }

            array_push($findings, ...fakturowniaSensitiveMatrixPaths($item, $itemPath));
        }

        return $findings;
    }

    if (! is_string($value)) {
        return $findings;
    }

    $allowsDigest = (str_ends_with($path, '.sha256') || str_ends_with($path, '_sha256'))
        && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    $hasUrlOrEmail = preg_match('/https?:\/\/|[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value) === 1;
    $hasKnownSecretPrefix = preg_match('/(?:sk|pk)_(?:live|test)_[A-Za-z0-9_-]{16,}|Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', $value) === 1;
    $hasJwt = preg_match('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', $value) === 1;
    $hasOpaqueToken = false;

    if (! $allowsDigest && preg_match_all('/[A-Za-z0-9+\/_=-]{32,}/', $value, $tokens) > 0) {
        foreach ($tokens[0] as $token) {
            $hasMixedSecretAlphabet = preg_match('/[a-z]/', $token) === 1
                && preg_match('/[A-Z]/', $token) === 1
                && preg_match('/\d/', $token) === 1;
            $hasUnlabelledDigest = preg_match('/^[a-f0-9]{32,}$/', $token) === 1;

            if ($hasMixedSecretAlphabet || $hasUnlabelledDigest) {
                $hasOpaqueToken = true;

                break;
            }
        }
    }

    if ($hasUrlOrEmail
        || $hasKnownSecretPrefix
        || $hasJwt
        || $hasOpaqueToken
        || str_contains(strtolower($value), 'fakturownia.pl')
        || str_contains(strtolower($value), 'invoiceocean.com')) {
        $findings[] = $path;
    }

    return $findings;
}

/** @param list<string> $arguments */
function runFakturowniaTestGit(string $repositoryRoot, array $arguments): string
{
    $process = proc_open(
        ['/usr/bin/git', '-C', $repositoryRoot, ...$arguments],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'LC_ALL' => 'C'],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Git for the capability protocol test.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0 || $stdout === false || $stderr === false) {
        throw new RuntimeException('Git failed for the capability protocol test: '.$stderr);
    }

    return rtrim($stdout, "\r\n");
}

function removeFakturowniaTestTree(string $root): void
{
    if (! is_dir($root) || is_link($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir() && ! $entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }

    rmdir($root);
}

function writeFakturowniaTestFile(string $root, string $relativePath, string $contents, int $permissions = 0644): void
{
    $path = $root.'/'.$relativePath;
    $directory = dirname($path);

    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create a capability protocol test directory.');
    }

    if (file_put_contents($path, $contents) !== strlen($contents) || ! chmod($path, $permissions)) {
        throw new RuntimeException('Could not write a capability protocol test file.');
    }
}

/**
 * @param  array<string, mixed>  $envelope
 * @return array<string, mixed>
 */
function signFakturowniaTestEnvelope(array $envelope, string $secretKey): array
{
    if ($secretKey === '') {
        throw new InvalidArgumentException('The test signing secret key cannot be empty.');
    }

    return [
        'envelope' => $envelope,
        'signature' => base64_encode(sodium_crypto_sign_detached(
            LiveEvidenceAttestationGuard::canonicalJson($envelope),
            $secretKey,
        )),
    ];
}

/** @return array<string, mixed> */
function fakturowniaS04ProfileEvidence(string $key): array
{
    $explicit = str_starts_with($key, 'explicit');
    $block = str_ends_with($key, 'block');
    $hmacSha256 = hash('sha256', 'pdf-'.$key);

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
                        'gov_id_hmac_sha256' => hash('sha256', 'invalid-gov-'.$key),
                        'validation_error_category' => 'expected_validation_leaf_gov_error',
                    ],
                    [
                        'status' => 'demo_ok',
                        'gov_id_hmac_sha256' => hash('sha256', 'invalid-gov-'.$key),
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
            'before' => ['mime' => 'application/pdf', 'size' => 1_024, 'hmac_sha256' => $hmacSha256],
            'after' => ['mime' => 'application/pdf', 'size' => 1_024, 'hmac_sha256' => $hmacSha256],
            'equal' => true,
        ],
    ];
}

/** @return array<string, mixed> */
function fakturowniaS04Fixture(
    DateTimeImmutable $runStartedAt,
    DateTimeImmutable $runFinishedAt,
    string $launchManifestSha256,
): array {
    $profiles = [];

    foreach (['explicit_block', 'explicit_persist', 'auto_block', 'auto_persist'] as $key) {
        $profiles[$key] = fakturowniaS04ProfileEvidence($key);
    }

    return [
        'contract' => 'fakturownia-ksef-demo-s0.4-v1',
        'run' => [
            'started_at' => $runStartedAt->format('Y-m-d\TH:i:s.u\Z'),
            'finished_at' => $runFinishedAt->format('Y-m-d\TH:i:s.u\Z'),
            'environment' => 'ksef_demo',
            'launch_manifest_sha256' => $launchManifestSha256,
        ],
        'probe_limits' => [
            'poll_window_ms' => 30_000,
            'poll_interval_ms' => 500,
            'max_search_pages' => 10,
            'pre_send_observation_window_ms' => 1_000,
            'visibility_window_ms' => 10_000,
            'visibility_poll_interval_ms' => 250,
            'connect_timeout_ms' => 5_000,
            'request_timeout_ms' => 30_000,
            'minimum_pdf_size_bytes' => 1_024,
        ],
        'profiles' => $profiles,
        'capability_0_2' => KsefDemoContractProbe::resolveCapabilityPolicy($profiles),
    ];
}

it('publishes the complete versioned fail-closed capability contract', function (): void {
    $matrix = loadFakturowniaCapabilityMatrix();
    $capabilities = fakturowniaCapabilitiesById($matrix);
    $requiredIds = [
        'invoice.vat.issue',
        'invoice.ksef.ensure_accepted',
        'invoice.pdf.ksef_revision.observe',
        'invoice.pdf.download',
        'invoice.correction.issue',
        'invoice.read.list',
        'invoice.read.get',
        'client.read.list',
        'client.read.get',
        'product.read.list',
        'product.read.get',
        'invoice.pdf.stream',
        'invoice.attachments.zip.stream',
        'invoice.ksef.xml.stream',
        'invoice.ksef.upo.stream',
        'invoice.proforma.issue',
        'cost.invoice.read',
        'cost.invoice.create',
        'cost.invoice.status.change',
        'cost.invoice.delete',
        'invoice.attachment.credentials.get',
        'invoice.attachment.binary.upload',
        'invoice.attachment.finalize',
        'payment.read.list',
        'payment.read.get',
        'payment.sync',
        'payment.create',
        'payment.update',
        'payment.delete',
        'invoice.ksef.xml.download',
        'invoice.ksef.upo.download',
        'webhook.invoice.receive',
        'master_data.client.sync',
        'master_data.product.sync',
        'account.invoice.read',
        'invoice.update',
        'invoice.cancel',
        'invoice.status.change',
        'invoice.email.send',
    ];

    expect(array_keys($matrix))->toBe(['contract', 'matrix_version', 'default_policy', 'evidence_contracts', 'attestation_policy', 'spi_constraints', 'capabilities'])
        ->and($matrix['contract'])->toBe('cieplik206.fakturownia.capability-matrix')
        ->and($matrix['matrix_version'])->toBe('0.1')
        ->and(array_keys($capabilities))->toContain(...$requiredIds)
        ->and($matrix['default_policy'])->toBe([
            'unknown_capability' => 'deny',
            'unknown_classification' => 'deny',
            'public_api_gate' => 'required_live_evidence_must_be_passed',
            'passed_evidence_requires' => ['allowlisted_contract', 'canonical_repository_relative_path', 'sha256', 'semantic_fixture_validation', 'trusted_ed25519_attestation', 'preautoload_verified_read_only_snapshot', 'supervised_launch_manifest_binding', 'broker_owned_atomic_authorization_consumption', 'external_atomic_consumption_receipt', 'signed_brokered_effect_execution_receipts', 'archived_harness_snapshot', 'globally_unique_claim_cursor_run_id_nonce'],
            'deferred_capability' => 'disabled',
            'vat_pilot_blocking_rule' => 'only_explicit_blocks_vat_pilot_flags',
        ])
        ->and($matrix['evidence_contracts'])->toBe(fakturowniaEvidenceContractAllowlist())
        ->and($matrix['attestation_policy'])->toBe([
            'authorization_contract' => LiveEvidenceAttestationGuard::AuthorizationContract,
            'evidence_payload_contract' => LiveEvidenceAttestationGuard::EvidencePayloadContract,
            'evidence_contract' => LiveEvidenceAttestationGuard::EvidenceContract,
            'consumption_claim_request_contract' => LiveEvidenceAttestationGuard::ConsumptionClaimRequestContract,
            'consumption_receipt_contract' => LiveEvidenceAttestationGuard::ConsumptionReceiptContract,
            'consumption_replay_policy' => LiveEvidenceAttestationGuard::ConsumptionReplayPolicy,
            'fresh_consumption_disposition' => LiveEvidenceAttestationGuard::FreshConsumptionDisposition,
            'recovered_consumption_disposition' => LiveEvidenceAttestationGuard::RecoveredConsumptionDisposition,
            'version' => LiveEvidenceAttestationGuard::Version,
            'algorithm' => LiveEvidenceAttestationGuard::Algorithm,
            'unsigned_sidecar_suffix' => '.attestation.unsigned.json',
            'signed_sidecar_suffix' => '.attestation.json',
            'preautoload_trust_root' => [
                'launcher_source_path' => 'bin/fakturownia-live-evidence-launcher.php',
                'launcher_source_sha256' => 'e0fe53eb581157b73c9fdf588631e1a2a7486a05305312da45157fd52dbe0951',
                'policy_contract' => 'cieplik206.fakturownia.preauthenticated-policy',
                'manifest_contract' => 'cieplik206.fakturownia.preauthenticated-snapshot',
                'version' => 1,
                'production_launcher_path' => '/usr/local/libexec/cieplik206/fakturownia-live-evidence-launcher.php',
                'policy_path' => '/etc/cieplik206/fakturownia-live-evidence/preautoload-policy.json',
                'snapshot_root' => '/var/lib/cieplik206/fakturownia-live-evidence/snapshots',
                'launch_manifest_handoff' => 'root_authenticated_af_unix_fd6',
                'native_supervisor_status' => 'not_implemented_requires_explicit_user_approval',
                'provider_credentials_in_php' => 'forbidden',
                'status' => 'fail_closed_before_manifest_parse_or_secret_open',
            ],
            'brokered_effect_execution' => [
                'effect_descriptor_contract' => LiveEffectDescriptor::Contract,
                'effect_descriptor_version' => LiveEffectDescriptor::Version,
                'effect_descriptor_operations' => [
                    ['evidence_contract' => 'fakturownia-invoice-identity-s0.3-v1', 'profiles' => ['invoice_identity'], 'capability' => 'invoice.vat.issue', 'semantic_effect' => 'invoice_create', 'http_method' => 'POST', 'endpoint_template' => '/invoices.json', 'request_body_policy' => 'required_non_empty', 'maximum_effect_sequence' => 11],
                    ['evidence_contract' => 'fakturownia-ksef-demo-s0.4-v1', 'profiles' => ['explicit_block', 'explicit_persist', 'auto_block', 'auto_persist'], 'capability' => 'contract_probe.invoice.fixture.issue', 'semantic_effect' => 'probe_fixture_invoice_create', 'http_method' => 'POST', 'endpoint_template' => '/invoices.json', 'request_body_policy' => 'required_non_empty', 'maximum_effect_sequence' => 8],
                    ['evidence_contract' => 'fakturownia-ksef-demo-s0.4-v1', 'profiles' => ['explicit_block', 'explicit_persist'], 'capability' => 'invoice.ksef.ensure_accepted', 'semantic_effect' => 'ksef_explicit_submit', 'http_method' => 'GET', 'endpoint_template' => '/invoices/{invoice_id}.json?send_to_ksef=yes', 'request_body_policy' => 'must_be_empty', 'maximum_effect_sequence' => 8],
                ],
                'supervisor_attestation' => 'required_not_implemented',
                'authorization_cas' => 'root_broker_owned',
                'provider_http' => 'root_broker_owned_one_shot',
                'php_direct_provider_write' => 'forbidden',
                'signed_execution_receipt' => 'required_for_canonical_live_evidence',
                'status' => 'unprovisioned_fail_closed',
            ],
            'trusted_signer_store' => [
                'path' => 'tests/Fixtures/Contract/trusted-operator-signers.json',
                'contract' => LiveEvidenceAttestationGuard::TrustedSignersContract,
                'version' => LiveEvidenceAttestationGuard::Version,
                'sha256' => '228fa29cf6151c990c4e47cd639a9358d44cef06a8e70e4e1c07ff224d0254b7',
            ],
            'remote_authority_policy_store' => [
                'path' => 'tests/Fixtures/Contract/trusted-consumption-authorities.json',
                'contract' => 'cieplik206.fakturownia.remote-consumption-authorities',
                'version' => LiveEvidenceAttestationGuard::Version,
                'sha256' => '80d35511063aa2d59731e91d2e1ac96be26d04ae416b0408b864295d0a2ce6ec',
                'status' => 'unprovisioned_fail_closed',
            ],
            'maximum_authorization_ttl_seconds' => 86400,
            'maximum_evidence_ttl_seconds' => 2592000,
            'maximum_signing_delay_seconds' => 86400,
            'max_run_seconds' => 21600,
            'future_skew_seconds' => 300,
        ]);

    $root = dirname(__DIR__, 2);
    $launcher = $matrix['attestation_policy']['preautoload_trust_root'];
    $launcherFile = readFakturowniaEvidenceFile($root, $launcher['launcher_source_path']);
    $signerStore = $matrix['attestation_policy']['trusted_signer_store'];
    $signerStoreFile = readFakturowniaEvidenceFile($root, $signerStore['path']);
    $authorityStore = $matrix['attestation_policy']['remote_authority_policy_store'];
    $authorityStoreFile = readFakturowniaEvidenceFile($root, $authorityStore['path']);

    expect($launcherFile)->not->toBeNull()
        ->and(hash('sha256', $launcherFile['contents']))->toBe($launcher['launcher_source_sha256'])
        ->and($signerStoreFile)->not->toBeNull()
        ->and(hash('sha256', $signerStoreFile['contents']))->toBe($signerStore['sha256'])
        ->and(LiveEvidenceAttestationGuard::loadTrustedSigners())->toBe([])
        ->and($authorityStoreFile)->not->toBeNull()
        ->and(hash('sha256', $authorityStoreFile['contents']))->toBe($authorityStore['sha256']);
});

it('rejects duplicate trusted signer identities and key material across roles', function (): void {
    $temporaryRoot = realpath(sys_get_temp_dir());

    if (! is_string($temporaryRoot)) {
        throw new RuntimeException('Could not resolve the canonical temporary directory.');
    }

    $path = $temporaryRoot.'/fakturownia-trusted-signers-'.bin2hex(random_bytes(8)).'.json';
    $hardlinkPath = $path.'.hardlink';
    $operatorKeyPair = sodium_crypto_sign_keypair();
    $authorityKeyPair = sodium_crypto_sign_keypair();
    $operatorPublicKey = base64_encode(sodium_crypto_sign_publickey($operatorKeyPair));
    $authorityPublicKey = base64_encode(sodium_crypto_sign_publickey($authorityKeyPair));
    $store = static fn (array $signers): array => [
        'contract' => LiveEvidenceAttestationGuard::TrustedSignersContract,
        'version' => LiveEvidenceAttestationGuard::Version,
        'signers' => $signers,
    ];
    $operator = [
        'id' => 'operator-1',
        'algorithm' => LiveEvidenceAttestationGuard::Algorithm,
        'public_key' => $operatorPublicKey,
        'roles' => ['operator_attestation'],
    ];
    $authority = [
        'id' => 'authority-1',
        'algorithm' => LiveEvidenceAttestationGuard::Algorithm,
        'public_key' => $authorityPublicKey,
        'roles' => ['consumption_authority'],
    ];

    try {
        file_put_contents($path, LiveEvidenceAttestationGuard::canonicalJson($store([$operator, $authority])));

        expect(LiveEvidenceAttestationGuard::loadTrustedSignerRoles($path))->toBe([
            'operator_attestation' => ['operator-1' => $operatorPublicKey],
            'consumption_authority' => ['authority-1' => $authorityPublicKey],
        ]);

        expect(link($path, $hardlinkPath))->toBeTrue()
            ->and(fn () => LiveEvidenceAttestationGuard::loadTrustedSignerRoles($path))
            ->toThrow(RuntimeException::class);
        unlink($hardlinkPath);

        file_put_contents($path, LiveEvidenceAttestationGuard::canonicalJson($store([
            $operator,
            [...$authority, 'id' => 'operator-1'],
        ])));

        expect(fn () => LiveEvidenceAttestationGuard::loadTrustedSignerRoles($path))
            ->toThrow(RuntimeException::class);

        file_put_contents($path, LiveEvidenceAttestationGuard::canonicalJson($store([
            $operator,
            [...$authority, 'public_key' => $operatorPublicKey],
        ])));

        expect(fn () => LiveEvidenceAttestationGuard::loadTrustedSignerRoles($path))
            ->toThrow(RuntimeException::class);
    } finally {
        if (is_file($hardlinkPath)) {
            unlink($hardlinkPath);
        }

        if (is_file($path)) {
            unlink($path);
        }

        sodium_memzero($operatorKeyPair);
        sodium_memzero($authorityKeyPair);
    }
});

it('keeps the verified fresh-claim brand inaccessible to unverified wire consumers', function (): void {
    $constructor = (new ReflectionClass(VerifiedFreshClaimGrant::class))->getConstructor();

    expect($constructor)->not->toBeNull()
        ->and($constructor?->isPrivate())->toBeTrue();
});

it('requires the exact normative fields for every capability', function (): void {
    $matrix = loadFakturowniaCapabilityMatrix();
    $capabilities = fakturowniaCapabilitiesById($matrix);
    $evidenceContracts = fakturowniaEvidenceContractAllowlist();
    $classifications = ['exact', 'state-verifiable', 'heuristic', 'manual-only', 'deferred'];
    $evidenceStatuses = ['pending_live', 'pending_implementation', 'passed', 'failed', 'deferred', 'not_required'];
    $writeRecoveryAllowlist = [
        'request_not_started_before_effect_boundary_only' => [
            'exact_business_oid_with_stable_payload_fingerprint; absent_conclusive_terminates_failed_not_applied',
            'exact_original_invoice_link_business_oid_and_correction_fingerprint',
        ],
        'poll_only_after_effect_boundary' => ['preflight_then_one_explicit_send_then_status_observation_until_terminal_or_manual_review'],
        'disabled_until_capability_gate' => ['not_frozen'],
        'disabled_until_state_preflight_gate' => ['expected_remote_status_comparison', 'expected_remote_state_comparison'],
        'disabled_until_upload_reconciliation_gate' => ['not_frozen'],
        'disabled_until_finalize_reconciliation_gate' => ['invoice_or_zip_attachment_presence'],
        'never_automatic' => [
            'manual_review_with_post_delete_read_evidence',
            'manual_review_after_ambiguous_delete',
            'manual_review_after_ambiguous_destructive_effect',
        ],
        'never_blind' => ['manual_review_unless_provider_delivery_evidence_becomes_exact'],
        'content_addressed_put_after_rt6_gate' => ['object_head_size_and_sha256_then_descriptor_or_orphan_recovery'],
    ];
    $artifactContractByCapability = [
        'invoice.vat.issue' => 'fakturownia-invoice-identity-s0.3-v1',
        'invoice.ksef.ensure_accepted' => 'fakturownia-ksef-demo-s0.4-v1',
        'invoice.pdf.ksef_revision.observe' => 'fakturownia-ksef-demo-s0.4-v1',
    ];

    expect($matrix['evidence_contracts'])->toBe($evidenceContracts);

    foreach ($capabilities as $capability) {
        expect(array_keys($capability))->toBe([
            'id',
            'milestone',
            'classification',
            'transport',
            'semantic_writes',
            'identity',
            'recovery',
            'policy',
            'live_evidence',
            'limitations',
            'blocks_vat_pilot',
        ]);

        expect($capability['id'])->toBeString()->not->toBeEmpty()
            ->and($capability['milestone'])->toBeString()->not->toBeEmpty()
            ->and($capability['classification'])->toBeIn($classifications)
            ->and($capability['transport'])->toBeArray()
            ->and(array_keys($capability['transport']))->toBe(['method', 'endpoint'])
            ->and($capability['transport']['method'])->toBeIn(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])
            ->and($capability['transport']['endpoint'])->toBeString()->not->toBeEmpty()
            ->and($capability['semantic_writes'])->toBeArray()
            ->and(array_keys($capability['semantic_writes']))->toBe(['count', 'effects'])
            ->and($capability['identity'])->toBeArray()
            ->and(array_keys($capability['identity']))->toBe(['scope', 'fingerprint'])
            ->and(fakturowniaIsUniqueNonEmptyStringList($capability['identity']['scope']))->toBeTrue()
            ->and(fakturowniaIsUniqueNonEmptyStringList($capability['identity']['fingerprint']))->toBeTrue()
            ->and($capability['recovery'])->toBeArray()
            ->and(array_keys($capability['recovery']))->toBe(['safe_retry', 'reconciliation'])
            ->and($capability['recovery']['safe_retry'])->toBeString()->not->toBeEmpty()
            ->and($capability['recovery']['reconciliation'])->toBeString()->not->toBeEmpty()
            ->and($capability['policy'])->toBeArray()
            ->and(array_keys($capability['policy']))->toBe(['ownership', 'validation', 'profile'])
            ->and($capability['policy']['ownership'])->toBeString()->not->toBeEmpty()
            ->and($capability['policy']['validation'])->toBeString()->not->toBeEmpty()
            ->and($capability['policy']['profile'])->toBeString()->not->toBeEmpty()
            ->and($capability['live_evidence'])->toBeArray()
            ->and(array_keys($capability['live_evidence']))->toBe(['required', 'status', 'requirements', 'artifacts'])
            ->and($capability['live_evidence']['required'])->toBeBool()
            ->and($capability['live_evidence']['status'])->toBeIn($evidenceStatuses)
            ->and(fakturowniaIsUniqueNonEmptyStringList($capability['live_evidence']['requirements']))->toBeTrue()
            ->and($capability['live_evidence']['artifacts'])->toBeArray()
            ->and(fakturowniaIsUniqueNonEmptyStringList($capability['limitations']))->toBeTrue()
            ->and($capability['blocks_vat_pilot'])->toBeBool();

        expect($capability['semantic_writes']['count'])->toBeIn([0, 1])
            ->and(fakturowniaIsUniqueNonEmptyStringList(
                $capability['semantic_writes']['effects'],
                $capability['semantic_writes']['count'] === 0,
            ))->toBeTrue()
            ->and(count($capability['semantic_writes']['effects']))->toBe($capability['semantic_writes']['count']);

        if ($capability['semantic_writes']['count'] === 0) {
            expect($capability['semantic_writes']['effects'])->toBe([]);
        } else {
            $safeRetry = $capability['recovery']['safe_retry'];
            $reconciliation = $capability['recovery']['reconciliation'];

            expect($safeRetry)->toBeString()
                ->and($reconciliation)->toBeString()
                ->and(array_key_exists($safeRetry, $writeRecoveryAllowlist))->toBeTrue()
                ->and(in_array($reconciliation, $writeRecoveryAllowlist[$safeRetry] ?? [], true))->toBeTrue();
        }

        if ($capability['live_evidence']['required'] === true) {
            expect($capability['live_evidence']['status'])->not->toBe('not_required');
        }

        if ($capability['live_evidence']['status'] === 'passed') {
            expect($capability['live_evidence']['required'])->toBeTrue();
        }

        foreach ($capability['live_evidence']['artifacts'] as $artifact) {
            expect($artifact)->toBeArray()
                ->and(array_keys($artifact))->toBe(['contract', 'path', 'sha256', 'authorizations', 'attestation'])
                ->and($artifact['contract'])->toBeString()->toBeIn(array_keys($evidenceContracts))
                ->and($artifactContractByCapability[$capability['id']] ?? null)->toBe($artifact['contract'])
                ->and($artifact['path'])->toBeString()->not->toBeEmpty()
                ->and($artifact['authorizations'])->toBeArray()->not->toBeEmpty()
                ->and($artifact['attestation'])->toBeArray()
                ->and(array_keys($artifact['attestation']))->toBe(['path', 'sha256'])
                ->and($artifact['attestation']['path'])->toBeString()->not->toBeEmpty()
                ->and(
                    $artifact['sha256'] === null
                    || (is_string($artifact['sha256']) && preg_match('/^[a-f0-9]{64}$/', $artifact['sha256']) === 1),
                )->toBeTrue()
                ->and(
                    $artifact['attestation']['sha256'] === null
                    || (is_string($artifact['attestation']['sha256']) && preg_match('/^[a-f0-9]{64}$/', $artifact['attestation']['sha256']) === 1),
                )->toBeTrue();

            foreach ($artifact['authorizations'] as $authorization) {
                expect($authorization)->toBeArray()
                    ->and(array_keys($authorization))->toBe(['profile', 'path', 'sha256'])
                    ->and($authorization['profile'])->toBeString()->not->toBeEmpty()
                    ->and($authorization['path'])->toBeString()->not->toBeEmpty()
                    ->and(
                        $authorization['sha256'] === null
                        || (is_string($authorization['sha256']) && preg_match('/^[a-f0-9]{64}$/', $authorization['sha256']) === 1),
                    )->toBeTrue();
            }

            if ($capability['live_evidence']['status'] === 'passed') {
                expect($artifact['path'])->not->toContain('*')->not->toContain('?')
                    ->and($artifact['attestation']['path'])->not->toContain('*')->not->toContain('?')
                    ->and($artifact['sha256'])->toBeString()
                    ->and($artifact['attestation']['sha256'])->toBeString();

                foreach ($artifact['authorizations'] as $authorization) {
                    expect($authorization['path'])->not->toContain('*')->not->toContain('?')
                        ->and($authorization['sha256'])->toBeString();
                }
            }
        }
    }
});

it('freezes the exact semantic contracts of pilot and RT3 read capabilities', function (): void {
    $capabilities = fakturowniaCapabilitiesById(loadFakturowniaCapabilityMatrix());
    $readContract = static fn (
        string $milestone,
        string $endpoint,
        array $scope,
        array $fingerprint,
        string $reconciliation,
        string $validation,
    ): array => [
        'milestone' => $milestone,
        'classification' => 'state-verifiable',
        'transport' => ['method' => 'GET', 'endpoint' => $endpoint],
        'semantic_writes' => ['count' => 0, 'effects' => []],
        'identity' => ['scope' => $scope, 'fingerprint' => $fingerprint],
        'recovery' => ['safe_retry' => 'read_safe_after_contract_gate', 'reconciliation' => $reconciliation],
        'policy' => ['ownership' => 'sdk_read_client', 'validation' => $validation, 'profile' => 'rt3_pending'],
        'live_evidence_status' => 'pending_implementation',
        'blocks_vat_pilot' => false,
    ];
    $binaryContract = static fn (
        string $endpoint,
        array $scope,
        string $reconciliation,
        string $validation,
    ): array => [
        'milestone' => 'RT-3/S3.6',
        'classification' => 'state-verifiable',
        'transport' => ['method' => 'GET', 'endpoint' => $endpoint],
        'semantic_writes' => ['count' => 0, 'effects' => []],
        'identity' => ['scope' => $scope, 'fingerprint' => ['mime', 'size', 'sha256']],
        'recovery' => ['safe_retry' => 'read_safe_after_redirect_gate', 'reconciliation' => $reconciliation],
        'policy' => ['ownership' => 'sdk_read_client', 'validation' => $validation, 'profile' => 'rt3_pending'],
        'live_evidence_status' => 'pending_implementation',
        'blocks_vat_pilot' => false,
    ];
    $expected = [
        'invoice.vat.issue' => [
            'milestone' => '0.1',
            'classification' => 'exact',
            'transport' => ['method' => 'POST', 'endpoint' => '/invoices.json'],
            'semantic_writes' => ['count' => 1, 'effects' => ['invoice_create']],
            'identity' => [
                'scope' => ['provider', 'connection', 'account', 'department', 'document_kind', 'business_oid'],
                'fingerprint' => ['buyer_tax_identity', 'currency', 'total', 'issue_date', 'positions'],
            ],
            'recovery' => [
                'safe_retry' => 'request_not_started_before_effect_boundary_only',
                'reconciliation' => 'exact_business_oid_with_stable_payload_fingerprint; absent_conclusive_terminates_failed_not_applied',
            ],
            'policy' => ['ownership' => 'sdk_explicit', 'validation' => 'typed_request_and_response', 'profile' => 'business_oid+oid_unique_after_s0.3'],
            'live_evidence_status' => 'pending_implementation',
            'blocks_vat_pilot' => true,
        ],
        'invoice.ksef.ensure_accepted' => [
            'milestone' => '0.2',
            'classification' => 'state-verifiable',
            'transport' => ['method' => 'GET', 'endpoint' => '/invoices/{id}.json?send_to_ksef=yes'],
            'semantic_writes' => ['count' => 1, 'effects' => ['ksef_explicit_submit']],
            'identity' => [
                'scope' => ['provider', 'connection', 'remote_invoice_id'],
                'fingerprint' => ['remote_invoice_id', 'ownership_profile', 'validation_profile', 'gov_status', 'gov_id'],
            ],
            'recovery' => [
                'safe_retry' => 'poll_only_after_effect_boundary',
                'reconciliation' => 'preflight_then_one_explicit_send_then_status_observation_until_terminal_or_manual_review',
            ],
            'policy' => ['ownership' => 'explicit_sdk', 'validation' => 'block_invalid', 'profile' => 'explicit_sdk+block_invalid'],
            'live_evidence_status' => 'pending_implementation',
            'blocks_vat_pilot' => false,
        ],
        'invoice.pdf.ksef_revision.observe' => [
            'milestone' => '0.2',
            'classification' => 'state-verifiable',
            'transport' => ['method' => 'GET', 'endpoint' => '/invoices/{id}.pdf'],
            'semantic_writes' => ['count' => 0, 'effects' => []],
            'identity' => [
                'scope' => ['provider', 'connection', 'remote_invoice_id', 'ksef_probe_run'],
                'fingerprint' => ['mime', 'size', 'ephemeral_run_hmac_sha256', 'before_after_equality'],
            ],
            'recovery' => ['safe_retry' => 'read_safe_with_redirects_forbidden', 'reconciliation' => 'validate_http_mime_magic_bytes_size_and_checksum'],
            'policy' => ['ownership' => 'contract_probe', 'validation' => 'binary_pdf_contract_redirects_forbidden', 'profile' => 's0.4_pre_post_observation'],
            'live_evidence_status' => 'pending_implementation',
            'blocks_vat_pilot' => false,
        ],
        'invoice.pdf.download' => [
            'milestone' => 'RT-6/S6.1-2',
            'classification' => 'state-verifiable',
            'transport' => ['method' => 'GET', 'endpoint' => '/invoices/{id}.pdf + ArtifactStore.putIfAbsent'],
            'semantic_writes' => ['count' => 1, 'effects' => ['artifact_store_put']],
            'identity' => [
                'scope' => ['provider', 'connection', 'remote_invoice_id', 'remote_snapshot_version', 'ksef_terminal_operation', 'rendering_profile'],
                'fingerprint' => ['artifact_revision', 'mime', 'size', 'sha256'],
            ],
            'recovery' => ['safe_retry' => 'content_addressed_put_after_rt6_gate', 'reconciliation' => 'object_head_size_and_sha256_then_descriptor_or_orphan_recovery'],
            'policy' => ['ownership' => 'sdk_artifact_store', 'validation' => 'remote_pdf_and_content_addressed_object_contract', 'profile' => 'immutable_content_addressed_revision'],
            'live_evidence_status' => 'pending_implementation',
            'blocks_vat_pilot' => false,
        ],
        'invoice.read.list' => $readContract(
            'RT-3/S3.3',
            '/invoices.json',
            ['provider', 'connection', 'remote_invoice_id'],
            ['typed_remote_snapshot'],
            'pagination_stable_order_duplicate_page_guard_and_complete_exact_oid_scan',
            'typed_invoice_collection_contract',
        ),
        'invoice.read.get' => $readContract('RT-3/S3.3', '/invoices/{id}.json', ['provider', 'connection', 'remote_invoice_id'], ['typed_remote_snapshot'], 'exact_remote_invoice_id', 'typed_invoice_contract'),
        'client.read.list' => $readContract('RT-3/S3.4', '/clients.json', ['provider', 'connection', 'remote_client_id'], ['typed_remote_snapshot'], 'pagination_stable_order_and_remote_id_deduplication', 'typed_client_collection_contract'),
        'client.read.get' => $readContract('RT-3/S3.4', '/clients/{id}.json', ['provider', 'connection', 'remote_client_id'], ['typed_remote_snapshot'], 'exact_remote_client_id', 'typed_client_contract'),
        'product.read.list' => $readContract('RT-3/S3.4', '/products.json', ['provider', 'connection', 'remote_product_id'], ['typed_remote_snapshot'], 'pagination_stable_order_and_remote_id_deduplication', 'typed_product_collection_contract'),
        'product.read.get' => $readContract('RT-3/S3.4', '/products/{id}.json', ['provider', 'connection', 'remote_product_id'], ['typed_remote_snapshot'], 'exact_remote_product_id', 'typed_product_contract'),
        'invoice.pdf.stream' => $binaryContract('/invoices/{id}.pdf', ['provider', 'connection', 'remote_invoice_id'], 'pdf_mime_magic_bytes_size_and_checksum', 'bounded_binary_stream_contract'),
        'invoice.attachments.zip.stream' => $binaryContract('/invoices/{id}/attachments_zip.json', ['provider', 'connection', 'remote_invoice_id'], 'zip_mime_magic_bytes_size_and_checksum', 'bounded_binary_stream_contract'),
        'invoice.ksef.xml.stream' => $binaryContract('/invoices/{id}/attachment?kind=gov', ['provider', 'connection', 'remote_invoice_id', 'gov_id'], 'http_302_ready_or_404_missing_then_xml_mime_content_size_and_checksum', 'bounded_xml_stream_contract_with_302_ready_404_missing'),
        'invoice.ksef.upo.stream' => $binaryContract('/invoices/{id}/attachment?kind=gov_upo', ['provider', 'connection', 'remote_invoice_id', 'gov_id'], 'http_302_ready_or_404_missing_then_upo_mime_content_size_and_checksum', 'bounded_upo_stream_contract_with_302_ready_404_missing'),
        'payment.read.list' => $readContract('RT-3/S3.4', '/banking/payments.json', ['provider', 'connection', 'remote_payment_id'], ['remote_snapshot_hmac'], 'pagination_and_remote_id_deduplication', 'typed_payment_collection_contract'),
        'payment.read.get' => $readContract('RT-3/S3.4', '/banking/payment/{id}.json', ['provider', 'connection', 'remote_payment_id'], ['typed_remote_snapshot'], 'exact_remote_payment_id_after_response_contract_gate', 'payment_detail_response_contract_unverified'),
    ];
    $actual = [];

    foreach (array_keys($expected) as $capabilityId) {
        $actual[$capabilityId] = fakturowniaCriticalCapabilityContract($capabilities[$capabilityId]);
    }

    expect($actual)->toBe($expected);
});

it('cannot declare live evidence passed without a concrete matching artifact hash', function (): void {
    $root = dirname(__DIR__, 2);
    $matrix = loadFakturowniaCapabilityMatrix();
    $capabilities = fakturowniaCapabilitiesById($matrix);
    $allowlist = fakturowniaEvidenceContractAllowlist();
    $trustedSignerRoles = LiveEvidenceAttestationGuard::loadTrustedSignerRoles();
    $trustedSigners = $trustedSignerRoles['operator_attestation'];
    $trustedConsumptionAuthorities = $trustedSignerRoles['consumption_authority'];
    $attestationPolicy = $matrix['attestation_policy'];
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $passedEvidenceByCapability = [];

    expect($capabilities)->not->toBeEmpty();

    foreach ($capabilities as $capability) {
        $evidence = $capability['live_evidence'];

        if (($evidence['status'] ?? null) !== 'passed') {
            expect(fakturowniaPassedEvidenceIsValid(
                $root,
                $capability['id'],
                $evidence,
                $allowlist,
                $trustedSigners,
                $attestationPolicy,
                $now,
                $trustedConsumptionAuthorities,
            ))->toBeFalse();

            continue;
        }

        $passedEvidenceByCapability[$capability['id']] = $evidence;

        expect(fakturowniaPassedEvidenceIsValid(
            $root,
            $capability['id'],
            $evidence,
            $allowlist,
            $trustedSigners,
            $attestationPolicy,
            $now,
            $trustedConsumptionAuthorities,
        ))->toBeTrue();
    }

    expect(fakturowniaPassedEvidenceSetIsValid(
        $root,
        $passedEvidenceByCapability,
        $allowlist,
        $trustedSigners,
        $attestationPolicy,
        $now,
        $trustedConsumptionAuthorities,
    ))->toBeTrue();
});

it('accepts a complete causal A run B chain and rejects signed-chain mutations', function (): void {
    $sourceRoot = dirname(__DIR__, 2);
    $temporaryRoot = realpath(sys_get_temp_dir());

    if (! is_string($temporaryRoot)) {
        throw new RuntimeException('Could not resolve the canonical system temporary directory.');
    }

    $repositoryRoot = $temporaryRoot.'/fakturownia-capability-chain-'.bin2hex(random_bytes(8));
    $claimRoot = $temporaryRoot.'/fakturownia-capability-claims-'.bin2hex(random_bytes(8));
    $contract = 'fakturownia-ksef-demo-s0.4-v1';
    $fixtureRelativePath = 'tests/Fixtures/Contract/ksef-demo-full-chain.json';
    $fixturePath = $repositoryRoot.'/'.$fixtureRelativePath;
    $signerId = 's05-test-operator';
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $authorityKeyPair = sodium_crypto_sign_keypair();
    $authoritySecretKey = sodium_crypto_sign_secretkey($authorityKeyPair);
    $authoritySignerId = 's05-consumption-authority';
    $trustedSigners = [
        $signerId => base64_encode(sodium_crypto_sign_publickey($keyPair)),
    ];
    $trustedConsumptionAuthorities = [
        $authoritySignerId => base64_encode(sodium_crypto_sign_publickey($authorityKeyPair)),
    ];
    $authorizationIssuedAt = new DateTimeImmutable('2026-08-25T09:55:00.000000Z');
    $authorizationExpiresAt = new DateTimeImmutable('2026-08-26T09:55:00.000000Z');
    $claimedAt = new DateTimeImmutable('2026-08-25T09:59:59.900000Z');
    $runStartedAt = new DateTimeImmutable('2026-08-25T10:00:00.000000Z');
    $authorityIssuedAt = new DateTimeImmutable('2026-08-25T10:00:00.000001Z');
    $runFinishedAt = new DateTimeImmutable('2026-08-25T10:01:00.000000Z');
    $evidenceIssuedAt = new DateTimeImmutable('2026-08-25T10:01:00.000001Z');
    $evidenceExpiresAt = new DateTimeImmutable('2026-08-26T10:01:00.000001Z');

    expect(mkdir($repositoryRoot, 0700, true))->toBeTrue()
        ->and(mkdir($claimRoot, 0700, true))->toBeTrue();

    try {
        runFakturowniaTestGit($repositoryRoot, ['init', '--quiet']);

        foreach (LiveEvidenceAttestationGuard::harnessManifest($contract) as $relativePath) {
            $contents = file_get_contents($sourceRoot.'/'.$relativePath);

            if (! is_string($contents)) {
                throw new RuntimeException('Could not copy a behavior-bearing harness file into the protocol test repository.');
            }

            writeFakturowniaTestFile($repositoryRoot, $relativePath, $contents);
        }

        foreach (LiveEvidenceAttestationGuard::dependencyBootstrapManifest() as $relativePath) {
            $contents = file_get_contents($sourceRoot.'/'.$relativePath);

            if (! is_string($contents)) {
                throw new RuntimeException('Could not copy a Composer bootstrap file into the protocol test repository.');
            }

            writeFakturowniaTestFile($repositoryRoot, $relativePath, $contents);
        }

        runFakturowniaTestGit($repositoryRoot, ['add', '--', ...LiveEvidenceAttestationGuard::harnessManifest($contract)]);
        runFakturowniaTestGit($repositoryRoot, [
            '-c',
            'user.name=Capability Matrix Test',
            '-c',
            'user.email=capability-matrix@example.invalid',
            'commit',
            '--quiet',
            '-m',
            'Pin behavior-bearing harness',
        ]);
        $repositoryCommit = runFakturowniaTestGit($repositoryRoot, ['rev-parse', 'HEAD']);
        $archivedHarness = LiveEvidenceAttestationGuard::harnessSnapshot($repositoryRoot, $contract);
        $codeSha256 = hash('sha256', LiveEvidenceAttestationGuard::canonicalJson($archivedHarness));
        $launchManifestSha256 = hash('sha256', 's05-supervised-launch-manifest-v1');

        $storeMetadata = LiveEvidenceAttestationGuard::claimStoreMetadataForTesting(base64_encode(random_bytes(32)));
        writeFakturowniaTestFile(
            $claimRoot,
            '.store.json',
            LiveEvidenceAttestationGuard::canonicalJson($storeMetadata),
            0600,
        );
        $claimStore = LiveEvidenceClaimStore::forTesting($claimRoot);
        $storeIdentitySha256 = LiveEvidenceAttestationGuard::claimStoreIdentitySha256ForTesting(
            $claimStore,
            $repositoryRoot,
        );
        $runId = bin2hex(random_bytes(16));
        $signedAuthorizations = [];

        foreach (['explicit_block', 'explicit_persist', 'auto_block', 'auto_persist'] as $index => $profile) {
            $envelope = LiveEvidenceAttestationGuard::buildUnsignedAuthorizationEnvelope(
                $signerId,
                $authorizationIssuedAt,
                $authorizationExpiresAt,
                $contract,
                base64_encode(hash('sha256', 's05-challenge-'.$profile, true)),
                $repositoryCommit,
                $codeSha256,
                $launchManifestSha256,
                [
                    'environment' => 'ksef_demo',
                    'profile' => $profile,
                    'tenant_hmac_sha256' => hash('sha256', 'tenant-'.$index),
                    'account_hmac_sha256' => hash('sha256', 'account-'.$index),
                ],
                [
                    'scheme' => LiveEvidenceAttestationGuard::CommitmentScheme,
                    'configuration_hmac_sha256' => hash('sha256', 'configuration-'.$index),
                    'policy_hmac_sha256' => hash('sha256', 'policy-'.$index),
                    'safety_hmac_sha256' => hash('sha256', 'safety-'.$index),
                    'templates_hmac_sha256' => hash('sha256', 'templates-'.$index),
                ],
                [
                    'authority_id' => $authoritySignerId,
                    'authority_policy_sha256' => hash('sha256', 's05-test-append-only-cas-policy-v1'),
                    'store_id' => 's05-test-cas-primary',
                    'store_identity_sha256' => $storeIdentitySha256,
                    'run_id' => $runId,
                    'replay_policy' => LiveEvidenceAttestationGuard::ConsumptionReplayPolicy,
                ],
                ['maximum_semantic_writes' => 1, 'profile_index' => $index],
            );
            $signedAuthorizations[] = signFakturowniaTestEnvelope($envelope, $secretKey);
        }

        $autoloadFilesPath = $repositoryRoot.'/vendor/composer/autoload_files.php';
        $autoloadFilesContents = file_get_contents($autoloadFilesPath);

        if (! is_string($autoloadFilesContents)) {
            throw new RuntimeException('Could not read the Composer bootstrap regression fixture.');
        }

        file_put_contents($autoloadFilesPath, $autoloadFilesContents."\n// injected bootstrap\n");

        expect(fn () => LiveEvidenceAttestationGuard::assertHarnessMatchesRepositoryCommit(
            $repositoryRoot,
            $contract,
            $repositoryCommit,
            $codeSha256,
        ))->toThrow(RuntimeException::class);

        file_put_contents($autoloadFilesPath, $autoloadFilesContents);

        LiveEvidenceAttestationGuard::assertHarnessMatchesRepositoryCommit(
            $repositoryRoot,
            $contract,
            $repositoryCommit,
            $codeSha256,
        );
        $receipt = LiveEvidenceAttestationGuard::claimAuthorizationSignaturesNow(
            $signedAuthorizations,
            $repositoryRoot,
            $claimStore,
            $claimedAt,
            86_400,
            $trustedSigners,
        );
        $claimRequest = LiveEvidenceAttestationGuard::buildConsumptionClaimRequest(
            $signedAuthorizations,
            $runStartedAt,
            base64_encode(hash('sha256', 's05-direct-response-claim-nonce', true)),
        );
        $authority = new class($authoritySignerId, $authoritySecretKey, $authorityIssuedAt, $authorizationExpiresAt) implements LiveEvidenceConsumptionAuthority
        {
            public int $calls = 0;

            /** @var array<string, mixed>|null */
            public ?array $lastRequest = null;

            public function __construct(
                private string $signerId,
                private string $secretKey,
                private DateTimeImmutable $issuedAt,
                private DateTimeImmutable $expiresAt,
            ) {}

            public function claim(array $signedAuthorizations, ConsumptionClaimRequest $claimRequest): FreshClaimGrant
            {
                $this->calls++;
                $this->lastRequest = $claimRequest->toArray();
                $envelope = LiveEvidenceAttestationGuard::buildConsumptionAuthorityEnvelopeForTesting(
                    $signedAuthorizations,
                    $claimRequest->toArray(),
                    $this->signerId,
                    ['store_id' => 's05-test-cas-primary', 'sequence' => '1'],
                    $this->issuedAt,
                    $this->expiresAt,
                );

                return new FreshClaimGrant(ConsumptionReceipt::fromArray(
                    signFakturowniaTestEnvelope($envelope, $this->secretKey),
                ));
            }
        };
        $verifiedFreshGrant = LiveEvidenceAttestationGuard::claimAuthorizationSignaturesWithAuthorityNow(
            $signedAuthorizations,
            $runStartedAt,
            new DateTimeImmutable('2026-08-25T10:00:00.000002Z'),
            86_400,
            86_400,
            $authority,
            $claimRequest['claim_nonce'],
            $trustedSigners,
            $trustedConsumptionAuthorities,
        );
        $signedAuthorityReceipt = $verifiedFreshGrant->toArray();
        expect(LiveEvidenceAttestationGuard::assertVerifiedFreshGrantSignaturesAtEffectBoundary(
            $verifiedFreshGrant,
            $signedAuthorizations,
            ConsumptionClaimRequest::fromArray($claimRequest),
            new DateTimeImmutable('2026-08-25T10:00:01.000000Z'),
            86_400,
            30,
            86_400,
            $trustedSigners,
            $trustedConsumptionAuthorities,
        ))->toBe($verifiedFreshGrant);
        $forger = Closure::bind(
            static fn (FreshClaimGrant $grant): VerifiedFreshClaimGrant => new VerifiedFreshClaimGrant($grant),
            null,
            VerifiedFreshClaimGrant::class,
        );

        $forgedGrant = $forger(new FreshClaimGrant(ConsumptionReceipt::fromArray($signedAuthorityReceipt)));

        expect(fn () => LiveEvidenceAttestationGuard::assertVerifiedFreshGrantSignaturesAtEffectBoundary(
            $forgedGrant,
            $signedAuthorizations,
            ConsumptionClaimRequest::fromArray($claimRequest),
            new DateTimeImmutable('2026-08-25T10:00:01.000000Z'),
            86_400,
            30,
            86_400,
            $trustedSigners,
            $trustedConsumptionAuthorities,
        ))->toThrow(InvalidArgumentException::class);
        expect(fn () => LiveEvidenceAttestationGuard::assertVerifiedFreshGrantSignaturesAtEffectBoundary(
            $verifiedFreshGrant,
            $signedAuthorizations,
            ConsumptionClaimRequest::fromArray($claimRequest),
            new DateTimeImmutable('2026-08-26T09:54:50.000000Z'),
            86_400,
            30,
            86_400,
            $trustedSigners,
            $trustedConsumptionAuthorities,
        ))->toThrow(InvalidArgumentException::class);
        $productionEffectParameters = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(
                LiveEvidenceAttestationGuard::class,
                'assertVerifiedFreshGrantAtEffectBoundary',
            ))->getParameters(),
        );
        expect($productionEffectParameters)->not->toContain('now');
        expect(fn () => LiveEvidenceAttestationGuard::assertVerifiedFreshGrantAtEffectBoundary(
            $verifiedFreshGrant,
            $signedAuthorizations,
            ConsumptionClaimRequest::fromArray($claimRequest),
            $repositoryRoot,
            86_400,
            30,
            86_400,
        ))->toThrow(RuntimeException::class, 'A CAS grant cannot open a mutating effect');

        expect(fn () => LiveEvidenceAttestationGuard::claimAuthorizationSignaturesWithAuthorityNow(
            $signedAuthorizations,
            $runStartedAt,
            new DateTimeImmutable('2026-08-25T10:00:00.000002Z'),
            86_400,
            86_400,
            $authority,
            $claimRequest['claim_nonce'],
            $trustedSigners,
            [$authoritySignerId => $trustedSigners[$signerId]],
        ))->toThrow(InvalidArgumentException::class);
        expect($authority->calls)->toBe(1)
            ->and(LiveEvidenceAttestationGuard::canonicalJson($authority->lastRequest ?? []))
            ->toBe(LiveEvidenceAttestationGuard::canonicalJson($claimRequest));
        $replayAuthority = new class($signedAuthorityReceipt) implements LiveEvidenceConsumptionAuthority
        {
            /** @param array<string, mixed> $receipt */
            public function __construct(private array $receipt) {}

            public function claim(array $signedAuthorizations, ConsumptionClaimRequest $claimRequest): FreshClaimGrant
            {
                return new FreshClaimGrant(ConsumptionReceipt::fromArray($this->receipt));
            }
        };

        expect(fn () => LiveEvidenceAttestationGuard::claimAuthorizationSignaturesWithAuthorityNow(
            $signedAuthorizations,
            $runStartedAt,
            new DateTimeImmutable('2026-08-25T10:00:00.000003Z'),
            86_400,
            86_400,
            $replayAuthority,
            base64_encode(hash('sha256', 's05-new-process-replay-nonce', true)),
            $trustedSigners,
            $trustedConsumptionAuthorities,
        ))->toThrow(InvalidArgumentException::class);

        $recoveredEnvelope = LiveEvidenceAttestationGuard::buildConsumptionAuthorityEnvelopeForTesting(
            $signedAuthorizations,
            $claimRequest,
            $authoritySignerId,
            ['store_id' => 's05-test-cas-primary', 'sequence' => '1'],
            $authorityIssuedAt,
            $authorizationExpiresAt,
            LiveEvidenceAttestationGuard::RecoveredConsumptionDisposition,
        );
        $recoveredAuthority = new class(signFakturowniaTestEnvelope($recoveredEnvelope, $authoritySecretKey)) implements LiveEvidenceConsumptionAuthority
        {
            /** @param array<string, mixed> $receipt */
            public function __construct(private array $receipt) {}

            public function claim(array $signedAuthorizations, ConsumptionClaimRequest $claimRequest): RecoveredConsumedProof
            {
                return new RecoveredConsumedProof(ConsumptionReceipt::fromArray($this->receipt));
            }
        };

        expect(fn () => LiveEvidenceAttestationGuard::claimAuthorizationSignaturesWithAuthorityNow(
            $signedAuthorizations,
            $runStartedAt,
            new DateTimeImmutable('2026-08-25T10:00:00.000003Z'),
            86_400,
            86_400,
            $recoveredAuthority,
            $claimRequest['claim_nonce'],
            $trustedSigners,
            $trustedConsumptionAuthorities,
        ))->toThrow(InvalidArgumentException::class);
        $fixture = fakturowniaS04Fixture($runStartedAt, $runFinishedAt, $launchManifestSha256);
        $fixtureContents = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        writeFakturowniaTestFile($repositoryRoot, $fixtureRelativePath, $fixtureContents);
        $fixtureSha256 = hash('sha256', $fixtureContents);
        $authorizationReferences = [];
        $authorizationArtifacts = [];

        foreach ($signedAuthorizations as $signedAuthorization) {
            $profile = $signedAuthorization['envelope']['target']['profile'];
            $authorizationRelativePath = substr($fixtureRelativePath, 0, -5).'.authorization-'.$profile.'.json';
            $authorizationContents = LiveEvidenceAttestationGuard::canonicalJson($signedAuthorization);
            writeFakturowniaTestFile($repositoryRoot, $authorizationRelativePath, $authorizationContents);
            $authorizationSha256 = hash('sha256', $authorizationContents);
            $authorizationReferences[] = [
                'profile' => $profile,
                'challenge' => $signedAuthorization['envelope']['challenge'],
                'sha256' => $authorizationSha256,
            ];
            $authorizationArtifacts[] = [
                'profile' => $profile,
                'path' => $authorizationRelativePath,
                'sha256' => $authorizationSha256,
            ];
        }

        $payload = LiveEvidenceAttestationGuard::buildUnsignedEvidencePayload(
            $contract,
            $fixtureRelativePath,
            $fixtureSha256,
            $repositoryCommit,
            $codeSha256,
            $signedAuthorizations[0]['envelope']['harness']['launch_manifest_sha256'],
            $archivedHarness,
            $runStartedAt,
            $runFinishedAt,
            'ksef_demo',
            [
                'local_claim' => $receipt,
                'authority_receipt' => $signedAuthorityReceipt,
                'effect_execution_receipts' => [],
            ],
            $authorizationReferences,
            LiveEvidenceAttestationGuard::evidenceCommitments($signedAuthorizations, $fixture, $contract),
        );
        $providerRunForger = Closure::bind(
            static fn (): VerifiedLiveProviderRun => new VerifiedLiveProviderRun,
            null,
            VerifiedLiveProviderRun::class,
        );

        $forgedProviderRun = $providerRunForger();

        expect(fn () => LiveEvidenceAttestationGuard::buildLiveUnsignedEvidencePayload(
            $contract,
            $fixtureRelativePath,
            $fixtureSha256,
            $repositoryCommit,
            $codeSha256,
            $archivedHarness,
            $verifiedFreshGrant,
            $forgedProviderRun,
            $signedAuthorizations,
            $receipt,
            $authorizationReferences,
            $payload['commitments'],
        ))->toThrow(RuntimeException::class, 'brokered effect-execution receipts')
            ->and(fn () => LiveEvidenceAttestationGuard::prepareEvidenceEnvelopeForSigning(
                $payload,
                $signerId,
                86_400,
            ))->toThrow(InvalidArgumentException::class);
        $evidenceEnvelope = LiveEvidenceAttestationGuard::buildEvidenceEnvelopeForTesting(
            $payload,
            $signerId,
            $evidenceIssuedAt,
            $evidenceExpiresAt,
        );
        $signedEvidence = signFakturowniaTestEnvelope($evidenceEnvelope, $secretKey);
        $authorityReceipt = ConsumptionReceipt::fromArray($signedAuthorityReceipt);
        $liveOrigins = [
            'contract' => LiveEvidenceAttestationGuard::LiveRuntimeOriginsContract,
            'version' => LiveEvidenceAttestationGuard::Version,
            'scheme' => LiveEvidenceAttestationGuard::LiveRuntimeOriginsScheme,
            'authority_receipt_sha256' => LiveEvidenceAttestationGuard::signedDocumentSha256($signedAuthorityReceipt),
            'claim_request_sha256' => $authorityReceipt->envelope->claimRequest->sha256(),
            'launch_manifest_sha256' => $launchManifestSha256,
            'provider_run_sha256' => hash('sha256', LiveEvidenceAttestationGuard::canonicalJson([
                'contract' => 'cieplik206.fakturownia.live-provider-run-binding',
                'version' => LiveEvidenceAttestationGuard::Version,
                'evidence_contract' => $contract,
                'started_at' => $payload['run']['started_at'],
                'finished_at' => $payload['run']['finished_at'],
                'environment' => $payload['run']['environment'],
                'launch_manifest_sha256' => $launchManifestSha256,
                'fixture_sha256' => $fixtureSha256,
            ])),
        ];
        $livePayload = [
            ...$payload,
            'contract' => LiveEvidenceAttestationGuard::EvidencePayloadContract,
            'origins' => $liveOrigins,
        ];
        $liveEvidenceEnvelope = [
            'contract' => LiveEvidenceAttestationGuard::EvidenceContract,
            'version' => LiveEvidenceAttestationGuard::Version,
            'algorithm' => LiveEvidenceAttestationGuard::Algorithm,
            'signer_id' => $signerId,
            'issued_at' => $evidenceIssuedAt->format('Y-m-d\TH:i:s.u\Z'),
            'expires_at' => $evidenceExpiresAt->format('Y-m-d\TH:i:s.u\Z'),
            'evidence' => $livePayload['evidence'],
            'probe' => $livePayload['probe'],
            'run' => $livePayload['run'],
            'consumption' => $livePayload['consumption'],
            'authorizations' => $livePayload['authorizations'],
            'commitments' => $livePayload['commitments'],
            'origins' => $livePayload['origins'],
        ];
        $signedLiveEvidence = signFakturowniaTestEnvelope($liveEvidenceEnvelope, $secretKey);
        expect($signedEvidence['envelope']['contract'])->toBe(LiveEvidenceAttestationGuard::TestEvidenceContract);
        expect(fn () => LiveEvidenceAttestationGuard::writeUnsignedEvidenceSidecar(
            $repositoryRoot,
            $payload,
            $signedAuthorizations,
            86_400,
            $trustedSigners,
            $trustedConsumptionAuthorities,
        ))->toThrow(InvalidArgumentException::class);

        $recoveredPayload = $payload;
        $recoveredPayload['consumption']['authority_receipt'] = signFakturowniaTestEnvelope(
            $recoveredEnvelope,
            $authoritySecretKey,
        );
        $recoveredEvidenceEnvelope = LiveEvidenceAttestationGuard::buildEvidenceEnvelopeForTesting(
            $recoveredPayload,
            $signerId,
            $evidenceIssuedAt,
            $evidenceExpiresAt,
        );
        $recoveredEvidence = signFakturowniaTestEnvelope($recoveredEvidenceEnvelope, $secretKey);

        expect(fn () => LiveEvidenceAttestationGuard::assertHistoricalTestEvidenceSignatures(
            $recoveredEvidence,
            $signedAuthorizations,
            $fixture,
            $runStartedAt,
            $runFinishedAt,
            21_600,
            86_400,
            86_400,
            86_400,
            $trustedSigners,
            $trustedConsumptionAuthorities,
        ))->toThrow(InvalidArgumentException::class);
        $attestationRelativePath = substr($fixtureRelativePath, 0, -5).'.attestation.json';
        $attestationPath = $repositoryRoot.'/'.$attestationRelativePath;
        $attestationContents = LiveEvidenceAttestationGuard::canonicalJson($signedEvidence);
        writeFakturowniaTestFile($repositoryRoot, $attestationRelativePath, $attestationContents);
        $artifact = [
            'contract' => $contract,
            'path' => $fixtureRelativePath,
            'sha256' => $fixtureSha256,
            'authorizations' => $authorizationArtifacts,
            'attestation' => [
                'path' => $attestationRelativePath,
                'sha256' => hash('sha256', $attestationContents),
            ],
        ];
        $evidence = [
            'required' => true,
            'status' => 'passed',
            'requirements' => ['full_causal_chain'],
            'artifacts' => [$artifact],
        ];
        $matrix = loadFakturowniaCapabilityMatrix();
        $policy = $matrix['attestation_policy'];
        $allowlist = fakturowniaEvidenceContractAllowlist();

        LiveEvidenceAttestationGuard::assertHistoricalTestEvidenceSignatures(
            $signedEvidence,
            $signedAuthorizations,
            $fixture,
            $runStartedAt,
            $runFinishedAt,
            $policy['max_run_seconds'],
            $policy['maximum_authorization_ttl_seconds'],
            $policy['maximum_evidence_ttl_seconds'],
            $policy['maximum_signing_delay_seconds'],
            $trustedSigners,
            $trustedConsumptionAuthorities,
        );
        KsefDemoFixtureGuard::assertSafe($fixture, []);
        expect(readFakturowniaEvidenceFile($repositoryRoot, $fixtureRelativePath))->not->toBeNull()
            ->and(readFakturowniaEvidenceFile($repositoryRoot, $attestationRelativePath))->not->toBeNull();

        foreach ($authorizationArtifacts as $authorizationArtifact) {
            expect(readFakturowniaEvidenceFile($repositoryRoot, $authorizationArtifact['path']))->not->toBeNull();
        }

        $reloadedAuthorizations = array_map(
            static fn (array $authorizationArtifact): array => json_decode(
                (string) readFakturowniaEvidenceFile($repositoryRoot, $authorizationArtifact['path'])['contents'],
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            $authorizationArtifacts,
        );
        $reloadedEvidence = json_decode(
            (string) readFakturowniaEvidenceFile($repositoryRoot, $attestationRelativePath)['contents'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect(array_map(LiveEvidenceAttestationGuard::canonicalJson(...), $reloadedAuthorizations))
            ->toBe(array_map(LiveEvidenceAttestationGuard::canonicalJson(...), $signedAuthorizations))
            ->and(LiveEvidenceAttestationGuard::canonicalJson($reloadedEvidence))
            ->toBe(LiveEvidenceAttestationGuard::canonicalJson($signedEvidence));
        LiveEvidenceAttestationGuard::assertHistoricalTestEvidenceSignatures(
            $reloadedEvidence,
            $reloadedAuthorizations,
            $fixture,
            $runStartedAt,
            $runFinishedAt,
            $policy['max_run_seconds'],
            $policy['maximum_authorization_ttl_seconds'],
            $policy['maximum_evidence_ttl_seconds'],
            $policy['maximum_signing_delay_seconds'],
            $trustedSigners,
            $trustedConsumptionAuthorities,
        );

        expect(fakturowniaArtifactAttestationIsValid(
            $repositoryRoot,
            $artifact,
            $fixtureSha256,
            $fixture,
            $trustedSigners,
            $policy,
            new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
            $trustedConsumptionAuthorities,
        ))->toBeFalse();

        expect(fakturowniaPassedEvidenceIsValid(
            $repositoryRoot,
            'invoice.ksef.ensure_accepted',
            $evidence,
            $allowlist,
            $trustedSigners,
            $policy,
            new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
            $trustedConsumptionAuthorities,
        ))->toBeFalse()
            ->and(fakturowniaPassedEvidenceIsValid(
                $repositoryRoot,
                'invoice.ksef.ensure_accepted',
                $evidence,
                $allowlist,
                $trustedSigners,
                $policy,
                new DateTimeImmutable('2027-08-25T10:02:00.000000Z'),
                $trustedConsumptionAuthorities,
            ))->toBeFalse()
            ->and(fn () => LiveEvidenceAttestationGuard::buildEvidenceEnvelopeForTesting(
                $payload,
                $signerId,
                new DateTimeImmutable('2026-08-25T10:00:59.999999Z'),
                $evidenceExpiresAt,
            ))->toThrow(InvalidArgumentException::class);

        expect(fakturowniaPassedEvidenceSetIsValid(
            $repositoryRoot,
            ['invoice.ksef.ensure_accepted' => $evidence],
            $allowlist,
            $trustedSigners,
            $policy,
            new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
            $trustedConsumptionAuthorities,
        ))->toBeFalse()
            ->and(fakturowniaPassedEvidenceSetIsValid(
                $repositoryRoot,
                [
                    'invoice.ksef.ensure_accepted' => $evidence,
                    'invoice.pdf.ksef_revision.observe' => $evidence,
                ],
                $allowlist,
                $trustedSigners,
                $policy,
                new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
                $trustedConsumptionAuthorities,
            ))->toBeFalse();

        $liveAttestationContents = LiveEvidenceAttestationGuard::canonicalJson($signedLiveEvidence);
        file_put_contents($attestationPath, $liveAttestationContents);
        $liveArtifact = $artifact;
        $liveArtifact['attestation']['sha256'] = hash('sha256', $liveAttestationContents);
        $liveEvidence = [...$evidence, 'artifacts' => [$liveArtifact]];
        expect(fn () => LiveEvidenceAttestationGuard::assertHistoricalEvidence(
            $signedLiveEvidence,
            $signedAuthorizations,
            $fixture,
            $repositoryRoot,
            $runStartedAt,
            $runFinishedAt,
            $policy['max_run_seconds'],
            $policy['maximum_authorization_ttl_seconds'],
            $policy['maximum_evidence_ttl_seconds'],
            $policy['maximum_signing_delay_seconds'],
            $trustedSigners,
            $trustedConsumptionAuthorities,
        ))->toThrow(RuntimeException::class, 'brokered effect-execution verifier is not provisioned')
            ->and(fakturowniaArtifactAttestationIsValid(
                $repositoryRoot,
                $liveArtifact,
                $fixtureSha256,
                $fixture,
                $trustedSigners,
                $policy,
                new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
                $trustedConsumptionAuthorities,
            ))->toBeFalse()
            ->and(fakturowniaPassedEvidenceIsValid(
                $repositoryRoot,
                'invoice.ksef.ensure_accepted',
                $liveEvidence,
                $allowlist,
                $trustedSigners,
                $policy,
                new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
                $trustedConsumptionAuthorities,
            ))->toBeFalse()
            ->and(fakturowniaPassedEvidenceSetIsValid(
                $repositoryRoot,
                ['invoice.ksef.ensure_accepted' => $liveEvidence],
                $allowlist,
                $trustedSigners,
                $policy,
                new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
                $trustedConsumptionAuthorities,
            ))->toBeFalse()
            ->and(fakturowniaPassedEvidenceIsValid(
                $repositoryRoot,
                'invoice.pdf.ksef_revision.observe',
                $liveEvidence,
                $allowlist,
                $trustedSigners,
                $policy,
                new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
                $trustedConsumptionAuthorities,
            ))->toBeFalse()
            ->and(fakturowniaPassedEvidenceSetIsValid(
                $repositoryRoot,
                [
                    'invoice.ksef.ensure_accepted' => $liveEvidence,
                    'invoice.pdf.ksef_revision.observe' => $liveEvidence,
                ],
                $allowlist,
                $trustedSigners,
                $policy,
                new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
                $trustedConsumptionAuthorities,
            ))->toBeFalse();

        $missingOriginsEnvelope = $liveEvidenceEnvelope;
        unset($missingOriginsEnvelope['origins']);
        $missingOriginsEvidence = signFakturowniaTestEnvelope($missingOriginsEnvelope, $secretKey);
        $missingOriginsContents = LiveEvidenceAttestationGuard::canonicalJson($missingOriginsEvidence);
        file_put_contents($attestationPath, $missingOriginsContents);
        $missingOriginsArtifact = $artifact;
        $missingOriginsArtifact['attestation']['sha256'] = hash('sha256', $missingOriginsContents);

        expect(fakturowniaPassedEvidenceIsValid(
            $repositoryRoot,
            'invoice.ksef.ensure_accepted',
            [...$evidence, 'artifacts' => [$missingOriginsArtifact]],
            $allowlist,
            $trustedSigners,
            $policy,
            new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
            $trustedConsumptionAuthorities,
        ))->toBeFalse();

        $tamperedEvidence = $signedEvidence;
        $tamperedEvidence['signature'] = base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));
        $tamperedContents = LiveEvidenceAttestationGuard::canonicalJson($tamperedEvidence);
        file_put_contents($attestationPath, $tamperedContents);
        $tamperedArtifact = $artifact;
        $tamperedArtifact['attestation']['sha256'] = hash('sha256', $tamperedContents);

        expect(fakturowniaPassedEvidenceIsValid(
            $repositoryRoot,
            'invoice.ksef.ensure_accepted',
            [...$evidence, 'artifacts' => [$tamperedArtifact]],
            $allowlist,
            $trustedSigners,
            $policy,
            new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
            $trustedConsumptionAuthorities,
        ))->toBeFalse();

        file_put_contents($attestationPath, $attestationContents);
        file_put_contents($fixturePath, $fixtureContents.' ');

        expect(fakturowniaPassedEvidenceIsValid(
            $repositoryRoot,
            'invoice.ksef.ensure_accepted',
            $evidence,
            $allowlist,
            $trustedSigners,
            $policy,
            new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
            $trustedConsumptionAuthorities,
        ))->toBeFalse();

        file_put_contents($fixturePath, $fixtureContents);
        $manifestPath = $repositoryRoot.'/tests/Contract/Support/LiveEvidenceAttestationGuard.php';
        $manifestContents = file_get_contents($manifestPath);

        if (! is_string($manifestContents)) {
            throw new RuntimeException('Could not read a protocol test manifest file.');
        }

        file_put_contents($manifestPath, $manifestContents."\n// dirty\n");

        expect(fakturowniaPassedEvidenceIsValid(
            $repositoryRoot,
            'invoice.ksef.ensure_accepted',
            $evidence,
            $allowlist,
            $trustedSigners,
            $policy,
            new DateTimeImmutable('2026-08-25T10:02:00.000000Z'),
            $trustedConsumptionAuthorities,
        ))->toBeFalse();
    } finally {
        sodium_memzero($secretKey);
        sodium_memzero($authoritySecretKey);
        removeFakturowniaTestTree($claimRoot);
        removeFakturowniaTestTree($repositoryRoot);
    }
});

it('rejects traversal and symlinks in every evidence path component', function (): void {
    $root = sys_get_temp_dir().'/fakturownia-capability-path-'.bin2hex(random_bytes(8));
    $fixtureDirectory = $root.'/tests/Fixtures/Contract';
    $fixturePath = $fixtureDirectory.'/evidence.json';
    $symlinkPath = $fixtureDirectory.'/evidence-link.json';
    $hardlinkPath = $fixtureDirectory.'/evidence-hardlink.json';

    expect(mkdir($fixtureDirectory, 0700, true))->toBeTrue()
        ->and(file_put_contents($fixturePath, '{}'))->toBeInt()
        ->and(symlink($fixturePath, $symlinkPath))->toBeTrue();

    try {
        expect(resolveFakturowniaEvidencePath($root, 'tests/Fixtures/Contract/evidence.json'))->toBe(realpath($fixturePath))
            ->and(resolveFakturowniaEvidencePath($root, '../evidence.json'))->toBeNull()
            ->and(resolveFakturowniaEvidencePath($root, 'tests/Fixtures/Contract/../../evidence.json'))->toBeNull()
            ->and(resolveFakturowniaEvidencePath($root, 'tests/Fixtures/Contract/evidence-link.json'))->toBeNull()
            ->and(resolveFakturowniaEvidencePath($root, '/tests/Fixtures/Contract/evidence.json'))->toBeNull();

        expect(link($fixturePath, $hardlinkPath))->toBeTrue()
            ->and(resolveFakturowniaEvidencePath($root, 'tests/Fixtures/Contract/evidence.json'))->toBeNull()
            ->and(resolveFakturowniaEvidencePath($root, 'tests/Fixtures/Contract/evidence-hardlink.json'))->toBeNull();
    } finally {
        if (is_file($hardlinkPath)) {
            unlink($hardlinkPath);
        }

        unlink($symlinkPath);
        unlink($fixturePath);
        rmdir($fixtureDirectory);
        rmdir($root.'/tests/Fixtures');
        rmdir($root.'/tests');
        rmdir($root);
    }
});

it('rejects arbitrary hash-matching JSON unknown contracts and non-passed status', function (): void {
    $root = sys_get_temp_dir().'/fakturownia-capability-contract-'.bin2hex(random_bytes(8));
    $fixtureDirectory = $root.'/tests/Fixtures/Contract';
    $relativePath = 'tests/Fixtures/Contract/arbitrary.json';
    $fixturePath = $root.'/'.$relativePath;
    $allowlist = fakturowniaEvidenceContractAllowlist();
    $matrix = loadFakturowniaCapabilityMatrix();
    $attestationPolicy = $matrix['attestation_policy'];
    $trustedSigners = [];
    $now = new DateTimeImmutable('2026-08-25T12:00:00.000000Z');

    expect(mkdir($fixtureDirectory, 0700, true))->toBeTrue()
        ->and(file_put_contents($fixturePath, '{"complete":true}'))->toBeInt();

    $artifact = [
        'contract' => 'fakturownia-invoice-identity-s0.3-v1',
        'path' => $relativePath,
        'sha256' => hash_file('sha256', $fixturePath),
        'authorizations' => [[
            'profile' => 'invoice_identity',
            'path' => 'tests/Fixtures/Contract/arbitrary.authorization-invoice_identity.json',
            'sha256' => str_repeat('0', 64),
        ]],
        'attestation' => [
            'path' => 'tests/Fixtures/Contract/arbitrary.attestation.json',
            'sha256' => str_repeat('0', 64),
        ],
    ];
    $wrongContract = [...$artifact, 'contract' => 'unknown-contract-v1'];
    $wrongHash = [...$artifact, 'sha256' => str_repeat('0', 64)];
    $pendingEvidence = [
        'required' => true,
        'status' => 'pending_live',
        'requirements' => ['semantic_fixture_validation'],
        'artifacts' => [$artifact],
    ];
    $passedEvidence = [...$pendingEvidence, 'status' => 'passed'];

    try {
        expect(validatedFakturowniaEvidenceArtifact($root, $artifact, $allowlist, $trustedSigners, $attestationPolicy, $now))->toBeNull()
            ->and(validatedFakturowniaEvidenceArtifact($root, $wrongContract, $allowlist, $trustedSigners, $attestationPolicy, $now))->toBeNull()
            ->and(validatedFakturowniaEvidenceArtifact($root, $wrongHash, $allowlist, $trustedSigners, $attestationPolicy, $now))->toBeNull()
            ->and(fakturowniaPassedEvidenceIsValid($root, 'invoice.vat.issue', $pendingEvidence, $allowlist, $trustedSigners, $attestationPolicy, $now))->toBeFalse()
            ->and(fakturowniaPassedEvidenceIsValid($root, 'invoice.vat.issue', $passedEvidence, $allowlist, $trustedSigners, $attestationPolicy, $now))->toBeFalse()
            ->and(fakturowniaPassedEvidenceIsValid($root, 'invoice.correction.issue', $passedEvidence, $allowlist, $trustedSigners, $attestationPolicy, $now))->toBeFalse();
    } finally {
        unlink($fixturePath);
        rmdir($fixtureDirectory);
        rmdir($root.'/tests/Fixtures');
        rmdir($root.'/tests');
        rmdir($root);
    }
});

it('keeps S0.3 and S0.4 implementation gates closed and models ProviderAutoSend only in SPI 0.2', function (): void {
    $matrix = loadFakturowniaCapabilityMatrix();
    $capabilities = fakturowniaCapabilitiesById($matrix);
    $autoSend = $matrix['spi_constraints']['provider_auto_send_ensure_accepted'];

    expect($capabilities['invoice.vat.issue']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['invoice.vat.issue']['live_evidence']['artifacts'])->toBe([])
        ->and($capabilities['invoice.vat.issue']['live_evidence']['requirements'])
        ->toContain('native_root_supervisor_and_secret_broker', 'signed_effect_execution_receipts')
        ->and($capabilities['invoice.vat.issue']['blocks_vat_pilot'])->toBeTrue()
        ->and($capabilities['invoice.ksef.ensure_accepted']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['invoice.ksef.ensure_accepted']['semantic_writes']['effects'])->toBe(['ksef_explicit_submit'])
        ->and($capabilities['invoice.ksef.ensure_accepted']['live_evidence']['artifacts'])->toBe([])
        ->and($capabilities['invoice.ksef.ensure_accepted']['live_evidence']['requirements'])
        ->toContain(
            'dedicated_capability_run_id_nonce_cursor',
            'signed_effect_execution_receipts',
            'operator_attested_settings_hmac',
            'exact_connection_fingerprint',
            'bounded_settings_evidence_ttl',
            'operator_attestation_internal_utc_and_fixed_600_second_ttl',
            'settings_evidence_serialization_forbidden',
            'initial_explicit_sdk_doctor_preflight',
            'sealed_online_settings_reader',
            'succeeded_status_requires_bounded_gov_id',
            'demo_error_statuses_cannot_satisfy_success',
        )
        ->and($capabilities['invoice.ksef.ensure_accepted']['limitations'])
        ->toContain(
            'S5.1 operator-attested settings evidence is local preflight evidence only and never authorizes an explicit send',
            'S5.1 doctor permitsExplicitSend remains false even when local settings preflight passes',
            'The sealed online settings reader required for explicit-send promotion is not implemented',
        )
        ->and($capabilities['invoice.pdf.ksef_revision.observe']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['invoice.pdf.ksef_revision.observe']['live_evidence']['artifacts'])->toBe([])
        ->and($capabilities['invoice.pdf.ksef_revision.observe']['live_evidence']['requirements'])
        ->toContain('dedicated_capability_run_id_nonce_cursor', 'brokered_probe_setup_execution_receipts')
        ->and($capabilities['invoice.pdf.download']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['invoice.ksef.ensure_accepted']['policy']['ownership'])->toBe('explicit_sdk')
        ->and($matrix['spi_constraints']['provider_spi'])->toBe('0.1')
        ->and($autoSend)->toBe([
            'spi_0_1' => 'forbidden',
            'required_spi' => '0.2',
            'required_contract' => 'SuccessEffectPolicy',
            'required_values' => ['must_be_applied_by_operation', 'may_be_observed_externally', 'read_only'],
        ]);
});

it('keeps RT3 read surfaces separate from managed artifact and sync capabilities', function (): void {
    $capabilities = fakturowniaCapabilitiesById(loadFakturowniaCapabilityMatrix());

    expect($capabilities['invoice.pdf.stream']['milestone'])->toBe('RT-3/S3.6')
        ->and($capabilities['invoice.pdf.stream']['policy']['ownership'])->toBe('sdk_read_client')
        ->and($capabilities['invoice.pdf.stream']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['invoice.pdf.ksef_revision.observe']['milestone'])->toBe('0.2')
        ->and($capabilities['invoice.pdf.ksef_revision.observe']['policy']['ownership'])->toBe('contract_probe')
        ->and($capabilities['invoice.pdf.ksef_revision.observe']['identity']['fingerprint'])->toBe([
            'mime',
            'size',
            'ephemeral_run_hmac_sha256',
            'before_after_equality',
        ])
        ->and($capabilities['invoice.pdf.ksef_revision.observe']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['invoice.pdf.download']['milestone'])->toBe('RT-6/S6.1-2')
        ->and($capabilities['invoice.pdf.download']['policy']['ownership'])->toBe('sdk_artifact_store')
        ->and($capabilities['invoice.pdf.download']['recovery']['safe_retry'])->toBe('content_addressed_put_after_rt6_gate')
        ->and($capabilities['invoice.pdf.download']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['invoice.pdf.download']['live_evidence']['artifacts'])->toBe([])
        ->and($capabilities['invoice.pdf.download']['live_evidence']['requirements'])
        ->toContain(
            'versioned_rt6_artifact_evidence_contract_and_guard',
            'shared_database_artifact_lock',
            'lease_owner_revalidation',
            'lease_renewal_before_critical_sections',
            'sealed_purge_authority_permit',
            'artifact_value_object_native_unserialize_denied',
            'terminal_tombstone',
            'immutable_purge_deadline',
            'ready_to_deleted_transition_forbidden',
            'truncate_bypass_forbidden',
            'retention_policy_mismatch_blocks_delete',
            'doctor_complete_pagination_before_pass',
            'database_lock_schema_and_search_path_binding',
            'database_lock_same_repository_writer_connection',
            'ciphertext_sha256_integrity',
            'retention_and_crash_chaos',
        )
        ->and($capabilities['invoice.pdf.download']['limitations'])->toContain(
            'S6 content-addressed store and maintenance code exists, but production remote-PDF integration, crash and retention evidence, and a reviewed capability digest are not published',
        )
        ->and($capabilities['payment.read.list']['policy']['ownership'])->toBe('sdk_read_client')
        ->and($capabilities['payment.sync']['classification'])->toBe('deferred')
        ->and($capabilities['invoice.correction.issue']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['invoice.correction.issue']['live_evidence']['artifacts'])->toBe([])
        ->and($capabilities['invoice.correction.issue']['live_evidence']['requirements'])
        ->toContain(
            'native_root_supervisor_and_secret_broker',
            'independently_verified_launch_manifest',
            'broker_owned_authorization_cas',
            'signed_effect_execution_receipts',
            'dedicated_correction_evidence_contract',
            'typed_correction_payload_and_response',
            'exact_original_invoice_link',
        )
        ->and($capabilities['invoice.correction.issue']['limitations'])->toContain(
            'S7.1 typed correction DTO and mapping code exists, but no production execution capability, live fixture or brokered effect receipt is published',
        );
});

it('distinguishes implemented RT3 read code from unproven production capabilities', function (): void {
    $capabilities = fakturowniaCapabilitiesById(loadFakturowniaCapabilityMatrix());
    $typedReadLimitation = 'RT-3 typed read implementation exists, but the production capability gate remains closed until the exact response/error contract fixture and reviewed evidence digest are pinned';
    $binaryStreamLimitation = 'The bounded RT-3 stream implementation exists, but the production capability gate remains closed until the binary contract fixture and reviewed evidence digest are pinned';

    foreach (['invoice.read.list', 'invoice.read.get', 'client.read.list', 'client.read.get', 'product.read.list', 'product.read.get'] as $capabilityId) {
        expect($capabilities[$capabilityId]['limitations'])->toContain($typedReadLimitation)
            ->and($capabilities[$capabilityId]['live_evidence']['requirements'])
            ->toContain('versioned_rt3_read_evidence_contract_and_guard')
            ->and($capabilities[$capabilityId]['live_evidence']['status'])->toBe('pending_implementation')
            ->and($capabilities[$capabilityId]['live_evidence']['artifacts'])->toBe([]);
    }

    expect($capabilities['invoice.read.list']['recovery']['reconciliation'])
        ->toBe('pagination_stable_order_duplicate_page_guard_and_complete_exact_oid_scan')
        ->and($capabilities['invoice.read.list']['live_evidence']['requirements'])
        ->toContain('exact_oid_query_tuple', 'complete_exact_oid_pagination', 'strict_decimal_scalar_provenance')
        ->and($capabilities['invoice.read.list']['limitations'])
        ->toContain('The exact OID query path cannot authorize oid_unique until a dedicated reviewed live artifact is pinned');

    foreach (['invoice.pdf.stream', 'invoice.attachments.zip.stream', 'invoice.ksef.xml.stream', 'invoice.ksef.upo.stream'] as $capabilityId) {
        expect($capabilities[$capabilityId]['limitations'])->toContain($binaryStreamLimitation)
            ->and($capabilities[$capabilityId]['live_evidence']['requirements'])
            ->toContain('versioned_rt3_read_evidence_contract_and_guard')
            ->and($capabilities[$capabilityId]['live_evidence']['status'])->toBe('pending_implementation')
            ->and($capabilities[$capabilityId]['live_evidence']['artifacts'])->toBe([]);
    }

    expect($capabilities['payment.read.list']['limitations'])->toContain(
        'The typed RT-3 payment-list implementation exists, but the production capability gate remains closed until the exact response/error contract fixture and reviewed evidence digest are pinned',
    )
        ->and($capabilities['payment.read.list']['live_evidence']['requirements'])
        ->toContain('versioned_rt3_read_evidence_contract_and_guard')
        ->and($capabilities['payment.read.list']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['payment.read.list']['live_evidence']['artifacts'])->toBe([])
        ->and($capabilities['payment.read.get']['limitations'])->toContain(
            'The exact singular route and typed request descriptor exist, but the public resource intentionally denies dispatch until the response/error contracts, contract fixture, and reviewed evidence digest are pinned',
        )
        ->and($capabilities['payment.read.get']['live_evidence']['requirements'])
        ->toContain('versioned_rt3_read_evidence_contract_and_guard')
        ->and($capabilities['payment.read.get']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['payment.read.get']['live_evidence']['artifacts'])->toBe([]);
});

it('does not let deferred payment lanes or webhooks block the VAT pilot', function (): void {
    $capabilities = fakturowniaCapabilitiesById(loadFakturowniaCapabilityMatrix());
    $deferredIds = ['payment.sync', 'payment.create', 'payment.update', 'payment.delete', 'webhook.invoice.receive'];

    foreach ($deferredIds as $id) {
        expect($capabilities[$id]['classification'])->toBe('deferred')
            ->and($capabilities[$id]['live_evidence']['status'])->toBe('deferred')
            ->and($capabilities[$id]['blocks_vat_pilot'])->toBeFalse();
    }

    foreach ($capabilities as $capability) {
        if ($capability['classification'] === 'deferred') {
            expect($capability['blocks_vat_pilot'])->toBeFalse();
        }
    }

    expect($capabilities['payment.read.list']['milestone'])->toBe('RT-3/S3.4')
        ->and($capabilities['payment.read.list']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['payment.read.list']['blocks_vat_pilot'])->toBeFalse()
        ->and($capabilities['payment.read.get']['milestone'])->toBe('RT-3/S3.4')
        ->and($capabilities['payment.read.get']['classification'])->toBe('state-verifiable')
        ->and($capabilities['payment.read.get']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['payment.read.get']['blocks_vat_pilot'])->toBeFalse();
});

it('freezes the officially confirmed payment cancel attachment and KSeF artifact routes fail closed', function (): void {
    $capabilities = fakturowniaCapabilitiesById(loadFakturowniaCapabilityMatrix());

    expect($capabilities['payment.read.get']['transport'])
        ->toBe(['method' => 'GET', 'endpoint' => '/banking/payment/{id}.json'])
        ->and($capabilities['payment.read.get']['classification'])->toBe('state-verifiable')
        ->and($capabilities['payment.read.get']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['payment.update']['transport'])
        ->toBe(['method' => 'PATCH', 'endpoint' => '/banking/payments/{id}.json'])
        ->and($capabilities['payment.delete']['transport'])
        ->toBe(['method' => 'DELETE', 'endpoint' => '/banking/payments/{id}.json'])
        ->and($capabilities['invoice.cancel']['transport'])
        ->toBe(['method' => 'POST', 'endpoint' => '/invoices/cancel.json'])
        ->and($capabilities['invoice.cancel']['semantic_writes']['effects'])->toBe(['invoice_cancel'])
        ->and($capabilities['invoice.cancel']['live_evidence']['status'])->toBe('deferred')
        ->and($capabilities['invoice.attachment.credentials.get']['transport'])
        ->toBe(['method' => 'GET', 'endpoint' => '/invoices/{id}/get_new_attachment_credentials.json'])
        ->and($capabilities['invoice.attachment.credentials.get']['live_evidence']['requirements'])
        ->toBe(['credential_response_contract', 'dynamic_multipart_target_contract', 'credential_redaction'])
        ->and($capabilities['invoice.attachment.binary.upload']['transport'])
        ->toBe(['method' => 'POST', 'endpoint' => 'provider_issued_temporary_multipart_upload_target'])
        ->and($capabilities['invoice.attachment.finalize']['transport'])
        ->toBe(['method' => 'POST', 'endpoint' => '/invoices/{id}/add_attachment.json']);

    foreach ([
        'invoice.ksef.xml.stream',
        'invoice.ksef.upo.stream',
        'invoice.ksef.xml.download',
        'invoice.ksef.upo.download',
    ] as $capabilityId) {
        expect($capabilities[$capabilityId]['live_evidence']['requirements'])
            ->toContain('http_302_ready_redirect', 'http_404_missing');
    }

    expect($capabilities['invoice.ksef.upo.stream']['limitations'])
        ->toContain('A separate PDF UPO endpoint is unconfirmed and remains unpublished')
        ->and($capabilities['invoice.ksef.upo.download']['limitations'])
        ->toContain('A separate PDF UPO endpoint is unconfirmed and remains unpublished')
        ->and($capabilities)->not->toHaveKey('invoice.ksef.upo.pdf.download')
        ->and($capabilities['webhook.invoice.receive']['policy']['validation'])
        ->toBe('signature_retry_ack_contracts_unverified')
        ->and($capabilities['webhook.invoice.receive']['live_evidence']['status'])->toBe('deferred')
        ->and($capabilities['invoice.update']['policy']['validation'])
        ->toBe('block_after_ksef_acceptance_and_partial_number_update_contract_unverified')
        ->and($capabilities['invoice.update']['live_evidence']['requirements'])
        ->toContain('partial_invoice_number_update_contract')
        ->and($capabilities['invoice.update']['live_evidence']['status'])->toBe('deferred');
});

it('pins versioned RT3 and RT6 diagnostic guards without making them passed evidence authorities', function (): void {
    $matrix = loadFakturowniaCapabilityMatrix();
    $capabilities = fakturowniaCapabilitiesById($matrix);
    $passedEvidenceContracts = array_keys(fakturowniaEvidenceContractAllowlist());

    foreach ([
        'invoice.read.list',
        'invoice.read.get',
        'client.read.list',
        'client.read.get',
        'product.read.list',
        'product.read.get',
        'payment.read.list',
        'payment.read.get',
        'invoice.pdf.stream',
        'invoice.attachments.zip.stream',
        'invoice.ksef.xml.stream',
        'invoice.ksef.upo.stream',
    ] as $capabilityId) {
        expect($capabilities[$capabilityId]['live_evidence']['requirements'])
            ->toContain('versioned_rt3_read_evidence_contract_and_guard')
            ->and($capabilities[$capabilityId]['live_evidence']['status'])->toBe('pending_implementation')
            ->and($capabilities[$capabilityId]['live_evidence']['artifacts'])->toBe([]);
    }

    expect($capabilities['invoice.pdf.download']['live_evidence']['requirements'])
        ->toContain('versioned_rt6_artifact_evidence_contract_and_guard')
        ->and($capabilities['invoice.pdf.download']['live_evidence']['status'])->toBe('pending_implementation')
        ->and($capabilities['invoice.pdf.download']['live_evidence']['artifacts'])->toBe([])
        ->and(Rt3ReadEvidenceContract::Version)->toBe('1')
        ->and(Rt3ReadEvidenceContract::Disposition)->toBe('diagnostic_only_not_runtime_authority')
        ->and(Rt6ArtifactEvidenceContract::Version)->toBe('1')
        ->and(Rt6ArtifactEvidenceContract::Disposition)->toBe('diagnostic_only_not_runtime_authority')
        ->and($passedEvidenceContracts)->not->toContain(
            Rt3ReadEvidenceContract::Contract,
            Rt6ArtifactEvidenceContract::Contract,
        );
});

it('contains no credentials PII or tenant URLs', function (): void {
    $path = dirname(__DIR__, 2).'/docs/capability-matrix.json';
    $contents = file_get_contents($path);
    $matrix = loadFakturowniaCapabilityMatrix();

    expect($contents)->toBeString()
        ->and(fakturowniaSensitiveMatrixPaths($matrix))->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['access_token' => 'opaque']))->not->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['client_secret' => 'opaque']))->not->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['private_key' => 'opaque']))->not->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['cookie' => 'opaque']))->not->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['account_id' => '123']))->not->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['limitations' => ['OpaqueSecret123456789012345678901234567890']]))->not->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['transport' => ['endpoint' => 'OpaqueSecret123456789012345678901234567890']]))->not->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['requirements' => ['abcdef0123456789abcdef0123456789abcdef0123456789']]))->not->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['sha256' => str_repeat('a', 64)]))->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['launcher_source_sha256' => str_repeat('a', 64)]))->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['launcher_source_sha256' => 'OpaqueSecret123456789012345678901234567890']))->not->toBe([])
        ->and(fakturowniaSensitiveMatrixPaths(['tenant_hmac_sha256' => str_repeat('b', 64)]))->toBe([]);
});

it('uses the canonical Cieplik206 vendor namespace throughout package PHP', function (): void {
    $root = dirname(__DIR__, 2);
    $phpFiles = [];

    foreach (['src', 'tests'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $phpFiles[] = $file->getPathname();
            }
        }
    }

    expect($phpFiles)->not->toBeEmpty();

    foreach ($phpFiles as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Could not read a package PHP file for namespace validation.');
        }

        preg_match_all('/(?:namespace|use)\s+([A-Za-z0-9]+\\\\Fakturownia\\\\)/i', $contents, $matches);

        foreach ($matches[1] as $vendorPrefix) {
            expect($vendorPrefix)->toBe('Cieplik206\\Fakturownia\\');
        }
    }

    preg_match_all(
        '/(?:namespace|use)\s+([A-Za-z0-9]+\\\\Fakturownia\\\\)/i',
        '<?php namespace CiePlik206\\Fakturownia\\Broken;',
        $mixedCaseMatches,
    );

    expect($mixedCaseMatches[1])->toBe(['CiePlik206\\Fakturownia\\'])
        ->and($mixedCaseMatches[1][0])->not->toBe('Cieplik206\\Fakturownia\\');

    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['autoload']['psr-4'])->toHaveKey('Cieplik206\\Fakturownia\\')
        ->and($composer['autoload-dev']['psr-4'])->toHaveKey('Cieplik206\\Fakturownia\\Tests\\');
});
