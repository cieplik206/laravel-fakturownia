<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectDisposition;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectExecutionResult;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerWireFrame;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeSupervisorAttestation;

/** @return array{document: array<string, mixed>, public_key: non-empty-string} */
function fakturowniaNativeSupervisorAttestationFixture(): array
{
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);

    $envelope = [
        'contract' => NativeSupervisorAttestation::Contract,
        'version' => NativeSupervisorAttestation::Version,
        'algorithm' => NativeSupervisorAttestation::Algorithm,
        'signer_id' => 'native-broker-1',
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
        'document' => [
            'envelope' => $envelope,
            'signature' => base64_encode(sodium_crypto_sign_detached(CanonicalCodec::encode($envelope), $secretKey)),
        ],
        'public_key' => $publicKey,
    ];
}

/** @return array{document: array<string, mixed>, public_key: non-empty-string} */
function fakturowniaBrokeredEffectResultFixture(
    BrokeredEffectDisposition $disposition = BrokeredEffectDisposition::Applied,
): array {
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);

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
        'signer_id' => 'native-broker-1',
        'issued_at' => '2026-08-27T08:00:02.000000Z',
        'expires_at' => '2026-08-27T08:10:02.000000Z',
        'launch_manifest_sha256' => str_repeat('1', 64),
        'run_nonce' => base64_encode(str_repeat('n', 32)),
        'authorization_set_sha256' => str_repeat('2', 64),
        'broker_policy_sha256' => str_repeat('3', 64),
        'supervisor_attestation_sha256' => str_repeat('4', 64),
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
        'document' => [
            'envelope' => $envelope,
            'signature' => base64_encode(sodium_crypto_sign_detached(CanonicalCodec::encode($envelope), $secretKey)),
        ],
        'public_key' => $publicKey,
    ];
}

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
