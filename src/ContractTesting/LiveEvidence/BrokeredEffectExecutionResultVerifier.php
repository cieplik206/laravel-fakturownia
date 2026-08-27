<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use InvalidArgumentException;
use SensitiveParameter;

final class BrokeredEffectExecutionResultVerifier
{
    public static function verify(
        #[SensitiveParameter] BrokeredEffectExecutionResult $result,
        #[SensitiveParameter] NativeSupervisorAttestation $attestation,
        #[SensitiveParameter] NativeBrokerTrustPolicy $policy,
        DateTimeImmutable $observedAt,
        string $launchManifestSha256,
        #[SensitiveParameter] string $runNonce,
        string $authorizationSetSha256,
        string $effectDescriptorSha256,
        string $effectId,
        string $casRecordSha256,
    ): BrokeredEffectExecutionResult {
        NativeBrokerWireValidation::assertSha256($effectDescriptorSha256, 'expected effect descriptor');
        NativeBrokerWireValidation::assertSha256($casRecordSha256, 'expected CAS record');

        if (\preg_match('/^[a-f0-9]{32}$/D', $effectId) !== 1) {
            throw new InvalidArgumentException('The expected brokered effect identity is invalid.');
        }

        NativeSupervisorAttestationVerifier::verify(
            $attestation,
            $policy,
            $observedAt,
            $launchManifestSha256,
            $runNonce,
            $authorizationSetSha256,
        );
        $policy->assertEffectExecutionResultSignature($result);
        $issuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $result->issuedAt,
            'brokered effect result issue time',
        );
        $expiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $result->expiresAt,
            'brokered effect result expiry time',
        );
        $attestationIssuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $attestation->issuedAt,
            'native supervisor issue time',
        );
        $attestationExpiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $attestation->expiresAt,
            'native supervisor expiry time',
        );

        if ($issuedAt > $observedAt
            || $expiresAt <= $observedAt
            || $issuedAt < $attestationIssuedAt
            || $expiresAt > $attestationExpiresAt) {
            throw new InvalidArgumentException('The brokered effect result is outside the verified supervisor validity window.');
        }

        self::assertSame($attestation->launchManifestSha256, $result->launchManifestSha256, 'launch manifest');
        self::assertSame($attestation->runNonce, $result->runNonce, 'run nonce');
        self::assertSame($attestation->authorizationSetSha256, $result->authorizationSetSha256, 'authorization set');
        self::assertSame($policy->brokerPolicySha256, $result->brokerPolicySha256, 'broker policy');
        self::assertSame($attestation->sha256(), $result->supervisorAttestationSha256, 'supervisor attestation');
        self::assertSame($effectDescriptorSha256, $result->effectDescriptorSha256, 'effect descriptor');
        self::assertSame($effectId, $result->effectId, 'effect identity');
        self::assertSame($casRecordSha256, $result->casRecordSha256, 'CAS record');
        self::assertExecutionTimeline($result, $attestationIssuedAt, $issuedAt);

        return $result;
    }

    private static function assertExecutionTimeline(
        BrokeredEffectExecutionResult $result,
        DateTimeImmutable $attestationIssuedAt,
        DateTimeImmutable $resultIssuedAt,
    ): void {
        if ($result->requestStartedAt === null) {
            return;
        }

        $requestStartedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $result->requestStartedAt,
            'brokered effect request start',
        );

        if ($requestStartedAt < $attestationIssuedAt || $requestStartedAt > $resultIssuedAt) {
            throw new InvalidArgumentException('The brokered effect request timeline is outside the attested execution window.');
        }

        if ($result->responseReceivedAt === null) {
            return;
        }

        $responseReceivedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $result->responseReceivedAt,
            'brokered effect response time',
        );

        if ($responseReceivedAt < $requestStartedAt || $responseReceivedAt > $resultIssuedAt) {
            throw new InvalidArgumentException('The brokered effect response timeline is outside the attested execution window.');
        }
    }

    private static function assertSame(
        #[SensitiveParameter] string $expected,
        #[SensitiveParameter] string $actual,
        string $context,
    ): void {
        if (! \hash_equals($expected, $actual)) {
            throw new InvalidArgumentException("The brokered effect result {$context} binding does not match.");
        }
    }
}
