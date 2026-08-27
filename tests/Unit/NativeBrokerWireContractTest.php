<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectDisposition;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectExecutionResult;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectExecutionResultVerifier;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerTrustPolicy;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerWireFrame;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeSupervisorAttestation;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeSupervisorAttestationVerifier;

/**
 * @return array{
 *     document: array<string, mixed>,
 *     public_key: non-empty-string,
 *     policy_secret_key: non-empty-string,
 *     supervisor_secret_key: non-empty-string,
 *     supervisor_public_key: non-empty-string,
 *     effect_result_secret_key: non-empty-string,
 *     effect_result_public_key: non-empty-string
 * }
 */
function fakturowniaNativeBrokerTrustPolicyFixture(): array
{
    $policyKeyPair = sodium_crypto_sign_keypair();
    $supervisorKeyPair = sodium_crypto_sign_keypair();
    $effectResultKeyPair = sodium_crypto_sign_keypair();
    $policySecretKey = sodium_crypto_sign_secretkey($policyKeyPair);
    $policyPublicKey = sodium_crypto_sign_publickey($policyKeyPair);
    $supervisorSecretKey = sodium_crypto_sign_secretkey($supervisorKeyPair);
    $supervisorPublicKey = sodium_crypto_sign_publickey($supervisorKeyPair);
    $effectResultSecretKey = sodium_crypto_sign_secretkey($effectResultKeyPair);
    $effectResultPublicKey = sodium_crypto_sign_publickey($effectResultKeyPair);

    $envelope = [
        'contract' => NativeBrokerTrustPolicy::Contract,
        'version' => NativeBrokerTrustPolicy::Version,
        'algorithm' => NativeBrokerTrustPolicy::Algorithm,
        'signer_id' => 'deployment-policy-1',
        'issued_at' => '2026-08-27T07:50:00.000000Z',
        'expires_at' => '2026-08-27T09:00:00.000000Z',
        'broker_policy_sha256' => str_repeat('3', 64),
        'supervisor_semantics_sha256' => str_repeat('4', 64),
        'argv_sha256' => str_repeat('5', 64),
        'environment_sha256' => str_repeat('6', 64),
        'probe_uid' => 991,
        'probe_gid' => 991,
        'supervisor_signer' => [
            'id' => 'native-supervisor-1',
            'algorithm' => NativeBrokerTrustPolicy::Algorithm,
            'public_key' => base64_encode($supervisorPublicKey),
        ],
        'effect_result_signer' => [
            'id' => 'native-effect-result-1',
            'algorithm' => NativeBrokerTrustPolicy::Algorithm,
            'public_key' => base64_encode($effectResultPublicKey),
        ],
    ];

    return [
        'document' => fakturowniaSignNativeBrokerDocument($envelope, $policySecretKey),
        'public_key' => base64_encode($policyPublicKey),
        'policy_secret_key' => $policySecretKey,
        'supervisor_secret_key' => $supervisorSecretKey,
        'supervisor_public_key' => $supervisorPublicKey,
        'effect_result_secret_key' => $effectResultSecretKey,
        'effect_result_public_key' => $effectResultPublicKey,
    ];
}

/**
 * @param  array<string, mixed>  $envelope
 * @param  non-empty-string  $secretKey
 * @return array{envelope: array<string, mixed>, signature: string}
 */
function fakturowniaSignNativeBrokerDocument(array $envelope, string $secretKey): array
{
    return [
        'envelope' => $envelope,
        'signature' => base64_encode(sodium_crypto_sign_detached(CanonicalCodec::encode($envelope), $secretKey)),
    ];
}

/**
 * @param  non-empty-string|null  $secretKey
 * @return array{document: array<string, mixed>, public_key: non-empty-string}
 */
