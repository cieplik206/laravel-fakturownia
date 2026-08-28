<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use InvalidArgumentException;
use SensitiveParameter;

final class NativeSupervisorAttestationVerifier
{
    public static function verify(
        #[SensitiveParameter] NativeSupervisorAttestation $attestation,
        #[SensitiveParameter] NativeBrokerTrustPolicy $policy,
        DateTimeImmutable $observedAt,
        string $launchManifestSha256,
        #[SensitiveParameter] string $runNonce,
        string $authorizationSetSha256,
        string $authorizationBundleSha256,
        string $probePlanSha256,
    ): NativeSupervisorAttestation {
        NativeBrokerWireValidation::assertSha256($launchManifestSha256, 'expected launch manifest');
        NativeBrokerWireValidation::assertCanonicalBase64Bytes($runNonce, 32, 'expected native supervisor run nonce');
        NativeBrokerWireValidation::assertSha256($authorizationSetSha256, 'expected authorization set');
        NativeBrokerWireValidation::assertSha256($authorizationBundleSha256, 'expected authorization bundle');
        NativeBrokerWireValidation::assertSha256($probePlanSha256, 'expected probe plan');
        $policy->assertValidAt($observedAt);
        $policy->assertSupervisorAttestationSignature($attestation);
        $issuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $attestation->issuedAt,
            'native supervisor issue time',
        );
        $expiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $attestation->expiresAt,
            'native supervisor expiry time',
        );
        $policyExpiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $policy->expiresAt,
            'native broker trust policy expiry time',
        );

        if ($issuedAt > $observedAt
            || $expiresAt <= $observedAt
            || $expiresAt > $policyExpiresAt) {
            throw new InvalidArgumentException('The native supervisor attestation is not valid at the observation time.');
        }

        self::assertSame($launchManifestSha256, $attestation->launchManifestSha256, 'launch manifest');
        self::assertSame($runNonce, $attestation->runNonce, 'run nonce');
        self::assertSame($authorizationSetSha256, $attestation->authorizationSetSha256, 'authorization set');
        self::assertSame($authorizationBundleSha256, $attestation->authorizationBundleSha256, 'authorization bundle');
        self::assertSame($probePlanSha256, $attestation->probePlanSha256, 'probe plan');
        self::assertSame($policy->brokerPolicySha256, $attestation->brokerPolicySha256, 'broker policy');
        self::assertSame(
            $policy->supervisorSemanticsSha256,
            $attestation->supervisorSemanticsSha256,
            'supervisor semantics',
        );
        self::assertSame($policy->argvSha256, $attestation->argvSha256, 'argv contract');
        self::assertSame($policy->environmentSha256, $attestation->environmentSha256, 'environment contract');

        if ($policy->probeUid !== $attestation->probeUid || $policy->probeGid !== $attestation->probeGid) {
            throw new InvalidArgumentException('The native supervisor attestation probe identity does not match policy.');
        }

        return $attestation;
    }

    private static function assertSame(
        #[SensitiveParameter] string $expected,
        #[SensitiveParameter] string $actual,
        string $context,
    ): void {
        if (! \hash_equals($expected, $actual)) {
            throw new InvalidArgumentException("The native supervisor attestation {$context} binding does not match.");
        }
    }
}
