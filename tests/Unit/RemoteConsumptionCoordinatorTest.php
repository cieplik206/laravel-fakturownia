<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredExecutionRequiredException;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionClaimRequest;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionDisposition;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionReceiptEnvelope;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\LiveEffectDescriptor;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\PendingLiteralRemoteConsumptionClaim;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\PinnedLiveProbeTrustStore;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\PinnedRepositorySnapshotReader;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\RemoteConsumptionAuthorityPolicy;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\RemoteConsumptionAuthorityPolicyStore;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\RemoteConsumptionClaimRequest;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\RemoteConsumptionCoordinator;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\SignedLiveProbeAuthorization;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\VerifiedLaunchManifest;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\VerifiedRemoteConsumptionGrant;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Senders\GuzzleSender;

/**
 * @return array{
 *     root: non-empty-string,
 *     authorization: array{envelope: array<string, mixed>, signature: string},
 *     operator_secret_key: non-empty-string,
 *     authority_secret_key: non-empty-string,
 *     authority_public_key: non-empty-string,
 *     launch_manifest_sha256: non-empty-string,
 *     policy: RemoteConsumptionAuthorityPolicy
 * }
 */
function fakturowniaRemoteConsumptionFixture(): array
{
    $root = sys_get_temp_dir().'/fakturownia-remote-consumption-'.bin2hex(random_bytes(12));
    $fixtures = $root.'/tests/Fixtures/Contract';

    if (! mkdir($fixtures, 0700, true) && ! is_dir($fixtures)) {
        throw new RuntimeException('Unable to create the remote-consumption test repository.');
    }

    $operatorKeyPair = sodium_crypto_sign_keypair();
    $operatorSecretKey = sodium_crypto_sign_secretkey($operatorKeyPair);
    $operatorPublicKey = sodium_crypto_sign_publickey($operatorKeyPair);
    $authorityKeyPair = sodium_crypto_sign_keypair();
    $authoritySecretKey = sodium_crypto_sign_secretkey($authorityKeyPair);
    $authorityPublicKey = sodium_crypto_sign_publickey($authorityKeyPair);
    $encodedAuthorityPublicKey = base64_encode($authorityPublicKey);
    $policy = new RemoteConsumptionAuthorityPolicy(
        'root-consumption-authority',
        $encodedAuthorityPublicKey,
        hash('sha256', $authorityPublicKey),
        'root-consumption-ledger',
        str_repeat('a', 64),
        'https://live-evidence-authority.example'.RemoteConsumptionAuthorityPolicy::ClaimPath,
        2,
        5,
        16_384,
    );

    fakturowniaRemoteWriteJson($fixtures.'/trusted-operator-signers.json', [
        'contract' => PinnedLiveProbeTrustStore::Contract,
        'version' => PinnedLiveProbeTrustStore::Version,
        'signers' => [
            [
                'id' => 'root-operator',
                'algorithm' => SignedLiveProbeAuthorization::Algorithm,
                'public_key' => base64_encode($operatorPublicKey),
                'roles' => ['operator_attestation'],
            ],
            [
                'id' => $policy->authorityId,
                'algorithm' => SignedLiveProbeAuthorization::Algorithm,
                'public_key' => $encodedAuthorityPublicKey,
                'roles' => ['consumption_authority'],
            ],
        ],
    ]);
    fakturowniaRemoteWriteJson($fixtures.'/trusted-consumption-authorities.json', [
        'contract' => RemoteConsumptionAuthorityPolicyStore::Contract,
        'version' => RemoteConsumptionAuthorityPolicyStore::Version,
        'authorities' => [$policy->toArray()],
    ]);

    $launchManifestSha256 = str_repeat('b', 64);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $envelope = [
        'contract' => SignedLiveProbeAuthorization::Contract,
        'version' => SignedLiveProbeAuthorization::Version,
        'algorithm' => SignedLiveProbeAuthorization::Algorithm,
        'signer_id' => 'root-operator',
        'issued_at' => $now->modify('-30 seconds')->format('Y-m-d\TH:i:s.u\Z'),
        'expires_at' => $now->modify('+5 minutes')->format('Y-m-d\TH:i:s.u\Z'),
        'evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
        'challenge' => base64_encode(random_bytes(32)),
        'harness' => [
            'repository_commit' => str_repeat('c', 40),
            'code_sha256' => str_repeat('d', 64),
            'launch_manifest_sha256' => $launchManifestSha256,
        ],
        'target' => [
            'environment' => 'demo_pl',
            'profile' => 'invoice_identity',
            'tenant_hmac_sha256' => str_repeat('e', 64),
            'account_hmac_sha256' => str_repeat('f', 64),
        ],
        'commitments' => [
            'scheme' => SignedLiveProbeAuthorization::CommitmentScheme,
            'configuration_hmac_sha256' => str_repeat('1', 64),
            'policy_hmac_sha256' => str_repeat('2', 64),
            'safety_hmac_sha256' => str_repeat('3', 64),
            'templates_hmac_sha256' => str_repeat('4', 64),
        ],
        'consumption' => [
            'authority_id' => $policy->authorityId,
            'authority_policy_sha256' => $policy->sha256(),
            'store_id' => $policy->storeId,
            'store_identity_sha256' => $policy->storeIdentitySha256,
            'run_id' => bin2hex(random_bytes(16)),
            'replay_policy' => ConsumptionClaimRequest::ReplayPolicy,
        ],
        'limits' => [
            'maximum_effects' => 1,
            'request_timeout_ms' => 5_000,
        ],
    ];
    $authorization = [
        'envelope' => $envelope,
        'signature' => base64_encode(sodium_crypto_sign_detached(
            CanonicalCodec::encode($envelope),
            $operatorSecretKey,
        )),
    ];

    return [
        'root' => $root,
        'authorization' => $authorization,
        'operator_secret_key' => $operatorSecretKey,
        'authority_secret_key' => $authoritySecretKey,
        'authority_public_key' => $authorityPublicKey,
        'launch_manifest_sha256' => $launchManifestSha256,
        'policy' => $policy,
    ];
}

