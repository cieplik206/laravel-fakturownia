<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class NativeBrokerProbePlan implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.native-probe-plan';

    public const Version = '1';

    /** @param array<string, mixed> $value */
    private function __construct(private array $value) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        NativeBrokerWireValidation::assertExactKeys($value, [
            'contract',
            'version',
            'evidence_contract',
            'environment',
            'limits',
            'targets',
            ...(($value['evidence_contract'] ?? null) === SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract
                ? ['payload']
                : []),
        ], 'native broker probe plan');

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version
            || ! \is_array($value['limits'] ?? null)
            || \array_is_list($value['limits'])
            || ! \is_array($value['targets'] ?? null)
            || ! \array_is_list($value['targets'])) {
            throw new InvalidArgumentException('The native broker probe plan must use the exact version 1 contract.');
        }

        $evidenceContract = NativeBrokerWireValidation::string($value, 'evidence_contract', 'native broker probe plan');
        $environment = NativeBrokerWireValidation::string($value, 'environment', 'native broker probe plan');

        match ($evidenceContract) {
            SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract => self::assertInvoiceIdentityPlan($value, $environment),
            SignedLiveProbeAuthorization::KsefDemoEvidenceContract => self::assertKsefDemoPlan($value, $environment),
            default => throw new InvalidArgumentException('The native broker probe plan evidence contract is not allowlisted.'),
        };

        CanonicalCodec::encode($value);

        return new self($value);
    }

    public function evidenceContract(): string
    {
        return NativeBrokerWireValidation::string($this->value, 'evidence_contract', 'native broker probe plan');
    }

    public function environment(): string
    {
        return NativeBrokerWireValidation::string($this->value, 'environment', 'native broker probe plan');
    }

    /** @return array<string, int> */
    public function limits(): array
    {
        /** @var array<string, int> $limits */
        $limits = $this->value['limits'];

        return $limits;
    }

    /** @return list<array<string, mixed>> */
    public function targets(): array
    {
        /** @var list<array<string, mixed>> $targets */
        $targets = $this->value['targets'];

        return $targets;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $payload = $this->value['payload'] ?? null;

        if (! \is_array($payload) || \array_is_list($payload)) {
            throw new LogicException('Only an S0.3 native probe plan contains one shared payload.');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->value;
    }

    public function canonical(): string
    {
        return CanonicalCodec::encode($this->value);
    }

    public function sha256(): string
    {
        return \hash('sha256', $this->canonical());
    }

    /** @return array{native_broker_probe_plan: string} */
    public function __debugInfo(): array
    {
        return ['native_broker_probe_plan' => '[VERIFIED]'];
    }

    /** @return array{native_broker_probe_plan: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Native broker probe plans cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Native broker probe plans cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Native broker probe plans cannot be unserialized.');
    }

    /** @param array<string, mixed> $value */
    private static function assertInvoiceIdentityPlan(#[SensitiveParameter] array $value, string $environment): void
    {
        if (! \in_array($environment, ['demo_pl', 'demo_regional'], true)
            || ! \is_array($value['payload'] ?? null)
            || \array_is_list($value['payload'])) {
            throw new InvalidArgumentException('The native S0.3 probe plan environment or payload is invalid.');
        }

        self::assertExactIntegerLimits($value['limits'], [
            'visibility_window_ms',
            'poll_interval_ms',
            'max_search_pages',
            'lost_response_timeout_ms',
            'connect_timeout_ms',
            'request_timeout_ms',
            'write_attempt_budget',
        ]);
        self::assertTargetKeys($value['targets'], ['primary', 'secondary'], false);
        NativeBrokerWireValidation::assertExactKeys($value['payload'], [
            'invoice',
            'secondary_account_invoice',
            'correction_invoice',
            'secondary_department_id',
            'safety',
        ], 'native S0.3 probe payload');

        foreach (['invoice', 'secondary_account_invoice', 'correction_invoice', 'safety'] as $key) {
            if (! \is_array($value['payload'][$key] ?? null)
                || \array_is_list($value['payload'][$key])) {
                throw new InvalidArgumentException('The native S0.3 probe payload contains an invalid object.');
            }
        }

        $safety = $value['payload']['safety'];

        if (($safety['throwaway_tenants'] ?? null) !== true
            || ($safety['ksef_auto_send_disabled'] ?? null) !== true
            || ($safety['email_delivery_disabled'] ?? null) !== true
            || ($value['limits']['write_attempt_budget'] ?? null) !== 11) {
            throw new InvalidArgumentException('The native S0.3 probe safety contract is invalid.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function assertKsefDemoPlan(#[SensitiveParameter] array $value, string $environment): void
    {
        if ($environment !== 'ksef_demo') {
            throw new InvalidArgumentException('The native S0.4 probe plan must target KSeF DEMO.');
        }

        self::assertExactIntegerLimits($value['limits'], [
            'poll_window_ms',
            'poll_interval_ms',
            'max_search_pages',
            'pre_send_observation_window_ms',
            'visibility_window_ms',
            'visibility_poll_interval_ms',
            'connect_timeout_ms',
            'request_timeout_ms',
            'minimum_pdf_size_bytes',
        ]);
        self::assertTargetKeys($value['targets'], [
            'explicit_block',
            'explicit_persist',
            'auto_block',
            'auto_persist',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $limits
     * @param  list<string>  $keys
     */
    private static function assertExactIntegerLimits(#[SensitiveParameter] array $limits, array $keys): void
    {
        NativeBrokerWireValidation::assertExactKeys($limits, $keys, 'native broker probe limits');

        foreach ($limits as $limit) {
            if (! \is_int($limit) || $limit < 1) {
                throw new InvalidArgumentException('Every native broker probe limit must be a positive integer.');
            }
        }
    }

    /**
     * @param  list<mixed>  $targets
     * @param  list<string>  $expectedKeys
     */
    private static function assertTargetKeys(#[SensitiveParameter] array $targets, array $expectedKeys, bool $ksef): void
    {
        if (\count($targets) !== \count($expectedKeys)) {
            throw new InvalidArgumentException('The native broker probe target set is incomplete.');
        }

        foreach ($targets as $index => $target) {
            if (! \is_array($target) || \array_is_list($target)) {
                throw new InvalidArgumentException('A native broker probe target must be an object.');
            }

            $keys = $ksef ? [
                'profile',
                'target_key',
                'expected_account_fingerprint',
                'ownership',
                'validation_mode',
                'expected_validation_field',
                'ksef_environment',
                'gov_auto_send_mode',
                'validate_invoices_for_gov',
                'buyer_company',
                'throwaway_tenant',
                'email_delivery_disabled',
                'payments_disabled',
                'webhooks_disabled',
                'settings_checksum',
                'valid_invoice',
                'invalid_invoice',
            ] : ['target_key', 'expected_account_fingerprint'];
            NativeBrokerWireValidation::assertExactKeys($target, $keys, 'native broker probe target');
            $targetKey = NativeBrokerWireValidation::string($target, 'target_key', 'native broker probe target');
            $fingerprint = NativeBrokerWireValidation::string($target, 'expected_account_fingerprint', 'native broker probe target');

            if ($targetKey !== $expectedKeys[$index]
                || ($ksef && ($target['profile'] ?? null) !== $targetKey)) {
                throw new InvalidArgumentException('The native broker probe target order or profile is invalid.');
            }

            NativeBrokerWireValidation::assertSha256($fingerprint, 'native broker account fingerprint');

            if (! $ksef) {
                continue;
            }

            NativeBrokerWireValidation::assertSha256(
                NativeBrokerWireValidation::string($target, 'settings_checksum', 'native S0.4 target'),
                'native S0.4 settings checksum',
            );

            foreach (['valid_invoice', 'invalid_invoice'] as $template) {
                if (! \is_array($target[$template] ?? null)
                    || \array_is_list($target[$template])) {
                    throw new InvalidArgumentException('A native S0.4 invoice template must be an object.');
                }
            }

            foreach ([
                'validate_invoices_for_gov',
                'buyer_company',
                'throwaway_tenant',
                'email_delivery_disabled',
                'payments_disabled',
                'webhooks_disabled',
            ] as $boolean) {
                if (! \is_bool($target[$boolean] ?? null)) {
                    throw new InvalidArgumentException('A native S0.4 target safety setting must be boolean.');
                }
            }

            if (($target['gov_auto_send_mode'] ?? null) !== null
                && ! \is_string($target['gov_auto_send_mode'])) {
                throw new InvalidArgumentException('The native S0.4 auto-send mode must be null or string.');
            }
        }
    }
}