function fakturowniaNativeSupervisorAttestationFixture(
    ?string $secretKey = null,
    string $signerId = 'native-supervisor-1',
): array {
    $keyPair = $secretKey === null ? sodium_crypto_sign_keypair() : null;
    $secretKey ??= sodium_crypto_sign_secretkey($keyPair);
    $publicKey = $keyPair === null
        ? sodium_crypto_sign_publickey_from_secretkey($secretKey)
        : sodium_crypto_sign_publickey($keyPair);

    $envelope = [
        'contract' => NativeSupervisorAttestation::Contract,
        'version' => NativeSupervisorAttestation::Version,
        'algorithm' => NativeSupervisorAttestation::Algorithm,
        'signer_id' => $signerId,
        'issued_at' => '2026-08-27T08:00:00.000000Z',
        'expires_at' => '2026-08-27T08:10:00.000000Z',
        'launch_manifest_sha256' => str_repeat('1', 64),
        'run_nonce' => base64_encode(str_repeat('n', 32)),
        'authorization_set_sha256' => str_repeat('2', 64),
        'broker_policy_sha256' => str_repeat('3', 64),
        'supervisor_semantics_sha256' => str_repeat('4', 64),
        'argv_sha256' => str_repeat('5', 64),
        'environment_sha256' => str_repeat('6', 64),
        'probe_uid' => 991,
        'probe_gid' => 991,
    ];

    return [
        'document' => fakturowniaSignNativeBrokerDocument($envelope, $secretKey),
        'public_key' => $publicKey,
    ];
}

/**
 * @param  non-empty-string|null  $secretKey
 * @return array{document: array<string, mixed>, public_key: non-empty-string}
 */
function fakturowniaBrokeredEffectResultFixture(
    BrokeredEffectDisposition $disposition = BrokeredEffectDisposition::Applied,
    ?string $secretKey = null,
    string $signerId = 'native-effect-result-1',
    ?string $supervisorAttestationSha256 = null,
): array {
    $keyPair = $secretKey === null ? sodium_crypto_sign_keypair() : null;
    $secretKey ??= sodium_crypto_sign_secretkey($keyPair);
    $publicKey = $keyPair === null
        ? sodium_crypto_sign_publickey_from_secretkey($secretKey)
        : sodium_crypto_sign_publickey($keyPair);

    $response = $disposition === BrokeredEffectDisposition::Applied
        ? '{"id":123,"number":"FV/1/2026"}'
        : '';
    $requestStartedAt = in_array($disposition, [
        BrokeredEffectDisposition::Applied,
        BrokeredEffectDisposition::PossiblyApplied,
    ], true) ? '2026-08-27T08:00:01.000000Z' : null;
    $envelope = [
        'contract' => BrokeredEffectExecutionResult::Contract,
        'version' => BrokeredEffectExecutionResult::Version,
        'algorithm' => BrokeredEffectExecutionResult::Algorithm,
        'signer_id' => $signerId,
        'issued_at' => '2026-08-27T08:00:02.000000Z',
        'expires_at' => '2026-08-27T08:09:59.000000Z',
        'launch_manifest_sha256' => str_repeat('1', 64),
        'run_nonce' => base64_encode(str_repeat('n', 32)),
        'authorization_set_sha256' => str_repeat('2', 64),
        'broker_policy_sha256' => str_repeat('3', 64),
        'supervisor_attestation_sha256' => $supervisorAttestationSha256 ?? str_repeat('4', 64),
        'effect_descriptor_sha256' => str_repeat('5', 64),
        'effect_id' => str_repeat('6', 32),
        'cas_record_sha256' => str_repeat('7', 64),
        'disposition' => $disposition->value,
        'request_started_at' => $requestStartedAt,
        'response_received_at' => $disposition === BrokeredEffectDisposition::Applied
            ? '2026-08-27T08:00:01.250000Z'
            : null,
        'http_status' => $disposition === BrokeredEffectDisposition::Applied ? 201 : 0,
        'content_type' => $disposition === BrokeredEffectDisposition::Applied ? 'application/json' : null,
        'provider_request_id_hmac_sha256' => $disposition === BrokeredEffectDisposition::Applied
            ? str_repeat('8', 64)
            : null,
        'response_body_base64' => base64_encode($response),
        'response_body_sha256' => hash('sha256', $response),
        'response_size_bytes' => strlen($response),
    ];

    return [
        'document' => fakturowniaSignNativeBrokerDocument($envelope, $secretKey),
        'public_key' => $publicKey,
    ];
}

