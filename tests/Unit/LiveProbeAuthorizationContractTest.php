<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionClaimRequest;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\LiveProbeAuthorizationBatchAggregator;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\LiveProbeAuthorizationVerifier;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\RemoteConsumptionClaimRequest;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\SignedLiveProbeAuthorization;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\TrustedLiveProbeOperatorKeys;
use Cieplik206\Fakturownia\Tests\Contract\Support\LiveEvidenceAttestationGuard;

/** @return array<string, mixed> */
function fakturowniaGoldenAuthorizationEnvelope(): array
{
    return [
        'contract' => SignedLiveProbeAuthorization::Contract,
        'version' => SignedLiveProbeAuthorization::Version,
        'algorithm' => SignedLiveProbeAuthorization::Algorithm,
        'signer_id' => 'golden-operator',
        'issued_at' => '2026-08-26T10:00:00.000000Z',
        'expires_at' => '2026-08-26T12:00:00.000000Z',
        'evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
        'challenge' => 'ERERERERERERERERERERERERERERERERERERERERERE=',
        'harness' => [
            'repository_commit' => str_repeat('1', 40),
            'code_sha256' => str_repeat('2', 64),
            'launch_manifest_sha256' => str_repeat('c', 64),
        ],
        'target' => [
            'environment' => 'demo_pl',
            'profile' => 'invoice_identity',
            'tenant_hmac_sha256' => str_repeat('3', 64),
            'account_hmac_sha256' => str_repeat('4', 64),
        ],
        'commitments' => [
            'scheme' => SignedLiveProbeAuthorization::CommitmentScheme,
            'configuration_hmac_sha256' => str_repeat('5', 64),
            'policy_hmac_sha256' => str_repeat('6', 64),
            'safety_hmac_sha256' => str_repeat('7', 64),
            'templates_hmac_sha256' => str_repeat('8', 64),
        ],
        'consumption' => [
            'authority_id' => 'authority-1',
            'authority_policy_sha256' => str_repeat('9', 64),
            'store_id' => 'store-1',
            'store_identity_sha256' => str_repeat('a', 64),
            'run_id' => str_repeat('b', 32),
            'replay_policy' => ConsumptionClaimRequest::ReplayPolicy,
        ],
        'limits' => [
            'maximum_effects' => 1,
            'request_timeout_ms' => 30_000,
        ],
    ];
}

/**
 * @return array{envelope: array<string, mixed>, signature: string}
 */
function fakturowniaSignedRuntimeAuthorization(
    string $profile,
    string $configurationHmacSha256,
    string $challenge,
    string $secretKey,
    DateTimeImmutable $issuedAt,
    DateTimeImmutable $expiresAt,
    int $paddingBytes = 0,
): array {
    if ($secretKey === '') {
        throw new InvalidArgumentException('The runtime authorization signing key cannot be empty.');
    }

    $envelope = fakturowniaGoldenAuthorizationEnvelope();
    $envelope['signer_id'] = 'runtime-operator';
    $envelope['issued_at'] = $issuedAt->format('Y-m-d\TH:i:s.u\Z');
    $envelope['expires_at'] = $expiresAt->format('Y-m-d\TH:i:s.u\Z');
    $envelope['challenge'] = $challenge;
    $envelope['target']['profile'] = $profile;
    $envelope['commitments']['configuration_hmac_sha256'] = $configurationHmacSha256;

    if ($paddingBytes > 0) {
        $envelope['limits']['padding'] = str_repeat('x', $paddingBytes);
    }

    return [
        'envelope' => $envelope,
        'signature' => base64_encode(sodium_crypto_sign_detached(CanonicalCodec::encode($envelope), $secretKey)),
    ];
}

