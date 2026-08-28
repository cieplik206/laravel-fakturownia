<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use RuntimeException;
use SensitiveParameter;

final class NativeBrokerSession implements JsonSerializable
{
    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    private function __construct(
        private $input,
        private $output,
        public readonly NativeBrokerAuthorityHandoff $authority,
    ) {}

    public static function fromStandardStreams(?DateTimeImmutable $observedAt = null): self
    {
        $policySignerId = \getenv('FAKTUROWNIA_NATIVE_TRUST_POLICY_SIGNER_ID');
        $policyPublicKey = \getenv('FAKTUROWNIA_NATIVE_TRUST_POLICY_PUBLIC_KEY_BASE64');
        $launchManifestSha256 = \getenv('FAKTUROWNIA_PREAUTOLOAD_VERIFIED_MANIFEST_SHA256');
        $supervisorSemanticsSha256 = \getenv('FAKTUROWNIA_NATIVE_SUPERVISOR_SEMANTICS_SHA256');

        if (! \is_string($policySignerId)
            || ! \is_string($policyPublicKey)
            || ! \is_string($launchManifestSha256)
            || ! \is_string($supervisorSemanticsSha256)) {
            throw new RuntimeException('The native broker trust pins are unavailable.');
        }

        return self::fromStreams(
            \STDIN,
            \STDOUT,
            $policySignerId,
            $policyPublicKey,
            $launchManifestSha256,
            $supervisorSemanticsSha256,
            $observedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    public static function fromStreams(
        $input,
        $output,
        string $expectedPolicySignerId,
        #[SensitiveParameter] string $expectedPolicyPublicKeyBase64,
        string $expectedLaunchManifestSha256,
        string $expectedSupervisorSemanticsSha256,
        DateTimeImmutable $observedAt,
    ): self {
        if (! \is_resource($input) || ! \is_resource($output)) {
            throw new InvalidArgumentException('Native broker session streams are invalid.');
        }

        $handoff = NativeBrokerAuthorityHandoff::verify(
            NativeBrokerWireFrame::readFromStream($input),
            $expectedPolicySignerId,
            $expectedPolicyPublicKeyBase64,
            $expectedLaunchManifestSha256,
            $expectedSupervisorSemanticsSha256,
            $observedAt,
        );

        return new self($input, $output, $handoff);
    }

    public function execute(
        #[SensitiveParameter] BrokeredEffectExecutionProposal $proposal,
        ?DateTimeImmutable $observedAt = null,
    ): BrokeredEffectExecutionResponse {
        if ($proposal->evidenceContract !== $this->authority->evidenceContract
            || ! \in_array($proposal->profile, $this->authority->profiles, true)) {
            throw new InvalidArgumentException('The brokered effect proposal is outside the authorized profile set.');
        }

        NativeBrokerWireFrame::writeToStream($this->output, $proposal->toArray());
        $response = BrokeredEffectExecutionResponse::fromArray(
            NativeBrokerWireFrame::readFromStream($this->input),
        );
        $observedAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->verifyExecution($proposal, $response, $observedAt);

        return $response;
    }

    public function executeConcurrent(
        #[SensitiveParameter] ConcurrentBrokeredEffectExecutionProposal $proposal,
        ?DateTimeImmutable $observedAt = null,
    ): ConcurrentBrokeredEffectExecutionResponse {
        foreach ($proposal->proposals as $effect) {
            if ($effect->evidenceContract !== $this->authority->evidenceContract
                || ! \in_array($effect->profile, $this->authority->profiles, true)) {
                throw new InvalidArgumentException('A concurrent brokered effect is outside the authorized profile set.');
            }
        }

        NativeBrokerWireFrame::writeToStream($this->output, $proposal->toArray());
        $response = ConcurrentBrokeredEffectExecutionResponse::fromArray(
            NativeBrokerWireFrame::readFromStream($this->input),
        );
        $observedAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($proposal->proposals as $index => $effect) {
            $this->verifyExecution($effect, $response->responses[$index], $observedAt);
        }

        return $response;
    }

    public function observe(
        #[SensitiveParameter] BrokeredReadObservationProposal $proposal,
        ?DateTimeImmutable $observedAt = null,
    ): BrokeredReadObservationResponse {
        if ($proposal->evidenceContract !== $this->authority->evidenceContract
            || ! \in_array($proposal->profile, $this->authority->profiles, true)) {
            throw new InvalidArgumentException('The brokered read observation is outside the authorized profile set.');
        }

        NativeBrokerWireFrame::writeToStream($this->output, $proposal->toArray());
        $response = BrokeredReadObservationResponse::fromArray(
            NativeBrokerWireFrame::readFromStream($this->input),
        );
        BrokeredReadObservationResultVerifier::verify(
            $response->result,
            $proposal,
            $this->authority->supervisorAttestation,
            $this->authority->trustPolicy,
            $observedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        return $response;
    }

    /** @return array{native_broker_session: string} */
    public function __debugInfo(): array
    {
        return ['native_broker_session' => '[VERIFIED]'];
    }

    /** @return array{native_broker_session: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Native broker sessions cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Native broker sessions cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Native broker sessions cannot be unserialized.');
    }

    private function assertProposalBindings(
        BrokeredEffectExecutionProposal $proposal,
        LiveEffectDescriptor $descriptor,
    ): void {
        foreach ([
            [$proposal->evidenceContract, $descriptor->evidenceContract],
            [$proposal->effectId, $descriptor->effectId],
            [(string) $proposal->effectSequence, (string) $descriptor->effectSequence],
            [$proposal->profile, $descriptor->profile],
            [$proposal->targetKey, $descriptor->targetKey],
            [$proposal->capability, $descriptor->capability],
            [$proposal->semanticEffect, $descriptor->semanticEffect],
            [$proposal->httpMethod, $descriptor->httpMethod],
            [$proposal->endpointTemplate, $descriptor->endpointTemplate],
            [(string) \strlen($proposal->requestBody()), (string) $descriptor->requestBodySizeBytes],
            [(string) $proposal->connectTimeoutMs, (string) $descriptor->connectTimeoutMs],
            [(string) $proposal->requestTimeoutMs, (string) $descriptor->requestTimeoutMs],
            [(string) $proposal->maximumResponseBytes, (string) $descriptor->maximumResponseBytes],
            [$this->authority->runId, $descriptor->runId],
            [$this->authority->runStartedAt, $descriptor->runStartedAt],
            [$this->authority->claimNonce, $descriptor->claimNonce],
            [$this->authority->authorizationSetSha256, $descriptor->authorizationSetSha256],
            [$this->authority->authorizationBundleSha256, $descriptor->authorizationBundleSha256],
            [$this->authority->probePlan->sha256(), $descriptor->probePlanSha256],
            [$this->authority->claimRequestSha256, $descriptor->claimRequestSha256],
            [$this->authority->consumptionReceiptSha256, $descriptor->consumptionReceiptSha256],
            [$this->authority->supervisorAttestation->launchManifestSha256, $descriptor->launchManifestSha256],
            [$this->authority->supervisorAttestation->sha256(), $descriptor->supervisorAttestationSha256],
            [$this->authority->trustPolicy->brokerPolicySha256, $descriptor->brokerPolicySha256],
        ] as [$expected, $actual]) {
            if (! \hash_equals($expected, $actual)) {
                throw new InvalidArgumentException('The native broker response does not bind the exact effect proposal and authority.');
            }
        }
    }

    private function verifyExecution(
        BrokeredEffectExecutionProposal $proposal,
        BrokeredEffectExecutionResponse $response,
        DateTimeImmutable $observedAt,
    ): void {
        $this->assertProposalBindings($proposal, $response->descriptor);
        BrokeredEffectExecutionResultVerifier::verify(
            $response->result,
            $this->authority->supervisorAttestation,
            $this->authority->trustPolicy,
            $observedAt,
            $this->authority->supervisorAttestation->launchManifestSha256,
            $this->authority->supervisorAttestation->runNonce,
            $this->authority->authorizationSetSha256,
            $response->descriptor->sha256(),
            $proposal->effectId,
            $response->result->casRecordSha256,
        );
        $this->authority->trustPolicy->assertEffectExecutionReceiptSignature($response->receipt);
        $issuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $response->receipt->issuedAt,
            'brokered effect receipt issue time',
        );
        $expiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $response->receipt->expiresAt,
            'brokered effect receipt expiry time',
        );
        $attestationIssuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $this->authority->supervisorAttestation->issuedAt,
            'native supervisor issue time',
        );
        $attestationExpiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $this->authority->supervisorAttestation->expiresAt,
            'native supervisor expiry time',
        );

        if ($issuedAt > $observedAt
            || $expiresAt <= $observedAt
            || $issuedAt < $attestationIssuedAt
            || $expiresAt > $attestationExpiresAt) {
            throw new InvalidArgumentException('The brokered effect receipt is outside the attested execution window.');
        }
    }
}