it('verifies a role-separated native broker policy, supervisor attestation and effect result', function (): void {
    $policyFixture = fakturowniaNativeBrokerTrustPolicyFixture();
    $observedAt = new DateTimeImmutable('2026-08-27T08:00:03.000000Z');
    $policy = NativeBrokerTrustPolicy::verify(
        $policyFixture['document'],
        'deployment-policy-1',
        $policyFixture['public_key'],
        $observedAt,
    );
    $attestationFixture = fakturowniaNativeSupervisorAttestationFixture(
        $policyFixture['supervisor_secret_key'],
    );
    $attestation = NativeSupervisorAttestation::fromArray($attestationFixture['document']);

    expect(NativeSupervisorAttestationVerifier::verify(
        $attestation,
        $policy,
        $observedAt,
        str_repeat('1', 64),
        base64_encode(str_repeat('n', 32)),
        str_repeat('2', 64),
    ))->toBe($attestation)
        ->and(json_encode($policy, JSON_THROW_ON_ERROR))
        ->toBe('{"native_broker_trust_policy":"[VERIFIED]","public_keys":"[REDACTED]"}')
        ->and(fn () => serialize($policy))->toThrow(LogicException::class);

    $resultFixture = fakturowniaBrokeredEffectResultFixture(
        secretKey: $policyFixture['effect_result_secret_key'],
        supervisorAttestationSha256: $attestation->sha256(),
    );
    $result = BrokeredEffectExecutionResult::fromArray($resultFixture['document']);

    expect(BrokeredEffectExecutionResultVerifier::verify(
        $result,
        $attestation,
        $policy,
        $observedAt,
        str_repeat('1', 64),
        base64_encode(str_repeat('n', 32)),
        str_repeat('2', 64),
        str_repeat('5', 64),
        str_repeat('6', 32),
        str_repeat('7', 64),
    ))->toBe($result);
});

