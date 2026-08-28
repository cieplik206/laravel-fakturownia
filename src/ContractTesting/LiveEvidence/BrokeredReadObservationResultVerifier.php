<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use InvalidArgumentException;
use SensitiveParameter;

final class BrokeredReadObservationResultVerifier
{
    public static function verify(
        #[SensitiveParameter] BrokeredReadObservationResult $result,
        #[SensitiveParameter] BrokeredReadObservationProposal $proposal,
        #[SensitiveParameter] NativeSupervisorAttestation $attestation,
        #[SensitiveParameter] NativeBrokerTrustPolicy $policy,
        DateTimeImmutable $observedAt,
    ): BrokeredReadObservationResult {
        NativeSupervisorAttestationVerifier::verify(
            $attestation,
            $policy,
            $observedAt,
            $attestation->launchManifestSha256,
            $attestation->runNonce,
            $attestation->authorizationSetSha256,
            $attestation->authorizationBundleSha256,
            $attestation->probePlanSha256,
        );
        $policy->assertReadObservationResultSignature($result);
        $issuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $result->issuedAt,
            'brokered read result issue time',
        );
        $expiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $result->expiresAt,
            'brokered read result expiry time',
        );
        $requestStartedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $result->requestStartedAt,
            'brokered read request time',
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
            || $expiresAt > $attestationExpiresAt
            || $requestStartedAt < $attestationIssuedAt
            || $requestStartedAt > $issuedAt) {
            throw new InvalidArgumentException('The brokered read result is outside the attested execution window.');
        }

        foreach ([
            [$attestation->launchManifestSha256, $result->launchManifestSha256],
            [$attestation->runNonce, $result->runNonce],
            [$attestation->authorizationSetSha256, $result->authorizationSetSha256],
            [$attestation->authorizationBundleSha256, $result->authorizationBundleSha256],
            [$attestation->probePlanSha256, $result->probePlanSha256],
            [$policy->brokerPolicySha256, $result->brokerPolicySha256],
            [$attestation->sha256(), $result->supervisorAttestationSha256],
            [$proposal->sha256(), $result->proposalSha256],
            [$proposal->observationId, $result->observationId],
        ] as [$expected, $actual]) {
            if (! \hash_equals($expected, $actual)) {
                throw new InvalidArgumentException('The brokered read result does not bind its proposal and authority.');
            }
        }

        if ($result->responseReceivedAt !== null) {
            $receivedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
                $result->responseReceivedAt,
                'brokered read response time',
            );

            if ($receivedAt < $requestStartedAt || $receivedAt > $issuedAt) {
                throw new InvalidArgumentException('The brokered read response timeline is invalid.');
            }
        }

        return $result;
    }
}
