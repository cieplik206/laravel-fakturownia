<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/**
 * Canonical, privacy-preserving specification for a future broker-owned
 * one-shot provider effect. This value never authorizes or executes an effect.
 */
final readonly class LiveEffectDescriptor implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.live-effect-descriptor';

    public const Version = '1';

    /** @var list<string> */
    public const AllowedEndpointTemplates = [
        '/invoices.json',
        '/invoices/{invoice_id}.json?send_to_ksef=yes',
    ];

    /**
     * @var list<array{
     *     evidence_contract: string,
     *     profiles: non-empty-list<string>,
     *     capability: string,
     *     semantic_effect: string,
     *     method: string,
     *     endpoint: string,
     *     request_body_policy: string,
     *     maximum_effect_sequence: int
     * }>
     */
    private const AllowedOperations = [
        [
            'evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
            'profiles' => ['invoice_identity'],
            'capability' => 'invoice.vat.issue',
            'semantic_effect' => 'invoice_create',
            'method' => 'POST',
            'endpoint' => '/invoices.json',
            'request_body_policy' => 'required_non_empty',
            'maximum_effect_sequence' => 11,
        ],
        [
            'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
            'profiles' => ['explicit_block', 'explicit_persist', 'auto_block', 'auto_persist'],
            'capability' => 'contract_probe.invoice.fixture.issue',
            'semantic_effect' => 'probe_fixture_invoice_create',
            'method' => 'POST',
            'endpoint' => '/invoices.json',
            'request_body_policy' => 'required_non_empty',
            'maximum_effect_sequence' => 8,
        ],
        [
            'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
            'profiles' => ['explicit_block', 'explicit_persist'],
            'capability' => 'invoice.ksef.ensure_accepted',
            'semantic_effect' => 'ksef_explicit_submit',
            'method' => 'GET',
            'endpoint' => '/invoices/{invoice_id}.json?send_to_ksef=yes',
            'request_body_policy' => 'must_be_empty',
            'maximum_effect_sequence' => 8,
        ],
    ];

    private function __construct(
        public string $evidenceContract,
        public string $runId,
        public string $effectId,
        public int $effectSequence,
        public string $profile,
        public string $capability,
        public string $semanticEffect,
        public string $httpMethod,
        public string $endpointTemplate,
        public string $commitmentScheme,
        public string $targetOriginHmacSha256,
        public string $operationIdentityHmacSha256,
        public string $requestBodyHmacSha256,
        public int $requestBodySizeBytes,
        public string $requestBodyPolicy,
        public string $launchManifestSha256,
        public string $supervisorAttestationSha256,
        public string $brokerPolicySha256,
        public string $authorizationSetSha256,
        public string $claimRequestSha256,
        public string $consumptionReceiptSha256,
        public string $claimNonce,
        public string $runStartedAt,
        public int $connectTimeoutMs,
        public int $requestTimeoutMs,
        public int $maximumResponseBytes,
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        self::assertExactKeys($value, [
            'contract',
            'version',
            'evidence_contract',
            'run_id',
            'effect_id',
            'effect_sequence',
            'profile',
            'capability',
            'semantic_effect',
            'http_method',
            'endpoint_template',
            'commitment_scheme',
            'target_origin_hmac_sha256',
            'operation_identity_hmac_sha256',
            'request_body_hmac_sha256',
            'request_body_size_bytes',
            'request_body_policy',
            'launch_manifest_sha256',
            'supervisor_attestation_sha256',
            'broker_policy_sha256',
            'authorization_set_sha256',
            'claim_request_sha256',
            'consumption_receipt_sha256',
            'claim_nonce',
            'run_started_at',
            'connect_timeout_ms',
            'request_timeout_ms',
            'maximum_response_bytes',
        ]);

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version) {
            throw new InvalidArgumentException('The live-effect descriptor must use the exact version 1 contract.');
        }

        $descriptor = new self(
            self::string($value, 'evidence_contract'),
            self::string($value, 'run_id'),
            self::string($value, 'effect_id'),
            self::integer($value, 'effect_sequence'),
            self::string($value, 'profile'),
            self::string($value, 'capability'),
            self::string($value, 'semantic_effect'),
            self::string($value, 'http_method'),
            self::string($value, 'endpoint_template'),
            self::string($value, 'commitment_scheme'),
            self::string($value, 'target_origin_hmac_sha256'),
            self::string($value, 'operation_identity_hmac_sha256'),
            self::string($value, 'request_body_hmac_sha256'),
            self::integer($value, 'request_body_size_bytes'),
            self::string($value, 'request_body_policy'),
            self::string($value, 'launch_manifest_sha256'),
            self::string($value, 'supervisor_attestation_sha256'),
            self::string($value, 'broker_policy_sha256'),
            self::string($value, 'authorization_set_sha256'),
            self::string($value, 'claim_request_sha256'),
            self::string($value, 'consumption_receipt_sha256'),
            self::string($value, 'claim_nonce'),
            self::string($value, 'run_started_at'),
            self::integer($value, 'connect_timeout_ms'),
            self::integer($value, 'request_timeout_ms'),
            self::integer($value, 'maximum_response_bytes'),
        );
        $descriptor->assertValid();

        return $descriptor;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'evidence_contract' => $this->evidenceContract,
            'run_id' => $this->runId,
            'effect_id' => $this->effectId,
            'effect_sequence' => $this->effectSequence,
            'profile' => $this->profile,
            'capability' => $this->capability,
            'semantic_effect' => $this->semanticEffect,
            'http_method' => $this->httpMethod,
            'endpoint_template' => $this->endpointTemplate,
            'commitment_scheme' => $this->commitmentScheme,
            'target_origin_hmac_sha256' => $this->targetOriginHmacSha256,
            'operation_identity_hmac_sha256' => $this->operationIdentityHmacSha256,
            'request_body_hmac_sha256' => $this->requestBodyHmacSha256,
            'request_body_size_bytes' => $this->requestBodySizeBytes,
            'request_body_policy' => $this->requestBodyPolicy,
            'launch_manifest_sha256' => $this->launchManifestSha256,
            'supervisor_attestation_sha256' => $this->supervisorAttestationSha256,
            'broker_policy_sha256' => $this->brokerPolicySha256,
            'authorization_set_sha256' => $this->authorizationSetSha256,
            'claim_request_sha256' => $this->claimRequestSha256,
            'consumption_receipt_sha256' => $this->consumptionReceiptSha256,
            'claim_nonce' => $this->claimNonce,
            'run_started_at' => $this->runStartedAt,
            'connect_timeout_ms' => $this->connectTimeoutMs,
            'request_timeout_ms' => $this->requestTimeoutMs,
            'maximum_response_bytes' => $this->maximumResponseBytes,
        ];
    }

    public function canonical(): string
    {
        return CanonicalCodec::encode($this->toArray());
    }

    public function sha256(): string
    {
        return \hash('sha256', $this->canonical());
    }

    /** @return array{descriptor: string, target: string, operation: string, request: string, authority: string} */
    public function __debugInfo(): array
    {
        return [
            'descriptor' => '[REDACTED]',
            'target' => '[REDACTED]',
            'operation' => '[REDACTED]',
            'request' => '[REDACTED]',
            'authority' => '[REDACTED]',
        ];
    }

    /** @return array{descriptor: string, target: string, operation: string, request: string, authority: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Live-effect descriptors cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Live-effect descriptors cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Live-effect descriptors cannot be unserialized.');
    }

    private function assertValid(): void
    {
        if (\preg_match('/^[a-f0-9]{32}$/D', $this->runId) !== 1
            || \preg_match('/^[a-f0-9]{32}$/D', $this->effectId) !== 1) {
            throw new InvalidArgumentException('The live-effect run or effect identity is invalid.');
        }

        $operation = $this->matchingOperation();

        if ($this->effectSequence < 1
            || $this->effectSequence > $operation['maximum_effect_sequence']) {
            throw new InvalidArgumentException('The live-effect sequence exceeds the exact contract budget.');
        }

        if ($this->commitmentScheme !== SignedLiveProbeAuthorization::CommitmentScheme) {
            throw new InvalidArgumentException('The live-effect commitment scheme is invalid.');
        }

        foreach ([
            'target origin HMAC' => $this->targetOriginHmacSha256,
            'operation identity HMAC' => $this->operationIdentityHmacSha256,
            'request body HMAC' => $this->requestBodyHmacSha256,
            'launch manifest' => $this->launchManifestSha256,
            'supervisor attestation' => $this->supervisorAttestationSha256,
            'broker policy' => $this->brokerPolicySha256,
            'authorization set' => $this->authorizationSetSha256,
            'claim request' => $this->claimRequestSha256,
            'consumption receipt' => $this->consumptionReceiptSha256,
        ] as $label => $digest) {
            if (\preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException("The live-effect {$label} SHA-256 is invalid.");
            }
        }

        $nonce = \base64_decode($this->claimNonce, true);

        if ($nonce === false || \strlen($nonce) !== 32 || \base64_encode($nonce) !== $this->claimNonce) {
            throw new InvalidArgumentException('The live-effect claim nonce is not canonical.');
        }

        self::assertUtcMicrosecondInstant($this->runStartedAt);

        if ($this->requestBodySizeBytes < 0
            || $this->requestBodySizeBytes > 1_048_576
            || ($this->requestBodyPolicy === 'required_non_empty' && $this->requestBodySizeBytes === 0)
            || ($this->requestBodyPolicy === 'must_be_empty' && $this->requestBodySizeBytes !== 0)
            || $this->connectTimeoutMs < 1
            || $this->connectTimeoutMs > 5_000
            || $this->requestTimeoutMs < $this->connectTimeoutMs
            || $this->requestTimeoutMs > 30_000
            || $this->maximumResponseBytes < 1
            || $this->maximumResponseBytes > 1_048_576) {
            throw new InvalidArgumentException('The live-effect size or transport bounds are invalid.');
        }
    }

    /**
     * @return array{
     *     evidence_contract: string,
     *     profiles: non-empty-list<string>,
     *     capability: string,
     *     semantic_effect: string,
     *     method: string,
     *     endpoint: string,
     *     request_body_policy: string,
     *     maximum_effect_sequence: int
     * }
     */
    private function matchingOperation(): array
    {
        foreach (self::AllowedOperations as $operation) {
            if ($this->evidenceContract === $operation['evidence_contract']
                && \in_array($this->profile, $operation['profiles'], true)
                && $this->capability === $operation['capability']
                && $this->semanticEffect === $operation['semantic_effect']
                && $this->httpMethod === $operation['method']
                && $this->endpointTemplate === $operation['endpoint']
                && $this->requestBodyPolicy === $operation['request_body_policy']) {
                return $operation;
            }
        }

        throw new InvalidArgumentException('The live-effect operation tuple is not allowlisted.');
    }

    private static function assertUtcMicrosecondInstant(string $value): void
    {
        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($instant === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $instant->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new InvalidArgumentException('The live-effect run start is not a strict UTC microsecond instant.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function assertExactKeys(
        #[SensitiveParameter] array $value,
        #[SensitiveParameter] array $expected,
    ): void {
        $keys = \array_keys($value);
        \sort($keys, \SORT_STRING);
        \sort($expected, \SORT_STRING);

        if ($keys !== $expected) {
            throw new InvalidArgumentException('The live-effect descriptor contains missing or unknown fields.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function string(#[SensitiveParameter] array $value, string $key): string
    {
        $result = $value[$key] ?? null;

        if (! \is_string($result)) {
            throw new InvalidArgumentException("The live-effect field {$key} must be a string.");
        }

        return $result;
    }

    /** @param array<string, mixed> $value */
    private static function integer(#[SensitiveParameter] array $value, string $key): int
    {
        $result = $value[$key] ?? null;

        if (! \is_int($result)) {
            throw new InvalidArgumentException("The live-effect field {$key} must be an integer.");
        }

        return $result;
    }
}
