<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class NativeBrokerAuthorityHandoff implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.native-supervisor-authority';

    public const Version = '1';

    private const AuthorizationBundleContract = 'cieplik206.fakturownia.native-authorization-bundle';

    private const SetContract = 'cieplik206.fakturownia.authorization-consumption-set';

    /**
     * @param  non-empty-list<string>  $profiles
     * @param  non-empty-list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, mixed>  $consumptionReceipt
     */
    private function __construct(
        public NativeBrokerTrustPolicy $trustPolicy,
        public NativeSupervisorAttestation $supervisorAttestation,
        public NativeBrokerProbePlan $probePlan,
        public array $signedAuthorizations,
        public array $consumptionReceipt,
        public string $runId,
        public string $runStartedAt,
        public string $evidenceContract,
        public array $profiles,
        public string $claimNonce,
        public string $authorizationSetSha256,
        public string $authorizationBundleSha256,
        public string $claimRequestSha256,
        public string $consumptionReceiptSha256,
    ) {}

    /** @param array<string, mixed> $value */
    public static function verify(
        #[SensitiveParameter] array $value,
        string $expectedPolicySignerId,
        #[SensitiveParameter] string $expectedPolicyPublicKeyBase64,
        string $expectedLaunchManifestSha256,
        string $expectedSupervisorSemanticsSha256,
        DateTimeImmutable $observedAt,
    ): self {
        NativeBrokerWireValidation::assertExactKeys(
            $value,
            ['contract', 'version', 'trust_policy', 'supervisor_attestation', 'authorization', 'authorization_bundle'],
            'native broker authority handoff',
        );

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version
            || ! self::object($value['trust_policy'] ?? null)
            || ! self::object($value['supervisor_attestation'] ?? null)
            || ! self::object($value['authorization'] ?? null)
            || ! self::object($value['authorization_bundle'] ?? null)) {
            throw new InvalidArgumentException('The native broker authority handoff must use the exact version 1 contract.');
        }

        $policy = NativeBrokerTrustPolicy::verify(
            $value['trust_policy'],
            $expectedPolicySignerId,
            $expectedPolicyPublicKeyBase64,
            $observedAt,
        );
        NativeBrokerWireValidation::assertSha256(
            $expectedSupervisorSemanticsSha256,
            'expected native supervisor semantics',
        );

        if (! \hash_equals($expectedSupervisorSemanticsSha256, $policy->supervisorSemanticsSha256)) {
            throw new InvalidArgumentException('The native broker policy does not bind the expected supervisor semantics.');
        }

        $authorization = $value['authorization'];
        NativeBrokerWireValidation::assertExactKeys($authorization, [
            'run_id',
            'run_started_at',
            'evidence_contract',
            'profiles',
            'claim_nonce',
            'authorization_set_sha256',
            'claim_request_sha256',
            'consumption_receipt_sha256',
            'authorization_bundle_sha256',
            'probe_plan_sha256',
        ], 'native broker run authorization');
        $runId = NativeBrokerWireValidation::string($authorization, 'run_id', 'native broker run authorization');
        $runStartedAt = NativeBrokerWireValidation::string($authorization, 'run_started_at', 'native broker run authorization');
        $evidenceContract = NativeBrokerWireValidation::string($authorization, 'evidence_contract', 'native broker run authorization');
        $claimNonce = NativeBrokerWireValidation::string($authorization, 'claim_nonce', 'native broker run authorization');
        $authorizationSetSha256 = NativeBrokerWireValidation::string($authorization, 'authorization_set_sha256', 'native broker run authorization');
        $authorizationBundleSha256 = NativeBrokerWireValidation::string($authorization, 'authorization_bundle_sha256', 'native broker run authorization');
        $probePlanSha256 = NativeBrokerWireValidation::string($authorization, 'probe_plan_sha256', 'native broker run authorization');
        $claimRequestSha256 = NativeBrokerWireValidation::string($authorization, 'claim_request_sha256', 'native broker run authorization');
        $consumptionReceiptSha256 = NativeBrokerWireValidation::string($authorization, 'consumption_receipt_sha256', 'native broker run authorization');
        $profiles = self::profiles($authorization['profiles'] ?? null);

        if (\preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1
            || ! \in_array($evidenceContract, [
                SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
                SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
            ], true)) {
            throw new InvalidArgumentException('The native broker run authorization identity is invalid.');
        }

        $runStartedAtInstant = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $runStartedAt,
            'native broker run start',
        );
        NativeBrokerWireValidation::assertCanonicalBase64Bytes($claimNonce, 32, 'native broker claim nonce');

        foreach ([
            'authorization set' => $authorizationSetSha256,
            'authorization bundle' => $authorizationBundleSha256,
            'probe plan' => $probePlanSha256,
            'claim request' => $claimRequestSha256,
            'consumption receipt' => $consumptionReceiptSha256,
        ] as $context => $sha256) {
            NativeBrokerWireValidation::assertSha256($sha256, $context);
        }

        if ($runStartedAtInstant > $observedAt) {
            throw new InvalidArgumentException('The native broker run start is in the future.');
        }

        $bundle = self::authorizationBundle($value['authorization_bundle']);
        $actualBundleSha256 = \hash('sha256', CanonicalCodec::encode($bundle));

        if (! \hash_equals($authorizationBundleSha256, $actualBundleSha256)) {
            throw new InvalidArgumentException('The native broker authorization bundle hash does not match its handoff.');
        }

        $probePlan = NativeBrokerProbePlan::fromArray($bundle['probe_plan']);

        if (! \hash_equals($probePlanSha256, $probePlan->sha256())
            || $probePlan->evidenceContract() !== $evidenceContract) {
            throw new InvalidArgumentException('The native broker probe plan does not bind the authorized run.');
        }

        $signedAuthorizations = self::signedAuthorizations(
            $bundle['authorizations'],
            $evidenceContract,
            $runId,
            $expectedLaunchManifestSha256,
        );
        $actualProfiles = \array_map(
            static fn (SignedLiveProbeAuthorization $document): string => $document->target['profile'],
            $signedAuthorizations['documents'],
        );
        \sort($actualProfiles, \SORT_STRING);
        $actualAuthorizationSetSha256 = self::setSha256(\array_map(
            static fn (SignedLiveProbeAuthorization $document): array => [
                'profile' => $document->target['profile'],
                'sha256' => $document->sha256(),
            ],
            $signedAuthorizations['documents'],
        ));
        $actualChallengeSetSha256 = self::setSha256(\array_map(
            static fn (SignedLiveProbeAuthorization $document): array => [
                'profile' => $document->target['profile'],
                'sha256' => $document->challengeSha256(),
            ],
            $signedAuthorizations['documents'],
        ));
        $actualConfigurationSetSha256 = self::setSha256(\array_map(
            static fn (SignedLiveProbeAuthorization $document): array => [
                'profile' => $document->target['profile'],
                'sha256' => $document->commitments['configuration_hmac_sha256'],
            ],
            $signedAuthorizations['documents'],
        ));
        $consumptionReceipt = $bundle['consumption_receipt'];
        $receipt = ConsumptionReceipt::fromArray($consumptionReceipt);

        if ($receipt->envelope->disposition !== ConsumptionDisposition::FreshDirectGrant) {
            throw new InvalidArgumentException('The native broker authorization receipt is not a fresh direct grant.');
        }

        $actualConsumptionReceiptSha256 = \hash('sha256', CanonicalCodec::encode($consumptionReceipt));
        $receiptEnvelope = $consumptionReceipt['envelope'];
        $claimRequest = $receiptEnvelope['claim_request'] ?? null;

        if (! self::object($claimRequest)) {
            throw new InvalidArgumentException('The native broker consumption receipt has no exact claim request.');
        }

        $claim = ConsumptionClaimRequest::fromArray($claimRequest);
        $actualClaimRequestSha256 = \hash('sha256', CanonicalCodec::encode($claimRequest));

        foreach ([
            [$bundle['run_id'], $runId],
            [$bundle['run_started_at'], $runStartedAt],
            [$bundle['evidence_contract'], $evidenceContract],
            [$bundle['launch_manifest_sha256'], $expectedLaunchManifestSha256],
            [$bundle['authorization_set_sha256'], $authorizationSetSha256],
            [$actualAuthorizationSetSha256, $authorizationSetSha256],
            [$bundle['challenge_set_sha256'], $actualChallengeSetSha256],
            [$bundle['configuration_set_sha256'], $actualConfigurationSetSha256],
            [$bundle['claim_request_sha256'], $claimRequestSha256],
            [$actualClaimRequestSha256, $claimRequestSha256],
            [$bundle['consumption_receipt_sha256'], $consumptionReceiptSha256],
            [$actualConsumptionReceiptSha256, $consumptionReceiptSha256],
            [$bundle['claim_nonce'], $claimNonce],
        ] as [$expected, $actual]) {
            if (! \is_string($expected)
                || ! \hash_equals($expected, $actual)) {
                throw new InvalidArgumentException('The native broker authorization bundle does not bind its verified run context.');
            }
        }

        $planProfiles = $evidenceContract === SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract
            ? ['invoice_identity']
            : \array_map(
                static fn (array $target): mixed => $target['profile'] ?? null,
                $probePlan->targets(),
            );
        \sort($planProfiles, \SORT_STRING);

        if ($profiles !== $actualProfiles || $profiles !== $planProfiles) {
            throw new InvalidArgumentException('The native broker authorization profiles do not match the signed plan and bundle.');
        }

        foreach ($signedAuthorizations['documents'] as $document) {
            if ($document->consumption['authority_id'] !== $claim->authorityId
                || ! \hash_equals($document->consumption['authority_policy_sha256'], $claim->authorityPolicySha256)
                || $document->consumption['store_id'] !== $claim->storeId
                || ! \hash_equals($document->consumption['store_identity_sha256'], $claim->storeIdentitySha256)
                || $document->consumption['run_id'] !== $claim->runId
                || $document->harness !== $claim->harness
                || $document->issuedAtInstant() > $runStartedAtInstant
                || $document->expiresAtInstant() <= $runStartedAtInstant) {
                throw new InvalidArgumentException('A native broker authorization does not bind the consumed run.');
            }
        }

        if ($claim->runId !== $runId
            || $claim->runStartedAt !== $runStartedAt
            || $claim->claimNonce !== $claimNonce
            || ! \hash_equals($claim->authorizationSetSha256, $authorizationSetSha256)
            || ! \hash_equals($claim->challengeSetSha256, $actualChallengeSetSha256)
            || ! \hash_equals($claim->configurationSetSha256, $actualConfigurationSetSha256)) {
            throw new InvalidArgumentException('The native broker claim request does not bind the signed authorization set.');
        }

        $attestation = NativeSupervisorAttestation::fromArray($value['supervisor_attestation']);
        NativeSupervisorAttestationVerifier::verify(
            $attestation,
            $policy,
            $observedAt,
            $expectedLaunchManifestSha256,
            $attestation->runNonce,
            $authorizationSetSha256,
            $authorizationBundleSha256,
            $probePlanSha256,
        );
        $attestationIssuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $attestation->issuedAt,
            'native supervisor issue time',
        );

        if ($runStartedAtInstant > $attestationIssuedAt) {
            throw new InvalidArgumentException('The native broker run starts after its supervisor attestation.');
        }

        return new self(
            $policy,
            $attestation,
            $probePlan,
            $signedAuthorizations['raw'],
            $consumptionReceipt,
            $runId,
            $runStartedAt,
            $evidenceContract,
            $profiles,
            $claimNonce,
            $authorizationSetSha256,
            $authorizationBundleSha256,
            $claimRequestSha256,
            $consumptionReceiptSha256,
        );
    }

    /** @return array{native_broker_authority_handoff: string} */
    public function __debugInfo(): array
    {
        return ['native_broker_authority_handoff' => '[VERIFIED]'];
    }

    /** @return array{native_broker_authority_handoff: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Native broker authority handoffs cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Native broker authority handoffs cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Native broker authority handoffs cannot be unserialized.');
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function authorizationBundle(#[SensitiveParameter] array $value): array
    {
        NativeBrokerWireValidation::assertExactKeys($value, [
            'contract',
            'version',
            'run_id',
            'run_started_at',
            'claim_nonce',
            'evidence_contract',
            'launch_manifest_sha256',
            'authorization_set_sha256',
            'challenge_set_sha256',
            'configuration_set_sha256',
            'claim_request_sha256',
            'consumption_receipt_sha256',
            'probe_plan',
            'authorizations',
            'consumption_receipt',
        ], 'native broker authorization bundle');

        if (($value['contract'] ?? null) !== self::AuthorizationBundleContract
            || ($value['version'] ?? null) !== self::Version
            || ! self::object($value['probe_plan'] ?? null)
            || ! \is_array($value['authorizations'] ?? null)
            || ! \array_is_list($value['authorizations'])
            || ! self::object($value['consumption_receipt'] ?? null)) {
            throw new InvalidArgumentException('The native broker authorization bundle uses an invalid exact contract.');
        }

        foreach ([
            'launch_manifest_sha256',
            'authorization_set_sha256',
            'challenge_set_sha256',
            'configuration_set_sha256',
            'claim_request_sha256',
            'consumption_receipt_sha256',
        ] as $key) {
            NativeBrokerWireValidation::assertSha256(
                NativeBrokerWireValidation::string($value, $key, 'native broker authorization bundle'),
                "native broker authorization bundle {$key}",
            );
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $value
     * @return array{documents: non-empty-list<SignedLiveProbeAuthorization>, raw: non-empty-list<array<string, mixed>>}
     */
    private static function signedAuthorizations(
        #[SensitiveParameter] array $value,
        string $evidenceContract,
        string $runId,
        string $launchManifestSha256,
    ): array {
        if ($value === [] || \count($value) > 16) {
            throw new InvalidArgumentException('The native broker authorization bundle must contain a bounded non-empty document list.');
        }

        $documents = [];
        $raw = [];
        $profiles = [];

        foreach ($value as $document) {
            if (! self::object($document)) {
                throw new InvalidArgumentException('A native broker signed authorization must be an object.');
            }

            $authorization = SignedLiveProbeAuthorization::fromArray($document);

            if ($authorization->evidenceContract !== $evidenceContract
                || $authorization->consumption['run_id'] !== $runId
                || ! \hash_equals($authorization->harness['launch_manifest_sha256'], $launchManifestSha256)) {
                throw new InvalidArgumentException('A native broker signed authorization belongs to another run or harness.');
            }

            $profiles[] = $authorization->target['profile'];
            $documents[] = $authorization;
            $raw[] = $document;
        }

        if (\count($profiles) !== \count(\array_unique($profiles))) {
            throw new InvalidArgumentException('The native broker authorization bundle contains a duplicate profile.');
        }

        /** @var non-empty-list<SignedLiveProbeAuthorization> $documents */
        /** @var non-empty-list<array<string, mixed>> $raw */
        return ['documents' => $documents, 'raw' => $raw];
    }

    /** @param list<array{profile: string, sha256: string}> $rows */
    private static function setSha256(#[SensitiveParameter] array $rows): string
    {
        \usort($rows, static fn (array $left, array $right): int => $left['profile'] <=> $right['profile']);

        return \hash('sha256', CanonicalCodec::encode([
            'contract' => self::SetContract,
            'version' => self::Version,
            'value' => $rows,
        ]));
    }

    /** @return non-empty-list<string> */
    private static function profiles(#[SensitiveParameter] mixed $value): array
    {
        if (! \is_array($value)
            || ! \array_is_list($value)
            || $value === []
            || \count($value) > 16) {
            throw new InvalidArgumentException('The native broker profiles must be one bounded list.');
        }

        $profiles = [];

        foreach ($value as $profile) {
            if (! \is_string($profile)
                || \preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $profile) !== 1) {
                throw new InvalidArgumentException('A native broker profile is invalid.');
            }

            $profiles[] = $profile;
        }

        $sorted = $profiles;
        \sort($sorted, \SORT_STRING);

        if ($profiles !== $sorted || \count($profiles) !== \count(\array_unique($profiles))) {
            throw new InvalidArgumentException('The native broker profiles must be sorted and unique.');
        }

        return $profiles;
    }

    private static function object(#[SensitiveParameter] mixed $value): bool
    {
        return \is_array($value) && ! \array_is_list($value);
    }
}
