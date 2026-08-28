<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class BrokeredEffectExecutionProposal implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.brokered-effect-execution-proposal';

    public const Version = '1';

    /**
     * @var list<array{
     *     evidence_contract: string,
     *     profiles: non-empty-list<string>,
     *     capability: string,
     *     semantic_effect: string,
     *     method: string,
     *     endpoint: string,
     *     body_policy: string,
     *     maximum_sequence: int
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
            'body_policy' => 'required_non_empty',
            'maximum_sequence' => 11,
        ],
        [
            'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
            'profiles' => ['explicit_block', 'explicit_persist', 'auto_block', 'auto_persist'],
            'capability' => 'contract_probe.invoice.fixture.issue',
            'semantic_effect' => 'probe_fixture_invoice_create',
            'method' => 'POST',
            'endpoint' => '/invoices.json',
            'body_policy' => 'required_non_empty',
            'maximum_sequence' => 8,
        ],
        [
            'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
            'profiles' => ['explicit_block', 'explicit_persist'],
            'capability' => 'invoice.ksef.ensure_accepted',
            'semantic_effect' => 'ksef_explicit_submit',
            'method' => 'GET',
            'endpoint' => '/invoices/{invoice_id}.json?send_to_ksef=yes',
            'body_policy' => 'must_be_empty',
            'maximum_sequence' => 8,
        ],
    ];

    private function __construct(
        public string $evidenceContract,
        public string $effectId,
        public int $effectSequence,
        public string $profile,
        public string $targetKey,
        public string $capability,
        public string $semanticEffect,
        public string $httpMethod,
        public string $endpointTemplate,
        public string $providerPath,
        public string $requestBodyBase64,
        public int $connectTimeoutMs,
        public int $requestTimeoutMs,
        public int $maximumResponseBytes,
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        NativeBrokerWireValidation::assertExactKeys($value, [
            'contract',
            'version',
            'evidence_contract',
            'effect_id',
            'effect_sequence',
            'profile',
            'target_key',
            'capability',
            'semantic_effect',
            'http_method',
            'endpoint_template',
            'provider_path',
            'request_body_base64',
            'connect_timeout_ms',
            'request_timeout_ms',
            'maximum_response_bytes',
        ], 'brokered effect execution proposal');

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version) {
            throw new InvalidArgumentException('The brokered effect proposal must use the exact version 1 contract.');
        }

        $proposal = new self(
            NativeBrokerWireValidation::string($value, 'evidence_contract', 'brokered effect proposal'),
            NativeBrokerWireValidation::string($value, 'effect_id', 'brokered effect proposal'),
            NativeBrokerWireValidation::integer($value, 'effect_sequence', 'brokered effect proposal'),
            NativeBrokerWireValidation::string($value, 'profile', 'brokered effect proposal'),
            NativeBrokerWireValidation::string($value, 'target_key', 'brokered effect proposal'),
            NativeBrokerWireValidation::string($value, 'capability', 'brokered effect proposal'),
            NativeBrokerWireValidation::string($value, 'semantic_effect', 'brokered effect proposal'),
            NativeBrokerWireValidation::string($value, 'http_method', 'brokered effect proposal'),
            NativeBrokerWireValidation::string($value, 'endpoint_template', 'brokered effect proposal'),
            NativeBrokerWireValidation::string($value, 'provider_path', 'brokered effect proposal'),
            NativeBrokerWireValidation::string($value, 'request_body_base64', 'brokered effect proposal'),
            NativeBrokerWireValidation::integer($value, 'connect_timeout_ms', 'brokered effect proposal'),
            NativeBrokerWireValidation::integer($value, 'request_timeout_ms', 'brokered effect proposal'),
            NativeBrokerWireValidation::integer($value, 'maximum_response_bytes', 'brokered effect proposal'),
        );
        $proposal->assertValid();

        return $proposal;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'evidence_contract' => $this->evidenceContract,
            'effect_id' => $this->effectId,
            'effect_sequence' => $this->effectSequence,
            'profile' => $this->profile,
            'target_key' => $this->targetKey,
            'capability' => $this->capability,
            'semantic_effect' => $this->semanticEffect,
            'http_method' => $this->httpMethod,
            'endpoint_template' => $this->endpointTemplate,
            'provider_path' => $this->providerPath,
            'request_body_base64' => $this->requestBodyBase64,
            'connect_timeout_ms' => $this->connectTimeoutMs,
            'request_timeout_ms' => $this->requestTimeoutMs,
            'maximum_response_bytes' => $this->maximumResponseBytes,
        ];
    }

    public function canonical(): string
    {
        return CanonicalCodec::encode($this->toArray());
    }

    public function requestBody(): string
    {
        return NativeBrokerWireValidation::decodeCanonicalBase64(
            $this->requestBodyBase64,
            1_048_576,
            'brokered effect request body',
        );
    }

    /** @return array{brokered_effect_execution_proposal: string} */
    public function __debugInfo(): array
    {
        return ['brokered_effect_execution_proposal' => '[REDACTED]'];
    }

    /** @return array{brokered_effect_execution_proposal: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Brokered effect execution proposals cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Brokered effect execution proposals cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Brokered effect execution proposals cannot be unserialized.');
    }

    private function assertValid(): void
    {
        if (\preg_match('/^[a-f0-9]{32}$/D', $this->effectId) !== 1) {
            throw new InvalidArgumentException('The brokered effect proposal identity is invalid.');
        }

        $operation = $this->matchingOperation();
        $this->assertTargetKey();

        if ($this->effectSequence < 1
            || $this->effectSequence > $operation['maximum_sequence']
            || $this->connectTimeoutMs < 1
            || $this->connectTimeoutMs > 5_000
            || $this->requestTimeoutMs < $this->connectTimeoutMs
            || $this->requestTimeoutMs > 30_000
            || $this->maximumResponseBytes < 1
            || $this->maximumResponseBytes > 1_048_576) {
            throw new InvalidArgumentException('The brokered effect proposal limits are invalid.');
        }

        if ($operation['endpoint'] === '/invoices.json') {
            if ($this->providerPath !== '/invoices.json') {
                throw new InvalidArgumentException('The brokered effect provider path is not allowlisted.');
            }
        } elseif (\preg_match('/^\/invoices\/[1-9][0-9]{0,18}\.json\?send_to_ksef=yes$/D', $this->providerPath) !== 1) {
            throw new InvalidArgumentException('The brokered KSeF provider path is not canonical.');
        }

        $body = $this->requestBody();

        if ($operation['body_policy'] === 'must_be_empty') {
            if ($body !== '') {
                throw new InvalidArgumentException('The brokered effect request body must be empty.');
            }

            return;
        }

        if ($body === '') {
            throw new InvalidArgumentException('The brokered effect request body must not be empty.');
        }

        try {
            $decoded = \json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The brokered effect request body must be canonical JSON.', 0, $exception);
        }

        if (! \is_array($decoded)
            || \array_is_list($decoded)
            || ! \hash_equals(CanonicalCodec::encode($decoded), $body)) {
            throw new InvalidArgumentException('The brokered effect request body must be one canonical JSON object.');
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
     *     body_policy: string,
     *     maximum_sequence: int
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
                && $this->endpointTemplate === $operation['endpoint']) {
                return $operation;
            }
        }

        throw new InvalidArgumentException('The brokered effect proposal tuple is not allowlisted.');
    }

    private function assertTargetKey(): void
    {
        $valid = $this->evidenceContract === SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract
            && $this->profile === 'invoice_identity'
            && \in_array($this->targetKey, ['primary', 'secondary'], true);
        $valid = $valid || ($this->evidenceContract === SignedLiveProbeAuthorization::KsefDemoEvidenceContract
            && \in_array($this->profile, ['explicit_block', 'explicit_persist', 'auto_block', 'auto_persist'], true)
            && \hash_equals($this->profile, $this->targetKey));

        if (! $valid) {
            throw new InvalidArgumentException('The brokered effect target is not allowlisted for its profile.');
        }
    }
}