/** @param array<string, mixed> $value */
function fakturowniaRemoteWriteJson(string $path, array $value): void
{
    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    if (file_put_contents($path, $json."\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to write a remote-consumption test fixture.');
    }
}

/**
 * @param array{
 *     root: non-empty-string,
 *     authorization: array{envelope: array<string, mixed>, signature: string},
 *     operator_secret_key: non-empty-string,
 *     authority_secret_key: non-empty-string,
 *     authority_public_key: non-empty-string,
 *     launch_manifest_sha256: non-empty-string,
 *     policy: RemoteConsumptionAuthorityPolicy
 * } $fixture
 */
function fakturowniaRemotePending(array $fixture): PendingLiteralRemoteConsumptionClaim
{
    return RemoteConsumptionCoordinator::beginLiteralInMemoryClaimNow(
        $fixture['root'],
        [$fixture['authorization']],
        600,
        30,
        60,
        300,
    );
}

/**
 * @return array{envelope: array<string, mixed>, signature: string}
 */
function fakturowniaRemoteSignedReceipt(
    ConsumptionClaimRequest $request,
    string $authoritySecretKey,
    ConsumptionDisposition $disposition = ConsumptionDisposition::FreshDirectGrant,
    int $ttlSeconds = 30,
): array {
    if ($authoritySecretKey === '') {
        throw new InvalidArgumentException('The remote consumption authority signing key cannot be empty.');
    }

    $issuedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $envelope = [
        'contract' => ConsumptionReceiptEnvelope::Contract,
        'version' => ConsumptionReceiptEnvelope::Version,
        'algorithm' => ConsumptionReceiptEnvelope::Algorithm,
        'signer_id' => $request->authorityId,
        'issued_at' => $issuedAt->format('Y-m-d\TH:i:s.u\Z'),
        'expires_at' => $issuedAt->modify("+{$ttlSeconds} seconds")->format('Y-m-d\TH:i:s.u\Z'),
        'claim_cursor' => [
            'store_id' => $request->storeId,
            'sequence' => '1',
        ],
        'disposition' => $disposition->value,
        'claim_request' => $request->toArray(),
        'claim_request_sha256' => $request->sha256(),
    ];

    return [
        'envelope' => $envelope,
        'signature' => base64_encode(sodium_crypto_sign_detached(
            CanonicalCodec::encode($envelope),
            $authoritySecretKey,
        )),
    ];
}

/**
 * @param array{
 *     root: non-empty-string,
 *     authorization: array{envelope: array<string, mixed>, signature: string},
 *     operator_secret_key: non-empty-string,
 *     authority_secret_key: non-empty-string,
 *     authority_public_key: non-empty-string,
 *     launch_manifest_sha256: non-empty-string,
 *     policy: RemoteConsumptionAuthorityPolicy
 * } $fixture
 */
function fakturowniaRemoteDestroyFixture(array $fixture): void
{
    $operatorSecretKey = $fixture['operator_secret_key'];
    $authoritySecretKey = $fixture['authority_secret_key'];
    sodium_memzero($operatorSecretKey);
    sodium_memzero($authoritySecretKey);
    $root = $fixture['root'];

    if (! is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isLink() || ! $entry->isDir()) {
            unlink($entry->getPathname());

            continue;
        }

        rmdir($entry->getPathname());
    }

    rmdir($root);
}

