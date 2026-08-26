<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class SignedLiveProbeAuthorization
{
    public const Contract = 'cieplik206.fakturownia.live-probe-authorization';

    public const Version = '1';

    public const Algorithm = 'Ed25519';

    public const CommitmentScheme = 'hmac-sha256-ephemeral-run-key-v1';

    public const InvoiceIdentityEvidenceContract = 'fakturownia-invoice-identity-s0.3-v1';

    public const KsefDemoEvidenceContract = 'fakturownia-ksef-demo-s0.4-v1';

    /**
     * @param  array{repository_commit: string, code_sha256: string, launch_manifest_sha256: string}  $harness
     * @param  array{environment: string, profile: string, tenant_hmac_sha256: string, account_hmac_sha256: string}  $target
     * @param  array{scheme: string, configuration_hmac_sha256: string, policy_hmac_sha256: string, safety_hmac_sha256: string, templates_hmac_sha256: string}  $commitments
     * @param  array{authority_id: string, authority_policy_sha256: string, store_id: string, store_identity_sha256: string, run_id: string, replay_policy: string}  $consumption
     * @param  non-empty-array<string, mixed>  $limits
     */
    private function __construct(
        public string $signerId,
        public string $issuedAt,
        public string $expiresAt,
        public string $evidenceContract,
        public string $challenge,
        public array $harness,
        public array $target,
        public array $commitments,
        public array $consumption,
        public array $limits,
        public string $signature,
    ) {}

    /** @param array<string, mixed> $document */
    public static function fromArray(#[SensitiveParameter] array $document): self
    {
        self::assertExactKeys($document, ['envelope', 'signature']);

        $envelope = $document['envelope'] ?? null;
        $signature = $document['signature'] ?? null;

        if (! \is_array($envelope)
            || \array_is_list($envelope)
            || ! \is_string($signature)) {
            throw new InvalidArgumentException('A signed live-probe authorization must contain one exact envelope and signature.');
        }

        self::assertExactKeys($envelope, [
            'contract',
            'version',
            'algorithm',
            'signer_id',
            'issued_at',
            'expires_at',
            'evidence_contract',
            'challenge',
            'harness',
            'target',
            'commitments',
            'consumption',
            'limits',
        ]);

        if (($envelope['contract'] ?? null) !== self::Contract
            || ($envelope['version'] ?? null) !== self::Version
            || ($envelope['algorithm'] ?? null) !== self::Algorithm) {
            throw new InvalidArgumentException('The live-probe authorization must use the exact version 1 contract.');
        }

        $signerId = self::string($envelope, 'signer_id');
        $issuedAt = self::string($envelope, 'issued_at');
        $expiresAt = self::string($envelope, 'expires_at');
        $evidenceContract = self::string($envelope, 'evidence_contract');
        $challenge = self::string($envelope, 'challenge');

        self::assertIdentifier($signerId, 'signer ID');
        self::strictUtcMicrosecondInstant($issuedAt, 'issued time');
        self::strictUtcMicrosecondInstant($expiresAt, 'expiry time');

        if (! \in_array($evidenceContract, [
            self::InvoiceIdentityEvidenceContract,
            self::KsefDemoEvidenceContract,
        ], true)) {
            throw new InvalidArgumentException('The live-probe authorization evidence contract is not allowlisted.');
        }

        self::assertCanonicalBase64Bytes($challenge, 32, 'challenge');
        self::assertCanonicalBase64Bytes($signature, 64, 'signature');

        $harness = self::harness($envelope['harness'] ?? null);
        $target = self::target($envelope['target'] ?? null);
        $commitments = self::commitments($envelope['commitments'] ?? null);
        $consumption = self::consumption($envelope['consumption'] ?? null);
        $limits = self::limits($envelope['limits'] ?? null);

        return new self(
            $signerId,
            $issuedAt,
            $expiresAt,
            $evidenceContract,
            $challenge,
            $harness,
            $target,
            $commitments,
            $consumption,
            $limits,
            $signature,
        );
    }

    /** @return array<string, mixed> */
    public function envelope(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'algorithm' => self::Algorithm,
            'signer_id' => $this->signerId,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'evidence_contract' => $this->evidenceContract,
            'challenge' => $this->challenge,
            'harness' => $this->harness,
            'target' => $this->target,
            'commitments' => $this->commitments,
            'consumption' => $this->consumption,
            'limits' => $this->limits,
        ];
    }

    /** @return array{envelope: array<string, mixed>, signature: string} */
    public function toArray(): array
    {
        return [
            'envelope' => $this->envelope(),
            'signature' => $this->signature,
        ];
    }

    public function canonicalEnvelope(): string
    {
        return CanonicalCodec::encode($this->envelope());
    }

    public function canonical(): string
    {
        return CanonicalCodec::encode($this->toArray());
    }

    public function sha256(): string
    {
        return \hash('sha256', $this->canonical());
    }

    public function challengeSha256(): string
    {
        return \hash('sha256', $this->challenge);
    }

    public function issuedAtInstant(): DateTimeImmutable
    {
        return self::strictUtcMicrosecondInstant($this->issuedAt, 'issued time');
    }

    public function expiresAtInstant(): DateTimeImmutable
    {
        return self::strictUtcMicrosecondInstant($this->expiresAt, 'expiry time');
    }

    /** @return array{repository_commit: string, code_sha256: string, launch_manifest_sha256: string} */
    private static function harness(#[SensitiveParameter] mixed $value): array
    {
        if (! \is_array($value) || \array_is_list($value)) {
            throw new InvalidArgumentException('The live-probe authorization harness must be an object.');
        }

        self::assertExactKeys($value, ['repository_commit', 'code_sha256', 'launch_manifest_sha256']);
        $repositoryCommit = self::string($value, 'repository_commit');
        $codeSha256 = self::string($value, 'code_sha256');
        $launchManifestSha256 = self::string($value, 'launch_manifest_sha256');

        if (\preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $repositoryCommit) !== 1) {
            throw new InvalidArgumentException('The live-probe authorization repository commit is invalid.');
        }

        self::assertSha256($codeSha256, 'harness code');
        self::assertSha256($launchManifestSha256, 'launch manifest');

        return [
            'repository_commit' => $repositoryCommit,
            'code_sha256' => $codeSha256,
            'launch_manifest_sha256' => $launchManifestSha256,
        ];
    }

    /** @return array{environment: string, profile: string, tenant_hmac_sha256: string, account_hmac_sha256: string} */
    private static function target(#[SensitiveParameter] mixed $value): array
    {
        if (! \is_array($value) || \array_is_list($value)) {
            throw new InvalidArgumentException('The live-probe authorization target must be an object.');
        }

        self::assertExactKeys($value, ['environment', 'profile', 'tenant_hmac_sha256', 'account_hmac_sha256']);
        $environment = self::string($value, 'environment');
        $profile = self::string($value, 'profile');
        $tenant = self::string($value, 'tenant_hmac_sha256');
        $account = self::string($value, 'account_hmac_sha256');

        if (\preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $environment) !== 1
            || \preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $profile) !== 1) {
            throw new InvalidArgumentException('The live-probe authorization target identifiers are invalid.');
        }

        self::assertSha256($tenant, 'tenant HMAC');
        self::assertSha256($account, 'account HMAC');

        return [
            'environment' => $environment,
            'profile' => $profile,
            'tenant_hmac_sha256' => $tenant,
            'account_hmac_sha256' => $account,
        ];
    }

    /** @return array{scheme: string, configuration_hmac_sha256: string, policy_hmac_sha256: string, safety_hmac_sha256: string, templates_hmac_sha256: string} */
    private static function commitments(#[SensitiveParameter] mixed $value): array
    {
        if (! \is_array($value) || \array_is_list($value)) {
            throw new InvalidArgumentException('The live-probe authorization commitments must be an object.');
        }

        self::assertExactKeys($value, [
            'scheme',
            'configuration_hmac_sha256',
            'policy_hmac_sha256',
            'safety_hmac_sha256',
            'templates_hmac_sha256',
        ]);

        if (($value['scheme'] ?? null) !== self::CommitmentScheme) {
            throw new InvalidArgumentException('The live-probe authorization commitment scheme is invalid.');
        }

        $configuration = self::string($value, 'configuration_hmac_sha256');
        $policy = self::string($value, 'policy_hmac_sha256');
        $safety = self::string($value, 'safety_hmac_sha256');
        $templates = self::string($value, 'templates_hmac_sha256');

        foreach ([$configuration, $policy, $safety, $templates] as $commitment) {
            self::assertSha256($commitment, 'commitment');
        }

        return [
            'scheme' => self::CommitmentScheme,
            'configuration_hmac_sha256' => $configuration,
            'policy_hmac_sha256' => $policy,
            'safety_hmac_sha256' => $safety,
            'templates_hmac_sha256' => $templates,
        ];
    }

    /** @return array{authority_id: string, authority_policy_sha256: string, store_id: string, store_identity_sha256: string, run_id: string, replay_policy: string} */
    private static function consumption(#[SensitiveParameter] mixed $value): array
    {
        if (! \is_array($value) || \array_is_list($value)) {
            throw new InvalidArgumentException('The live-probe authorization consumption policy must be an object.');
        }

        self::assertExactKeys($value, [
            'authority_id',
            'authority_policy_sha256',
            'store_id',
            'store_identity_sha256',
            'run_id',
            'replay_policy',
        ]);

        $authorityId = self::string($value, 'authority_id');
        $authorityPolicy = self::string($value, 'authority_policy_sha256');
        $storeId = self::string($value, 'store_id');
        $storeIdentity = self::string($value, 'store_identity_sha256');
        $runId = self::string($value, 'run_id');

        self::assertIdentifier($authorityId, 'authority ID');
        self::assertIdentifier($storeId, 'store ID');
        self::assertSha256($authorityPolicy, 'authority policy');
        self::assertSha256($storeIdentity, 'store identity');

        if (\preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1
            || ($value['replay_policy'] ?? null) !== ConsumptionClaimRequest::ReplayPolicy) {
            throw new InvalidArgumentException('The live-probe authorization consumption run or replay policy is invalid.');
        }

        return [
            'authority_id' => $authorityId,
            'authority_policy_sha256' => $authorityPolicy,
            'store_id' => $storeId,
            'store_identity_sha256' => $storeIdentity,
            'run_id' => $runId,
            'replay_policy' => ConsumptionClaimRequest::ReplayPolicy,
        ];
    }

    /** @return non-empty-array<string, mixed> */
    private static function limits(#[SensitiveParameter] mixed $value): array
    {
        if (! \is_array($value) || $value === [] || \array_is_list($value)) {
            throw new InvalidArgumentException('The live-probe authorization limits must be a non-empty object.');
        }

        CanonicalCodec::encode($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private static function assertExactKeys(
        #[SensitiveParameter] array $value,
        array $expectedKeys,
    ): void {
        $keys = \array_keys($value);
        \sort($keys, \SORT_STRING);
        \sort($expectedKeys, \SORT_STRING);

        if ($keys !== $expectedKeys) {
            throw new InvalidArgumentException('The live-probe authorization contains missing or unknown fields.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function string(#[SensitiveParameter] array $value, string $key): string
    {
        $result = $value[$key] ?? null;

        if (! \is_string($result)) {
            throw new InvalidArgumentException("The live-probe authorization field {$key} must be a string.");
        }

        return $result;
    }

    private static function assertIdentifier(#[SensitiveParameter] string $value, string $label): void
    {
        if (\preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The live-probe authorization {$label} is invalid.");
        }
    }

    private static function assertSha256(#[SensitiveParameter] string $value, string $label): void
    {
        if (\preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The live-probe authorization {$label} SHA-256 is invalid.");
        }
    }

    private static function assertCanonicalBase64Bytes(
        #[SensitiveParameter] string $value,
        int $bytes,
        string $label,
    ): void {
        $decoded = \base64_decode($value, true);

        if ($decoded === false || \strlen($decoded) !== $bytes || \base64_encode($decoded) !== $value) {
            throw new InvalidArgumentException("The live-probe authorization {$label} is not canonical base64.");
        }
    }

    private static function strictUtcMicrosecondInstant(
        #[SensitiveParameter] string $value,
        string $label,
    ): DateTimeImmutable {
        if (\preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1) {
            throw new InvalidArgumentException("The live-probe authorization {$label} is not a strict UTC microsecond instant.");
        }

        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($instant === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $instant->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new InvalidArgumentException("The live-probe authorization {$label} is invalid.");
        }

        return $instant;
    }
}
