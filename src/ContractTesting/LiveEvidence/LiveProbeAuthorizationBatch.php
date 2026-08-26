<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use SensitiveParameter;

final readonly class LiveProbeAuthorizationBatch
{
    /**
     * @param  array{repository_commit: string, code_sha256: string, launch_manifest_sha256: string}  $harness
     * @param  non-empty-list<array{profile: string, authorization_sha256: string, challenge_sha256: string, configuration_hmac_sha256: string}>  $rows
     */
    public function __construct(
        #[SensitiveParameter] public string $authorityId,
        #[SensitiveParameter] public string $authorityPolicySha256,
        #[SensitiveParameter] public string $storeId,
        #[SensitiveParameter] public string $storeIdentitySha256,
        #[SensitiveParameter] public string $runId,
        #[SensitiveParameter] public string $runStartedAt,
        #[SensitiveParameter] public string $claimNonce,
        #[SensitiveParameter] public string $evidenceContract,
        #[SensitiveParameter] public string $replayPolicy,
        #[SensitiveParameter] public array $harness,
        #[SensitiveParameter] public string $authorizationSetSha256,
        #[SensitiveParameter] public string $challengeSetSha256,
        #[SensitiveParameter] public string $configurationSetSha256,
        #[SensitiveParameter] public array $rows,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'authority_id' => $this->authorityId,
            'authority_policy_sha256' => $this->authorityPolicySha256,
            'store_id' => $this->storeId,
            'store_identity_sha256' => $this->storeIdentitySha256,
            'run_id' => $this->runId,
            'run_started_at' => $this->runStartedAt,
            'claim_nonce' => $this->claimNonce,
            'evidence_contract' => $this->evidenceContract,
            'replay_policy' => $this->replayPolicy,
            'harness' => $this->harness,
            'authorization_set_sha256' => $this->authorizationSetSha256,
            'challenge_set_sha256' => $this->challengeSetSha256,
            'configuration_set_sha256' => $this->configurationSetSha256,
            'rows' => $this->rows,
        ];
    }
}