it('rejects policy tampering, role confusion, stale proofs and cross-run bindings', function (): void {
    $policyFixture = fakturowniaNativeBrokerTrustPolicyFixture();
    $observedAt = new DateTimeImmutable('2026-08-27T08:00:03.000000Z');
    $policy = NativeBrokerTrustPolicy::verify(
        $policyFixture['document'],
        'deployment-policy-1',
        $policyFixture['public_key'],
        $observedAt,
    );
    $tamperedPolicy = $policyFixture['document'];
    $tamperedPolicy['envelope']['argv_sha256'] = str_repeat('a', 64);

    expect(fn () => NativeBrokerTrustPolicy::verify(
        $tamperedPolicy,
        'deployment-policy-1',
        $policyFixture['public_key'],
        $observedAt,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => NativeBrokerTrustPolicy::verify(
            $policyFixture['document'],
            'deployment-policy-2',
            $policyFixture['public_key'],
            $observedAt,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => NativeBrokerTrustPolicy::verify(
            $policyFixture['document'],
            'deployment-policy-1',
            $policyFixture['public_key'],
            new DateTimeImmutable('2026-08-27T09:00:00.000000Z'),
        ))->toThrow(InvalidArgumentException::class);

    $attestationFixture = fakturowniaNativeSupervisorAttestationFixture(
        $policyFixture['supervisor_secret_key'],
    );
    $attestation = NativeSupervisorAttestation::fromArray($attestationFixture['document']);

    expect(fn () => NativeSupervisorAttestationVerifier::verify(
        $attestation,
        $policy,
        $observedAt,
        str_repeat('1', 64),
        base64_encode(str_repeat('x', 32)),
        str_repeat('2', 64),
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => NativeSupervisorAttestationVerifier::verify(
            $attestation,
            $policy,
            new DateTimeImmutable('2026-08-27T08:10:00.000000Z'),
            str_repeat('1', 64),
            base64_encode(str_repeat('n', 32)),
            str_repeat('2', 64),
        ))->toThrow(InvalidArgumentException::class);

    $roleConfusedFixture = fakturowniaBrokeredEffectResultFixture(
        secretKey: $policyFixture['supervisor_secret_key'],
        signerId: 'native-supervisor-1',
        supervisorAttestationSha256: $attestation->sha256(),
    );
    $roleConfusedResult = BrokeredEffectExecutionResult::fromArray($roleConfusedFixture['document']);

    expect(fn () => BrokeredEffectExecutionResultVerifier::verify(
        $roleConfusedResult,
        $attestation,
        $policy,
        $observedAt,
        str_repeat('1', 64),
        base64_encode(str_repeat('n', 32)),
        str_repeat('2', 64),
        str_repeat('5', 64),
        str_repeat('6', 32),
        str_repeat('7', 64),
    ))->toThrow(InvalidArgumentException::class);

    $resultFixture = fakturowniaBrokeredEffectResultFixture(
        secretKey: $policyFixture['effect_result_secret_key'],
        supervisorAttestationSha256: $attestation->sha256(),
    );
    $result = BrokeredEffectExecutionResult::fromArray($resultFixture['document']);

    expect(fn () => BrokeredEffectExecutionResultVerifier::verify(
        $result,
        $attestation,
        $policy,
        $observedAt,
        str_repeat('1', 64),
        base64_encode(str_repeat('n', 32)),
        str_repeat('2', 64),
        str_repeat('a', 64),
        str_repeat('6', 32),
        str_repeat('7', 64),
    ))->toThrow(InvalidArgumentException::class);
});

it('freezes and verifies the signed native supervisor attestation envelope', function (): void {
    $fixture = fakturowniaNativeSupervisorAttestationFixture();
    $attestation = NativeSupervisorAttestation::fromArray($fixture['document']);
    $signature = base64_decode($attestation->signature, true);

    if (! is_string($signature) || $signature === '') {
        throw new LogicException('The native supervisor signature fixture is invalid.');
    }

    expect(sodium_crypto_sign_verify_detached(
        $signature,
        $attestation->canonicalEnvelope(),
        $fixture['public_key'],
    ))->toBeTrue()
        ->and($attestation->sha256())->toBe(hash('sha256', $attestation->canonical()))
        ->and(json_encode($attestation, JSON_THROW_ON_ERROR))->toBe('{"native_supervisor_attestation":"[REDACTED]"}')
        ->and(fn () => serialize($attestation))->toThrow(LogicException::class);

    foreach ([
        ['probe_uid' => 0],
        ['run_nonce' => base64_encode(str_repeat('n', 31))],
        ['expires_at' => '2026-08-27T08:10:00.000001Z'],
        ['launch_manifest_sha256' => str_repeat('A', 64)],
    ] as $mutation) {
        expect(fn () => NativeSupervisorAttestation::fromArray([
            ...$fixture['document'],
            'envelope' => [...$fixture['document']['envelope'], ...$mutation],
        ]))->toThrow(InvalidArgumentException::class);
    }
});

it('freezes applied, possibly applied, denied and consumed broker result shapes', function (): void {
    foreach (BrokeredEffectDisposition::cases() as $disposition) {
        $fixture = fakturowniaBrokeredEffectResultFixture($disposition);
        $result = BrokeredEffectExecutionResult::fromArray($fixture['document']);
        $signature = base64_decode($result->signature, true);

        if (! is_string($signature) || $signature === '') {
            throw new LogicException('The brokered effect signature fixture is invalid.');
        }

        expect($result->disposition)->toBe($disposition)
            ->and($result->responseBody())->toBe(
                $disposition === BrokeredEffectDisposition::Applied
                    ? '{"id":123,"number":"FV/1/2026"}'
                    : '',
            )
            ->and(sodium_crypto_sign_verify_detached(
                $signature,
                $result->canonicalEnvelope(),
                $fixture['public_key'],
            ))->toBeTrue()
            ->and(json_encode($result, JSON_THROW_ON_ERROR))
            ->toBe('{"brokered_effect_execution_result":"[REDACTED]"}');
    }
});

it('rejects result shapes that overclaim provider execution evidence', function (): void {
    $fixture = fakturowniaBrokeredEffectResultFixture(BrokeredEffectDisposition::Denied);

    foreach ([
        ['request_started_at' => '2026-08-27T08:00:01.000000Z'],
        ['http_status' => 403],
        ['content_type' => 'application/json'],
        ['response_body_base64' => base64_encode('denied')],
        ['response_body_sha256' => str_repeat('9', 64)],
        ['response_size_bytes' => 1],
    ] as $mutation) {
        expect(fn () => BrokeredEffectExecutionResult::fromArray([
            ...$fixture['document'],
            'envelope' => [...$fixture['document']['envelope'], ...$mutation],
        ]))->toThrow(InvalidArgumentException::class);
    }
});

it('frames only one bounded canonical JSON object with an exact length prefix', function (): void {
    $fixture = fakturowniaNativeSupervisorAttestationFixture();
    $frame = NativeBrokerWireFrame::encode($fixture['document']);

    expect(NativeBrokerWireFrame::decode($frame))->toEqual($fixture['document'])
        ->and(fn () => NativeBrokerWireFrame::decode($frame."\n"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => NativeBrokerWireFrame::decode("00000002\n{}"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => NativeBrokerWireFrame::decode("0000000d\n{\"b\":1,\"a\":2}"))
        ->toThrow(InvalidArgumentException::class);
});