/** @param list<SignedLiveProbeAuthorization> $authorizations */
function fakturowniaClaimRequestForAuthorizations(
    array $authorizations,
    DateTimeImmutable $runStartedAt,
    string $claimNonce,
): ConsumptionClaimRequest {
    $rows = array_map(static fn (SignedLiveProbeAuthorization $authorization): array => [
        'profile' => $authorization->target['profile'],
        'authorization_sha256' => $authorization->sha256(),
        'challenge_sha256' => $authorization->challengeSha256(),
        'configuration_hmac_sha256' => $authorization->commitments['configuration_hmac_sha256'],
    ], $authorizations);
    usort($rows, static fn (array $left, array $right): int => $left['profile'] <=> $right['profile']);
    $setHash = static fn (array $value): string => hash('sha256', CanonicalCodec::encode([
        'contract' => 'cieplik206.fakturownia.authorization-consumption-set',
        'version' => SignedLiveProbeAuthorization::Version,
        'value' => $value,
    ]));
    $first = $authorizations[0];

    return new ConsumptionClaimRequest(
        $first->consumption['authority_id'],
        $first->consumption['authority_policy_sha256'],
        $first->consumption['store_id'],
        $first->consumption['store_identity_sha256'],
        $first->consumption['run_id'],
        $runStartedAt->format('Y-m-d\TH:i:s.u\Z'),
        $claimNonce,
        $first->harness,
        $setHash(array_map(static fn (array $row): array => [
            'profile' => $row['profile'],
            'sha256' => $row['authorization_sha256'],
        ], $rows)),
        $setHash(array_map(static fn (array $row): array => [
            'profile' => $row['profile'],
            'sha256' => $row['challenge_sha256'],
        ], $rows)),
        $setHash(array_map(static fn (array $row): array => [
            'profile' => $row['profile'],
            'sha256' => $row['configuration_hmac_sha256'],
        ], $rows)),
    );
}

it('keeps the signed live-probe authorization version 1 golden vector canonical', function (): void {
    $document = [
        'envelope' => fakturowniaGoldenAuthorizationEnvelope(),
        'signature' => 'NuxPgTgGHFYY0uLWx2lopi/5i+R7PbtaYV2bo6t1DMA0CQoKgFdcYmIOkH7M8o6Uc9S5AtpBd3A6tzPzxNL9Ag==',
    ];
    $authorization = SignedLiveProbeAuthorization::fromArray($document);
    $publicKey = base64_decode('SX6Be2Nn7tgTolW5XtDymSn763aAnDA4uWQ1XBQMxmc=', true);
    $signature = base64_decode($document['signature'], true);

    if (! is_string($publicKey) || $publicKey === '' || ! is_string($signature) || $signature === '') {
        throw new RuntimeException('The golden authorization signing material is invalid.');
    }

    expect(base64_encode($publicKey))
        ->toBe('SX6Be2Nn7tgTolW5XtDymSn763aAnDA4uWQ1XBQMxmc=')
        ->and(hash('sha256', $authorization->canonicalEnvelope()))
        ->toBe('95a5af1376eb6f7c874b22f841c0f5801980fda6df215532440f8224d8d8abf8')
        ->and($authorization->sha256())
        ->toBe('d736ee9d83f066fb34a71447bcbd41fd0fc74400242a4a3f038a1019ed318380')
        ->and($authorization->toArray())->toBe($document)
        ->and(sodium_crypto_sign_verify_detached($signature, $authorization->canonicalEnvelope(), $publicKey))
        ->toBeTrue();

    $unknownField = $document;
    $unknownField['envelope']['unexpected'] = true;
    $tampered = $document;
    $tampered['envelope']['limits']['maximum_effects'] = 2;

    expect(fn () => SignedLiveProbeAuthorization::fromArray($unknownField))
        ->toThrow(InvalidArgumentException::class)
        ->and(hash('sha256', CanonicalCodec::encode($tampered)))
        ->not->toBe($authorization->sha256())
        ->and(sodium_crypto_sign_verify_detached(
            $signature,
            CanonicalCodec::encode($tampered['envelope']),
            $publicKey,
        ))->toBeFalse();
});

