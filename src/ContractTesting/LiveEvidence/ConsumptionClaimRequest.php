<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class ConsumptionClaimRequest
{
    public const Contract = 'cieplik206.fakturownia.authorization-consumption-claim-request';

    public const Version = '1';

    public const ReplayPolicy = 'consume_after_read_preflight_before_mutating_http_no_retry';

    /** @param array{repository_commit: string, code_sha256: string, launch_manifest_sha256: string} $harness */
    public function __construct(
        #[SensitiveParameter] public string $authorityId,
        #[SensitiveParameter] public string $authorityPolicySha256,
        #[SensitiveParameter] public string $storeId,
        #[SensitiveParameter] public string $storeIdentitySha256,
        #[SensitiveParameter] public string $runId,
        #[SensitiveParameter] public string $runStartedAt,
        #[SensitiveParameter] public string $claimNonce,
        #[SensitiveParameter] public array $harness,
        #[SensitiveParameter] public string $authorizationSetSha256,
        #[SensitiveParameter] public string $challengeSetSha256,
        #[SensitiveParameter] public string $configurationSetSha256,
    ) {
        self::assertIdentifier($authorityId, 'authority ID');
        self::assertIdentifier($storeId, 'store ID');
        self::assertSha256($authorityPolicySha256, 'authority policy');
        self::assertSha256($storeIdentitySha256, 'store identity');
        self::assertSha256($authorizationSetSha256, 'authorization set');
        self::assertSha256($challengeSetSha256, 'challenge set');
        self::assertSha256($configurationSetSha256, 'configuration set');

        if (\preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1) {
            throw new InvalidArgumentException('The consumption claim run ID is invalid.');
        }

        self::assertUtcMicrosecondInstant($runStartedAt, 'run start');
        self::assertCanonicalNonce($claimNonce);
        self::assertHarness($harness);
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        self::assertExactKeys($value, [
            'contract',
            'version',
            'authority_id',
            'authority_policy_sha256',
            'store_id',
            'store_identity_sha256',
            'run_id',
            'run_started_at',
            'claim_nonce',
            'harness',
            'authorization_set_sha256',
            'challenge_set_sha256',
            'configuration_set_sha256',
            'replay_policy',
        ]);

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version
            || ($value['replay_policy'] ?? null) !== self::ReplayPolicy
            || ! \is_array($value['harness'] ?? null)) {
            throw new InvalidArgumentException('The consumption claim request must use the exact version 1 contract.');
        }

        return new self(
            self::string($value, 'authority_id'),
            self::string($value, 'authority_policy_sha256'),
            self::string($value, 'store_id'),
            self::string($value, 'store_identity_sha256'),
            self::string($value, 'run_id'),
            self::string($value, 'run_started_at'),
            self::string($value, 'claim_nonce'),
            self::harness($value['harness']),
            self::string($value, 'authorization_set_sha256'),
            self::string($value, 'challenge_set_sha256'),
            self::string($value, 'configuration_set_sha256'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'authority_id' => $this->authorityId,
            'authority_policy_sha256' => $this->authorityPolicySha256,
            'store_id' => $this->storeId,
            'store_identity_sha256' => $this->storeIdentitySha256,
            'run_id' => $this->runId,
            'run_started_at' => $this->runStartedAt,
            'claim_nonce' => $this->claimNonce,
            'harness' => $this->harness,
            'authorization_set_sha256' => $this->authorizationSetSha256,
            'challenge_set_sha256' => $this->challengeSetSha256,
            'configuration_set_sha256' => $this->configurationSetSha256,
            'replay_policy' => self::ReplayPolicy,
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

    private static function assertIdentifier(#[SensitiveParameter] string $value, string $label): void
    {
        if (\preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The consumption claim {$label} is invalid.");
        }
    }

    private static function assertSha256(#[SensitiveParameter] string $value, string $label): void
    {
        if (\preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The consumption claim {$label} SHA-256 is invalid.");
        }
    }

    private static function assertUtcMicrosecondInstant(
        #[SensitiveParameter] string $value,
        string $label,
    ): void {
        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($instant === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $instant->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new InvalidArgumentException("The consumption claim {$label} is not a strict UTC microsecond instant.");
        }
    }

    private static function assertCanonicalNonce(#[SensitiveParameter] string $value): void
    {
        $decoded = \base64_decode($value, true);

        if ($decoded === false || \strlen($decoded) !== 32 || \base64_encode($decoded) !== $value) {
            throw new InvalidArgumentException('The consumption claim nonce must be canonical base64 for 32 random bytes.');
        }
    }

    /** @param array<string, mixed> $harness */
    private static function assertHarness(#[SensitiveParameter] array $harness): void
    {
        self::assertExactKeys($harness, ['repository_commit', 'code_sha256', 'launch_manifest_sha256']);

        if (! \is_string($harness['repository_commit'] ?? null)
            || \preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $harness['repository_commit']) !== 1
            || ! \is_string($harness['code_sha256'] ?? null)
            || ! \is_string($harness['launch_manifest_sha256'] ?? null)) {
            throw new InvalidArgumentException('The consumption claim harness provenance is invalid.');
        }

        self::assertSha256($harness['code_sha256'], 'harness code');
        self::assertSha256($harness['launch_manifest_sha256'], 'launch manifest');
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
        \sort($keys);
        \sort($expectedKeys);

        if ($keys !== $expectedKeys) {
            throw new InvalidArgumentException('The consumption claim request contains missing or unknown fields.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function string(#[SensitiveParameter] array $value, string $key): string
    {
        $string = $value[$key] ?? null;

        if (! \is_string($string)) {
            throw new InvalidArgumentException("The consumption claim field {$key} must be a string.");
        }

        return $string;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{repository_commit: string, code_sha256: string, launch_manifest_sha256: string}
     */
    private static function harness(#[SensitiveParameter] array $value): array
    {
        self::assertHarness($value);

        return [
            'repository_commit' => $value['repository_commit'],
            'code_sha256' => $value['code_sha256'],
            'launch_manifest_sha256' => $value['launch_manifest_sha256'],
        ];
    }
}