/**
 * @param  array<string, mixed>  $receipt
 * @return array<string, int|string>
 */
function fakturowniaLiveEffectDescriptorDocument(
    ConsumptionClaimRequest $request,
    array $receipt,
): array {
    return [
        'contract' => LiveEffectDescriptor::Contract,
        'version' => LiveEffectDescriptor::Version,
        'evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
        'run_id' => $request->runId,
        'effect_id' => str_repeat('5', 32),
        'effect_sequence' => 1,
        'profile' => 'invoice_identity',
        'target_key' => 'primary',
        'capability' => 'invoice.vat.issue',
        'semantic_effect' => 'invoice_create',
        'http_method' => 'POST',
        'endpoint_template' => '/invoices.json',
        'commitment_scheme' => SignedLiveProbeAuthorization::CommitmentScheme,
        'target_origin_hmac_sha256' => str_repeat('6', 64),
        'operation_identity_hmac_sha256' => str_repeat('7', 64),
        'request_body_hmac_sha256' => str_repeat('8', 64),
        'request_body_size_bytes' => 512,
        'request_body_policy' => 'required_non_empty',
        'launch_manifest_sha256' => $request->harness['launch_manifest_sha256'],
        'supervisor_attestation_sha256' => str_repeat('9', 64),
        'broker_policy_sha256' => str_repeat('a', 64),
        'authorization_set_sha256' => $request->authorizationSetSha256,
        'authorization_bundle_sha256' => str_repeat('b', 64),
        'probe_plan_sha256' => str_repeat('c', 64),
        'claim_request_sha256' => $request->sha256(),
        'consumption_receipt_sha256' => hash('sha256', CanonicalCodec::encode($receipt)),
        'claim_nonce' => $request->claimNonce,
        'run_started_at' => $request->runStartedAt,
        'connect_timeout_ms' => 2_000,
        'request_timeout_ms' => 5_000,
        'maximum_response_bytes' => 65_536,
    ];
}

