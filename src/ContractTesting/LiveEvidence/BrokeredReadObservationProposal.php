<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class BrokeredReadObservationProposal implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.brokered-read-observation-proposal';

    public const Version = '1';

    private function __construct(
        public string $evidenceContract,
        public string $observationId,
        public string $profile,
        public string $targetKey,
        public string $capability,
        public string $httpMethod,
        public string $endpointTemplate,
        public string $providerPath,
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
            'observation_id',
            'profile',
            'target_key',
            'capability',
            'http_method',
            'endpoint_template',
            'provider_path',
            'connect_timeout_ms',
            'request_timeout_ms',
            'maximum_response_bytes',
        ], 'brokered read observation proposal');

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version) {
            throw new InvalidArgumentException('The brokered read observation must use the exact version 1 contract.');
        }

        $proposal = new self(
            NativeBrokerWireValidation::string($value, 'evidence_contract', 'brokered read observation'),
            NativeBrokerWireValidation::string($value, 'observation_id', 'brokered read observation'),
            NativeBrokerWireValidation::string($value, 'profile', 'brokered read observation'),
            NativeBrokerWireValidation::string($value, 'target_key', 'brokered read observation'),
            NativeBrokerWireValidation::string($value, 'capability', 'brokered read observation'),
            NativeBrokerWireValidation::string($value, 'http_method', 'brokered read observation'),
            NativeBrokerWireValidation::string($value, 'endpoint_template', 'brokered read observation'),
            NativeBrokerWireValidation::string($value, 'provider_path', 'brokered read observation'),
            NativeBrokerWireValidation::integer($value, 'connect_timeout_ms', 'brokered read observation'),
            NativeBrokerWireValidation::integer($value, 'request_timeout_ms', 'brokered read observation'),
            NativeBrokerWireValidation::integer($value, 'maximum_response_bytes', 'brokered read observation'),
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
            'observation_id' => $this->observationId,
            'profile' => $this->profile,
            'target_key' => $this->targetKey,
            'capability' => $this->capability,
            'http_method' => $this->httpMethod,
            'endpoint_template' => $this->endpointTemplate,
            'provider_path' => $this->providerPath,
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

    /** @return array{brokered_read_observation_proposal: string} */
    public function __debugInfo(): array
    {
        return ['brokered_read_observation_proposal' => '[REDACTED]'];
    }

    /** @return array{brokered_read_observation_proposal: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Brokered read observation proposals cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Brokered read observation proposals cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Brokered read observation proposals cannot be unserialized.');
    }

    private function assertValid(): void
    {
        if (\preg_match('/^[a-f0-9]{32}$/D', $this->observationId) !== 1
            || $this->httpMethod !== 'GET'
            || $this->connectTimeoutMs < 1
            || $this->connectTimeoutMs > 5_000
            || $this->requestTimeoutMs < $this->connectTimeoutMs
            || $this->requestTimeoutMs > 30_000
            || $this->maximumResponseBytes < 1
            || $this->maximumResponseBytes > 26_214_400) {
            throw new InvalidArgumentException('The brokered read observation identity or limits are invalid.');
        }

        $this->assertTargetKey();
        $s03 = $this->evidenceContract === SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract;
        $s04 = $this->evidenceContract === SignedLiveProbeAuthorization::KsefDemoEvidenceContract;
        $valid = ($s03 || $s04)
            && $this->capability === 'account.read'
            && $this->endpointTemplate === '/account.json'
            && $this->providerPath === '/account.json';
        $valid = $valid || ($s03
            && $this->capability === 'invoice.search'
            && $this->endpointTemplate === '/invoices.json'
            && \preg_match('/^\/invoices\.json\?include_positions=true&oid=[A-Za-z0-9._-]{4,191}&page=(?:[1-9]|[1-9][0-9]|100)&per_page=100&period=all$/D', $this->providerPath) === 1);
        $valid = $valid || ($s04
            && $this->capability === 'invoice.search'
            && $this->endpointTemplate === '/invoices.json'
            && \preg_match('/^\/invoices\.json\?oid=[A-Za-z0-9._-]{4,191}&page=(?:[1-9]|[1-9][0-9]|100)&per_page=100&period=all$/D', $this->providerPath) === 1);
        $valid = $valid || ($s04
            && $this->capability === 'invoice.read'
            && $this->endpointTemplate === '/invoices/{invoice_id}.json'
            && \preg_match('/^\/invoices\/[1-9][0-9]{0,18}\.json\?fields%5Binvoice%5D=id%2Cgov_status%2Cgov_id%2Cgov_error_messages$/D', $this->providerPath) === 1);
        $valid = $valid || ($s04
            && $this->capability === 'invoice.pdf.download'
            && $this->endpointTemplate === '/invoices/{invoice_id}.pdf'
            && \preg_match('/^\/invoices\/[1-9][0-9]{0,18}\.pdf$/D', $this->providerPath) === 1);

        if (! $valid) {
            throw new InvalidArgumentException('The brokered read observation tuple or provider path is not allowlisted.');
        }
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
            throw new InvalidArgumentException('The brokered read observation target is not allowlisted.');
        }
    }
}