it('verifies and aggregates every signed authorization field before accepting a claim request', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $issuedAt = $now->modify('-1 minute');
    $expiresAt = $now->modify('+5 minutes');

    try {
        $documents = [
            fakturowniaSignedRuntimeAuthorization(
                'explicit_block',
                str_repeat('c', 64),
                base64_encode(str_repeat("\x21", 32)),
                $secretKey,
                $issuedAt,
                $expiresAt,
            ),
            fakturowniaSignedRuntimeAuthorization(
                'explicit_persist',
                str_repeat('d', 64),
                base64_encode(str_repeat("\x22", 32)),
                $secretKey,
                $issuedAt,
                $expiresAt,
            ),
        ];
        $authorizations = array_map(SignedLiveProbeAuthorization::fromArray(...), $documents);
        $claimNonce = base64_encode(random_bytes(32));
        $claimRequest = fakturowniaClaimRequestForAuthorizations($authorizations, $now, $claimNonce);
        $guardClaimRequest = ConsumptionClaimRequest::fromArray(
            LiveEvidenceAttestationGuard::buildConsumptionClaimRequest($documents, $now, $claimNonce),
        );
        $remoteCommand = new RemoteConsumptionClaimRequest($documents, $claimRequest);
        $keyring = TrustedLiveProbeOperatorKeys::fromBase64Map([
            'runtime-operator' => base64_encode($publicKey),
        ]);
        $batch = LiveProbeAuthorizationBatchAggregator::verifyForClaimRequestNow(
            $authorizations,
            $claimRequest,
            $keyring,
            360,
        );

        expect($batch->authorityId)->toBe('authority-1')
            ->and($guardClaimRequest->toArray())->toBe($claimRequest->toArray())
            ->and($remoteCommand->payload()['signed_authorizations'])->toBe($documents)
            ->and($batch->runStartedAt)->toBe($claimRequest->runStartedAt)
            ->and($batch->claimNonce)->toBe($claimNonce)
            ->and($batch->replayPolicy)->toBe(ConsumptionClaimRequest::ReplayPolicy)
            ->and($batch->rows)->toHaveCount(2)
            ->and(array_column($batch->rows, 'profile'))->toBe(['explicit_block', 'explicit_persist'])
            ->and($keyring->fingerprints())->toBe([
                'runtime-operator' => hash('sha256', $publicKey),
            ]);

        $wrongClaim = ConsumptionClaimRequest::fromArray([
            ...$claimRequest->toArray(),
            'authorization_set_sha256' => str_repeat('f', 64),
        ]);
        $tamperedDocument = $documents[0];
        $tamperedSignature = base64_decode($tamperedDocument['signature'], true);

        if (! is_string($tamperedSignature) || $tamperedSignature === '') {
            throw new RuntimeException('The authorization signature fixture is invalid.');
        }

        $tamperedSignature[0] = chr(ord($tamperedSignature[0]) ^ 1);
        $tamperedDocument['signature'] = base64_encode($tamperedSignature);
        $duplicateProfile = [
            $authorizations[0],
            SignedLiveProbeAuthorization::fromArray(fakturowniaSignedRuntimeAuthorization(
                'explicit_block',
                str_repeat('e', 64),
                base64_encode(str_repeat("\x23", 32)),
                $secretKey,
                $issuedAt,
                $expiresAt,
            )),
        ];
        $duplicateChallenge = [
            $authorizations[0],
            SignedLiveProbeAuthorization::fromArray(fakturowniaSignedRuntimeAuthorization(
                'auto_block',
                str_repeat('e', 64),
                $documents[0]['envelope']['challenge'],
                $secretKey,
                $issuedAt,
                $expiresAt,
            )),
        ];
        $claimMutations = [
            ['authority_id' => 'authority-2'],
            ['authority_policy_sha256' => str_repeat('0', 64)],
            ['store_id' => 'store-2'],
            ['store_identity_sha256' => str_repeat('0', 64)],
            ['run_id' => str_repeat('0', 32)],
            ['run_started_at' => $issuedAt->modify('-1 microsecond')->format('Y-m-d\TH:i:s.u\Z')],
            ['harness' => [
                ...$claimRequest->harness,
                'code_sha256' => str_repeat('0', 64),
            ]],
            ['harness' => [
                ...$claimRequest->harness,
                'launch_manifest_sha256' => str_repeat('0', 64),
            ]],
            ['authorization_set_sha256' => str_repeat('0', 64)],
            ['challenge_set_sha256' => str_repeat('0', 64)],
            ['configuration_set_sha256' => str_repeat('0', 64)],
        ];

        expect(fn () => LiveProbeAuthorizationVerifier::verifyNow(
            SignedLiveProbeAuthorization::fromArray($tamperedDocument),
            $keyring,
            360,
        ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => LiveProbeAuthorizationBatchAggregator::verifyForClaimRequestNow(
                [$authorizations[0], $authorizations[0]],
                $claimRequest,
                $keyring,
                360,
            ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => LiveProbeAuthorizationBatchAggregator::verifyForClaimRequestNow(
                $duplicateProfile,
                $claimRequest,
                $keyring,
                360,
            ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => LiveProbeAuthorizationBatchAggregator::verifyForClaimRequestNow(
                $duplicateChallenge,
                $claimRequest,
                $keyring,
                360,
            ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => TrustedLiveProbeOperatorKeys::fromBase64Map([
                'runtime-operator' => base64_encode($publicKey),
                'runtime-operator-alias' => base64_encode($publicKey),
            ]))->toThrow(InvalidArgumentException::class);

        foreach ($claimMutations as $mutation) {
            $mutatedClaim = ConsumptionClaimRequest::fromArray([
                ...$claimRequest->toArray(),
                ...$mutation,
            ]);

            expect(fn () => LiveProbeAuthorizationBatchAggregator::verifyForClaimRequestNow(
                $authorizations,
                $mutatedClaim,
                $keyring,
                360,
            ))->toThrow(InvalidArgumentException::class);
        }

        expect(fn () => LiveProbeAuthorizationBatchAggregator::verifyForClaimRequestNow(
            $authorizations,
            $wrongClaim,
            $keyring,
            360,
        ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => ConsumptionClaimRequest::fromArray([
                ...$claimRequest->toArray(),
                'claim_nonce' => 'not-canonical',
            ]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => ConsumptionClaimRequest::fromArray([
                ...$claimRequest->toArray(),
                'replay_policy' => 'retry_mutating_http',
            ]))->toThrow(InvalidArgumentException::class);
    } finally {
        sodium_memzero($secretKey);
        sodium_memzero($keyPair);
    }
});

it('rejects stale future oversized deep and out-of-range authorization batches', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $keyring = TrustedLiveProbeOperatorKeys::fromBase64Map([
        'runtime-operator' => base64_encode($publicKey),
    ]);

    try {
        $validDocument = fakturowniaSignedRuntimeAuthorization(
            'valid_profile',
            str_repeat('c', 64),
            base64_encode(str_repeat("\x31", 32)),
            $secretKey,
            $now->modify('-1 minute'),
            $now->modify('+5 minutes'),
        );
        $validAuthorization = SignedLiveProbeAuthorization::fromArray($validDocument);
        $claimRequest = fakturowniaClaimRequestForAuthorizations(
            [$validAuthorization],
            $now,
            base64_encode(random_bytes(32)),
        );
        $expired = SignedLiveProbeAuthorization::fromArray(fakturowniaSignedRuntimeAuthorization(
            'expired_profile',
            str_repeat('d', 64),
            base64_encode(str_repeat("\x32", 32)),
            $secretKey,
            $now->modify('-10 minutes'),
            $now->modify('-5 minutes'),
        ));
        $future = SignedLiveProbeAuthorization::fromArray(fakturowniaSignedRuntimeAuthorization(
            'future_profile',
            str_repeat('e', 64),
            base64_encode(str_repeat("\x33", 32)),
            $secretKey,
            $now->modify('+5 minutes'),
            $now->modify('+10 minutes'),
        ));
        $tooLong = SignedLiveProbeAuthorization::fromArray(fakturowniaSignedRuntimeAuthorization(
            'long_profile',
            str_repeat('f', 64),
            base64_encode(str_repeat("\x34", 32)),
            $secretKey,
            $now->modify('-1 minute'),
            $now->modify('+10 minutes'),
        ));
        $oversizedBatch = [];

        for ($index = 0; $index < 16; $index++) {
            $hex = dechex(($index % 15) + 1);
            $oversizedBatch[] = SignedLiveProbeAuthorization::fromArray(fakturowniaSignedRuntimeAuthorization(
                sprintf('profile_%02d', $index),
                str_repeat($hex, 64),
                base64_encode(str_repeat(chr($index + 1), 32)),
                $secretKey,
                $now->modify('-1 minute'),
                $now->modify('+5 minutes'),
                70_000,
            ));
        }

        $oversizedDocument = $validDocument;
        $oversizedDocument['envelope']['limits']['padding'] = str_repeat('x', 1_048_577);
        $deepDocument = $validDocument;
        $nested = true;

        for ($depth = 0; $depth < 70; $depth++) {
            $nested = ['nested' => $nested];
        }

        $deepDocument['envelope']['limits'] = ['nested' => $nested];

        expect(fn () => LiveProbeAuthorizationVerifier::verifyNow($expired, $keyring, 360))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => LiveProbeAuthorizationVerifier::verifyNow($future, $keyring, 360))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => LiveProbeAuthorizationVerifier::verifyNow($tooLong, $keyring, 360))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => LiveProbeAuthorizationBatchAggregator::verifyForClaimRequestNow(
                [],
                $claimRequest,
                $keyring,
                360,
            ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => LiveProbeAuthorizationBatchAggregator::verifyForClaimRequestNow(
                array_fill(0, 17, $validAuthorization),
                $claimRequest,
                $keyring,
                360,
            ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => LiveProbeAuthorizationBatchAggregator::verifyForClaimRequestNow(
                $oversizedBatch,
                $claimRequest,
                $keyring,
                360,
            ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => SignedLiveProbeAuthorization::fromArray($oversizedDocument))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => SignedLiveProbeAuthorization::fromArray($deepDocument))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        sodium_memzero($secretKey);
        sodium_memzero($keyPair);
    }
});