it('verifies the offline literal CAS protocol without ever granting a live effect', function (): void {
    $fixture = fakturowniaRemoteConsumptionFixture();

    try {
        $before = hrtime(true);
        $pending = fakturowniaRemotePending($fixture);
        $secondPending = fakturowniaRemotePending($fixture);
        $after = hrtime(true);
        $request = $pending->claimRequest();
        $receipt = fakturowniaRemoteSignedReceipt($request, $fixture['authority_secret_key']);
        $grant = $pending->completeLiteralNow(201, $receipt);
        $debug = print_r($grant, true);

        expect($request->harness['launch_manifest_sha256'])
            ->toBe($fixture['launch_manifest_sha256'])
            ->and($request->claimNonce)->not->toBe($secondPending->claimRequest()->claimNonce)
            ->and($request->runStartedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D')
            ->and($grant->claimRequest()->canonical())->toBe($request->canonical())
            ->and($grant->receipt()->toArray())->toBe($receipt)
            ->and($grant->authorizationBatch()->claimNonce)->toBe($request->claimNonce)
            ->and($grant->runStartedAt())->toBe($request->runStartedAt)
            ->and($grant->runStartedMonotonicNanoseconds())->toBeGreaterThanOrEqual($before)
            ->and($grant->runStartedMonotonicNanoseconds())->toBeLessThanOrEqual($after)
            ->and($debug)->not->toContain($fixture['policy']->endpoint)
            ->and($debug)->not->toContain($fixture['launch_manifest_sha256'])
            ->and(json_encode($grant, JSON_THROW_ON_ERROR))->not->toContain($request->claimNonce)
            ->and(fn () => $grant->assertPinnedRemoteOrigin())
            ->toThrow(BrokeredExecutionRequiredException::class)
            ->and(fn () => $grant->assertEffectBoundaryNow(
                [$fixture['authorization']],
                600,
                30,
                60,
                300,
            ))->toThrow(BrokeredExecutionRequiredException::class)
            ->and(fn () => serialize($grant))->toThrow(LogicException::class)
            ->and(fn () => clone $grant)->toThrow(LogicException::class)
            ->and(fn () => serialize($pending))->toThrow(LogicException::class)
            ->and(fn () => clone $pending)->toThrow(LogicException::class)
            ->and(fn () => $pending->completeLiteralNow(201, $receipt))->toThrow(LogicException::class);
    } finally {
        fakturowniaRemoteDestroyFixture($fixture);
    }
});

it('rejects tampered, mismatched, recovered and stale literal receipts', function (): void {
    $fixture = fakturowniaRemoteConsumptionFixture();

    try {
        $tamperedPending = fakturowniaRemotePending($fixture);
        $tamperedReceipt = fakturowniaRemoteSignedReceipt(
            $tamperedPending->claimRequest(),
            $fixture['authority_secret_key'],
        );
        $signature = base64_decode($tamperedReceipt['signature'], true);

        if (! is_string($signature) || $signature === '') {
            throw new LogicException('The remote consumption signature fixture is invalid.');
        }

        $signature[0] = chr(ord($signature[0]) ^ 1);
        $tamperedReceipt['signature'] = base64_encode($signature);

        expect(fn () => $tamperedPending->completeLiteralNow(201, $tamperedReceipt))
            ->toThrow(InvalidArgumentException::class);

        $statusPending = fakturowniaRemotePending($fixture);
        $freshReceipt = fakturowniaRemoteSignedReceipt(
            $statusPending->claimRequest(),
            $fixture['authority_secret_key'],
        );
        expect(fn () => $statusPending->completeLiteralNow(200, $freshReceipt))
            ->toThrow(RuntimeException::class);

        $recoveredPending = fakturowniaRemotePending($fixture);
        $recoveredReceipt = fakturowniaRemoteSignedReceipt(
            $recoveredPending->claimRequest(),
            $fixture['authority_secret_key'],
            ConsumptionDisposition::RecoveredConsumedProof,
        );
        expect(fn () => $recoveredPending->completeLiteralNow(200, $recoveredReceipt))
            ->toThrow(RuntimeException::class, 'never authorize');

        $sourcePending = fakturowniaRemotePending($fixture);
        $targetPending = fakturowniaRemotePending($fixture);
        $wrongRequestReceipt = fakturowniaRemoteSignedReceipt(
            $sourcePending->claimRequest(),
            $fixture['authority_secret_key'],
        );
        expect(fn () => $targetPending->completeLiteralNow(201, $wrongRequestReceipt))
            ->toThrow(InvalidArgumentException::class);

        $ttlPending = fakturowniaRemotePending($fixture);
        $longReceipt = fakturowniaRemoteSignedReceipt(
            $ttlPending->claimRequest(),
            $fixture['authority_secret_key'],
            ttlSeconds: 61,
        );
        expect(fn () => $ttlPending->completeLiteralNow(201, $longReceipt))
            ->toThrow(InvalidArgumentException::class);

        $callableCalls = 0;
        $callable = static function () use (&$callableCalls): array {
            $callableCalls++;

            return [];
        };
        $pending = fakturowniaRemotePending($fixture);
        $completeLiteralNow = new ReflectionMethod($pending, 'completeLiteralNow');

        expect(fn () => $completeLiteralNow->invoke($pending, 201, $callable))
            ->toThrow(TypeError::class)
            ->and($callableCalls)->toBe(0);
    } finally {
        fakturowniaRemoteDestroyFixture($fixture);
    }
});

it('fails the production coordinator before parsing, DNS, CAS or mutable Saloon state', function (): void {
    $fixture = fakturowniaRemoteConsumptionFixture();
    $manifest = (new ReflectionClass(VerifiedLaunchManifest::class))->newInstanceWithoutConstructor();
    $mockCalls = 0;
    $middlewareCalls = 0;
    $resolverCalls = 0;
    $globalMock = MockClient::global([
        static function () use (&$mockCalls): MockResponse {
            $mockCalls++;

            return MockResponse::make([], 201);
        },
    ]);
    Config::globalMiddleware()
        ->onRequest(static function () use (&$middlewareCalls): void {
            $middlewareCalls++;
        })
        ->onResponse(static function () use (&$middlewareCalls): void {
            $middlewareCalls++;
        })
        ->onFatalException(static function () use (&$middlewareCalls): void {
            $middlewareCalls++;
        });
    Config::setSenderResolver(static function () use (&$resolverCalls): GuzzleSender {
        $resolverCalls++;

        return new GuzzleSender;
    });

    try {
        expect(fn () => RemoteConsumptionCoordinator::claimNow(
            $fixture['root'],
            $manifest,
            [$fixture['authorization']],
            600,
            30,
            60,
            300,
        ))->toThrow(BrokeredExecutionRequiredException::class)
            ->and($mockCalls)->toBe(0)
            ->and($middlewareCalls)->toBe(0)
            ->and($resolverCalls)->toBe(0)
            ->and($globalMock->getRecordedResponses())->toBe([])
            ->and(class_exists('Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\RemoteConsumptionAuthorityClient'))
            ->toBeFalse()
            ->and(class_exists('Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\RemoteConsumptionAuthorityConnector'))
            ->toBeFalse()
            ->and(class_exists('Cieplik206\\Fakturownia\\ContractTesting\\LiveEvidence\\PinnedRemoteConsumptionSender'))
            ->toBeFalse();
    } finally {
        Config::setSenderResolver(null);
        Config::clearGlobalMiddleware();
        MockClient::destroyGlobal();
        fakturowniaRemoteDestroyFixture($fixture);
    }
});

it('keeps copied and forged PHP-local grants fail closed at every live boundary', function (): void {
    $fixture = fakturowniaRemoteConsumptionFixture();

    try {
        $pending = fakturowniaRemotePending($fixture);
        $grant = $pending->completeLiteralNow(201, fakturowniaRemoteSignedReceipt(
            $pending->claimRequest(),
            $fixture['authority_secret_key'],
        ));
        $forged = (new ReflectionClass(VerifiedRemoteConsumptionGrant::class))->newInstanceWithoutConstructor();
        $contextsProperty = (new ReflectionClass(VerifiedRemoteConsumptionGrant::class))->getProperty('contexts');
        $contexts = $contextsProperty->getValue();

        if (! $contexts instanceof WeakMap) {
            throw new LogicException('The adversarial grant registry fixture is unavailable.');
        }

        $contexts[$forged] = $contexts[$grant];

        foreach ([$grant, $forged] as $candidate) {
            expect(fn () => $candidate->assertPinnedRemoteOrigin())
                ->toThrow(BrokeredExecutionRequiredException::class)
                ->and(fn () => $candidate->assertEffectBoundaryNow(
                    [$fixture['authorization']],
                    600,
                    30,
                    60,
                    300,
                ))->toThrow(BrokeredExecutionRequiredException::class);
        }
    } finally {
        fakturowniaRemoteDestroyFixture($fixture);
    }
});

it('freezes a privacy-preserving live-effect descriptor without an execution API', function (): void {
    $fixture = fakturowniaRemoteConsumptionFixture();

    try {
        $pending = fakturowniaRemotePending($fixture);
        $receipt = fakturowniaRemoteSignedReceipt(
            $pending->claimRequest(),
            $fixture['authority_secret_key'],
        );
        $document = fakturowniaLiveEffectDescriptorDocument($pending->claimRequest(), $receipt);
        $descriptor = LiveEffectDescriptor::fromArray($document);
        $debug = print_r($descriptor, true);

        expect($descriptor->toArray())->toBe($document)
            ->and($descriptor->sha256())->toBe(hash('sha256', CanonicalCodec::encode($document)))
            ->and($debug)->not->toContain($document['target_origin_hmac_sha256'])
            ->and($debug)->not->toContain($document['endpoint_template'])
            ->and(fn () => serialize($descriptor))->toThrow(LogicException::class)
            ->and(fn () => clone $descriptor)->toThrow(LogicException::class);

        $mutations = [
            ['effect_sequence' => 0],
            ['effect_sequence' => 12],
            ['evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract],
            ['profile' => 'identity_probe_pair'],
            ['profile' => 'auto_block'],
            ['target_key' => 'explicit_block'],
            ['http_method' => 'GET'],
            ['endpoint_template' => '/invoices/{invoice_id}.json?send_to_ksef=yes'],
            ['capability' => 'invoice.ksef.ensure_accepted'],
            ['semantic_effect' => 'ksef_explicit_submit'],
            ['request_body_policy' => 'must_be_empty'],
            ['request_body_size_bytes' => 0],
            ['target_origin_hmac_sha256' => str_repeat('A', 64)],
            ['request_body_size_bytes' => 1_048_577],
            ['claim_nonce' => base64_encode(random_bytes(31))],
            ['run_started_at' => '2026-08-26T10:00:00Z'],
        ];

        foreach ($mutations as $mutation) {
            expect(fn () => LiveEffectDescriptor::fromArray([...$document, ...$mutation]))
                ->toThrow(InvalidArgumentException::class);
        }

        foreach (['explicit_block', 'explicit_persist', 'auto_block', 'auto_persist'] as $profile) {
            $setup = LiveEffectDescriptor::fromArray([
                ...$document,
                'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
                'effect_sequence' => 8,
                'profile' => $profile,
                'target_key' => $profile,
                'capability' => 'contract_probe.invoice.fixture.issue',
                'semantic_effect' => 'probe_fixture_invoice_create',
            ]);

            expect($setup->profile)->toBe($profile);
        }

        foreach (['explicit_block', 'explicit_persist'] as $profile) {
            $ksefDocument = [
                ...$document,
                'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
                'effect_sequence' => 8,
                'profile' => $profile,
                'target_key' => $profile,
                'capability' => 'invoice.ksef.ensure_accepted',
                'semantic_effect' => 'ksef_explicit_submit',
                'http_method' => 'GET',
                'endpoint_template' => '/invoices/{invoice_id}.json?send_to_ksef=yes',
                'request_body_size_bytes' => 0,
                'request_body_policy' => 'must_be_empty',
            ];
            $ksef = LiveEffectDescriptor::fromArray($ksefDocument);

            expect($ksef->profile)->toBe($profile)
                ->and($ksef->capability)->toBe('invoice.ksef.ensure_accepted');

            foreach ([
                ['evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract],
                ['effect_sequence' => 9],
                ['profile' => 'auto_block'],
                ['profile' => 'auto_persist'],
                ['capability' => 'invoice.vat.issue'],
                ['semantic_effect' => 'ksef_submit_or_observe'],
                ['http_method' => 'POST'],
                ['endpoint_template' => '/invoices.json'],
                ['request_body_size_bytes' => 1],
                ['request_body_policy' => 'required_non_empty'],
            ] as $mutation) {
                expect(fn () => LiveEffectDescriptor::fromArray([...$ksefDocument, ...$mutation]))
                    ->toThrow(InvalidArgumentException::class);
            }
        }

        foreach ([
            ['effect_sequence' => 9],
            ['request_body_size_bytes' => 0],
            ['profile' => 'invoice_identity'],
            ['capability' => 'invoice.vat.issue'],
        ] as $mutation) {
            $setupDocument = [
                ...$document,
                'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
                'effect_sequence' => 8,
                'profile' => 'explicit_block',
                'target_key' => 'explicit_block',
                'capability' => 'contract_probe.invoice.fixture.issue',
                'semantic_effect' => 'probe_fixture_invoice_create',
            ];

            expect(fn () => LiveEffectDescriptor::fromArray([...$setupDocument, ...$mutation]))
                ->toThrow(InvalidArgumentException::class);
        }

        $unknown = [...$document, 'remote_identity' => '123'];
        expect(fn () => LiveEffectDescriptor::fromArray($unknown))
            ->toThrow(InvalidArgumentException::class);

        $sentinel = 'https://tenant-secret.fakturownia.pl/raw-body/customer@example.test';
        $malformed = [...$document, 'raw_tenant_and_body' => $sentinel];

        try {
            LiveEffectDescriptor::fromArray($malformed);
            throw new LogicException('The malformed descriptor was unexpectedly accepted.');
        } catch (InvalidArgumentException $exception) {
            $exceptionDiagnostic = implode("\n", [
                $exception::class,
                $exception->getMessage(),
                $exception->getTraceAsString(),
            ]);
            $exceptionLeaks = str_contains($exceptionDiagnostic, $sentinel);
            $traceLeaks = str_contains(
                json_encode($exception->getTrace(), JSON_THROW_ON_ERROR),
                $sentinel,
            );

            expect($exceptionLeaks)->toBeFalse()
                ->and($traceLeaks)->toBeFalse()
                ->and(strlen($exceptionDiagnostic))->toBeLessThan(32_768);
        }

        $publicMethods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(LiveEffectDescriptor::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        expect($publicMethods)
            ->not->toContain('execute')
            ->not->toContain('authorize')
            ->not->toContain('send')
            ->not->toContain('openEffectBoundary');
    } finally {
        fakturowniaRemoteDestroyFixture($fixture);
    }
});

it('exposes no caller-controlled clock, nonce, transport or arbitrary launch-manifest fd', function (): void {
    $claim = new ReflectionMethod(RemoteConsumptionCoordinator::class, 'claimNow');
    $claimReturnType = $claim->getReturnType();

    if (! $claimReturnType instanceof ReflectionNamedType) {
        throw new LogicException('The production coordinator return type is not frozen.');
    }

    $claimTypes = array_map(
        static fn (ReflectionParameter $parameter): ?string => $parameter->getType() instanceof ReflectionNamedType
            ? $parameter->getType()->getName()
            : null,
        $claim->getParameters(),
    );
    $manifestFactory = new ReflectionMethod(VerifiedLaunchManifest::class, 'consumeFromSupervisorFd6');
    $manifestFactoryReturnType = $manifestFactory->getReturnType();

    if (! $manifestFactoryReturnType instanceof ReflectionNamedType) {
        throw new LogicException('The launch-manifest handoff return type is not frozen.');
    }

    $remoteCommand = new ReflectionClass(
        RemoteConsumptionClaimRequest::class,
    );
    $remoteCommandParents = class_parents(RemoteConsumptionClaimRequest::class);

    expect($claimReturnType->getName())->toBe('never')
        ->and($claimTypes)->toContain(VerifiedLaunchManifest::class)
        ->and($claimTypes)->not->toContain(DateTimeInterface::class)
        ->and($claimTypes)->not->toContain(MockClient::class)
        ->and($manifestFactory->getNumberOfParameters())->toBe(0)
        ->and($manifestFactoryReturnType->getName())->toBe('never')
        ->and((new ReflectionClass(VerifiedLaunchManifest::class))->getConstructor()?->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(RemoteConsumptionCoordinator::class))->hasMethod('forTesting'))->toBeFalse()
        ->and((new ReflectionClass(PendingLiteralRemoteConsumptionClaim::class))->hasMethod('forTesting'))->toBeFalse()
        ->and(array_values($remoteCommandParents))->not->toContain(Request::class);

    $forgedManifest = (new ReflectionClass(VerifiedLaunchManifest::class))->newInstanceWithoutConstructor();
    expect(fn () => VerifiedLaunchManifest::consumeFromSupervisorFd6())
        ->toThrow(BrokeredExecutionRequiredException::class)
        ->and(fn () => $forgedManifest->sha256())->toThrow(BrokeredExecutionRequiredException::class)
        ->and(print_r($forgedManifest, true))->not->toContain(str_repeat('b', 64))
        ->and(fn () => serialize($forgedManifest))->toThrow(LogicException::class)
        ->and(fn () => clone $forgedManifest)->toThrow(LogicException::class);
});

it('rejects noncanonical snapshot paths and intermediate symlinks', function (): void {
    $root = sys_get_temp_dir().'/fakturownia-pinned-reader-'.bin2hex(random_bytes(12));
    $outside = sys_get_temp_dir().'/fakturownia-pinned-reader-outside-'.bin2hex(random_bytes(12));
    mkdir($root.'/tests', 0700, true);
    mkdir($outside, 0700, true);
    file_put_contents($outside.'/snapshot.json', '{}');
    symlink($outside, $root.'/tests/Fixtures');

    try {
        foreach (['', '/absolute.json', '../escape.json', './dot.json', 'tests\\snapshot.json'] as $path) {
            expect(fn () => PinnedRepositorySnapshotReader::read($root, $path))
                ->toThrow(RuntimeException::class);
        }

        expect(fn () => PinnedRepositorySnapshotReader::read($root, 'tests/Fixtures/snapshot.json'))
            ->toThrow(RuntimeException::class, 'unsafe path component');
    } finally {
        unlink($root.'/tests/Fixtures');
        rmdir($root.'/tests');
        rmdir($root);
        unlink($outside.'/snapshot.json');
        rmdir($outside);
    }
});

it('requires disjoint pinned operator and consumption-authority key material', function (): void {
    $fixture = fakturowniaRemoteConsumptionFixture();

    try {
        $trustStore = PinnedLiveProbeTrustStore::load($fixture['root']);
        $trustStore->assertAuthorityMatches($fixture['policy']);
        $path = $fixture['root'].'/'.PinnedLiveProbeTrustStore::RepositoryPath;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new LogicException('The trust-store adversarial fixture cannot be read.');
        }

        $document = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);

        if (! is_array($document)
            || ! is_array($document['signers'] ?? null)
            || ! is_array($document['signers'][0] ?? null)) {
            throw new LogicException('The trust-store adversarial fixture is malformed.');
        }

        $document['signers'][0]['public_key'] = base64_encode($fixture['authority_public_key']);
        fakturowniaRemoteWriteJson($path, $document);

        expect(fn () => PinnedLiveProbeTrustStore::load($fixture['root']))
            ->toThrow(RuntimeException::class, 'reuses public-key material');
    } finally {
        fakturowniaRemoteDestroyFixture($fixture);
    }
});

it('ships no production signing helper or PHP-local remote dispatch path', function (): void {
    $files = glob(__DIR__.'/../../src/ContractTesting/LiveEvidence/*.php');

    if (! is_array($files) || $files === []) {
        throw new LogicException('The live-evidence production source inventory is unavailable.');
    }

    foreach ($files as $file) {
        $source = file_get_contents($file);

        expect($source)
            ->not->toBeFalse()
            ->not->toContain('sodium_crypto_sign_detached(')
            ->not->toContain('new RemoteConsumptionAuthorityConnector')
            ->not->toContain('->send(');
    }
});
