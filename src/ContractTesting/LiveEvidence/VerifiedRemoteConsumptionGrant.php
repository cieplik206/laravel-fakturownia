<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use JsonSerializable;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use WeakMap;

final class VerifiedRemoteConsumptionGrant implements JsonSerializable
{
    /**
     * @var WeakMap<self, array{
     *     request: ConsumptionClaimRequest,
     *     receipt: ConsumptionReceipt,
     *     batch: LiveProbeAuthorizationBatch,
     *     raw_authorization_set_sha256: string,
     *     repository_root: string,
     *     policy: RemoteConsumptionAuthorityPolicy,
     *     maximum_authorization_ttl_seconds: int,
     *     minimum_authorization_remaining_seconds: int,
     *     maximum_receipt_ttl_seconds: int,
     *     maximum_run_seconds: int,
     *     launch_manifest_sha256: string,
     *     run_started_monotonic_nanoseconds: int,
     *     issued_monotonic_nanoseconds: int,
     *     origin: 'in_memory_test'
     * }>|null
     */
    private static ?WeakMap $contexts = null;

    private function __construct() {}

    /**
     * @param array{
     *     request: ConsumptionClaimRequest,
     *     receipt: ConsumptionReceipt,
     *     batch: LiveProbeAuthorizationBatch,
     *     raw_authorization_set_sha256: string,
     *     repository_root: string,
     *     policy: RemoteConsumptionAuthorityPolicy,
     *     maximum_authorization_ttl_seconds: int,
     *     minimum_authorization_remaining_seconds: int,
     *     maximum_receipt_ttl_seconds: int,
     *     maximum_run_seconds: int,
     *     launch_manifest_sha256: string,
     *     run_started_monotonic_nanoseconds: int
     * } $context
     */
    protected static function issueInMemoryTest(#[SensitiveParameter] array $context): self
    {
        $issuedMonotonicNanoseconds = self::monotonicNanoseconds();

        if ($context['request']->runStartedAt !== $context['batch']->runStartedAt
            || $context['request']->canonical() !== $context['receipt']->envelope->claimRequest->canonical()
            || ! \hash_equals(
                $context['launch_manifest_sha256'],
                $context['request']->harness['launch_manifest_sha256'],
            )
            || $context['run_started_monotonic_nanoseconds'] < 1
            || $issuedMonotonicNanoseconds < $context['run_started_monotonic_nanoseconds']) {
            throw new LogicException('An in-memory remote consumption grant cannot be issued from inconsistent context.');
        }

        $grant = new self;
        self::registry()[$grant] = [
            ...$context,
            'issued_monotonic_nanoseconds' => $issuedMonotonicNanoseconds,
            'origin' => 'in_memory_test',
        ];

        return $grant;
    }

    public function claimRequest(): ConsumptionClaimRequest
    {
        return $this->context()['request'];
    }

    public function receipt(): ConsumptionReceipt
    {
        return $this->context()['receipt'];
    }

    public function authorizationBatch(): LiveProbeAuthorizationBatch
    {
        return $this->context()['batch'];
    }

    public function runStartedAt(): string
    {
        return $this->context()['request']->runStartedAt;
    }

    public function runStartedMonotonicNanoseconds(): int
    {
        return $this->context()['run_started_monotonic_nanoseconds'];
    }

    /** @param array<array-key, mixed> $rawSignedAuthorizations */
    public function assertEffectBoundaryNow(
        #[SensitiveParameter] array $rawSignedAuthorizations,
        int $maximumAuthorizationTtlSeconds,
        int $minimumAuthorizationRemainingSeconds,
        int $maximumReceiptTtlSeconds,
        int $maximumRunSeconds,
    ): never {
        throw new BrokeredExecutionRequiredException(
            'A local consumption grant cannot authorize a provider effect without a brokered execution receipt.',
        );
    }

    public function assertPinnedRemoteOrigin(): never
    {
        throw new BrokeredExecutionRequiredException(
            'No PHP-local grant can prove broker-owned authority and provider execution.',
        );
    }

    /** @return array{grant: string, authority: string, receipt: string, authorizations: string, clocks: string} */
    public function __debugInfo(): array
    {
        return [
            'grant' => '[REDACTED]',
            'authority' => '[REDACTED]',
            'receipt' => '[REDACTED]',
            'authorizations' => '[REDACTED]',
            'clocks' => '[REDACTED]',
        ];
    }

    /** @return array{grant: string, authority: string, receipt: string, authorizations: string, clocks: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Verified remote consumption grants cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Verified remote consumption grants cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Verified remote consumption grants cannot be unserialized.');
    }

    /**
     * @return array{
     *     request: ConsumptionClaimRequest,
     *     receipt: ConsumptionReceipt,
     *     batch: LiveProbeAuthorizationBatch,
     *     raw_authorization_set_sha256: string,
     *     repository_root: string,
     *     policy: RemoteConsumptionAuthorityPolicy,
     *     maximum_authorization_ttl_seconds: int,
     *     minimum_authorization_remaining_seconds: int,
     *     maximum_receipt_ttl_seconds: int,
     *     maximum_run_seconds: int,
     *     launch_manifest_sha256: string,
     *     run_started_monotonic_nanoseconds: int,
     *     issued_monotonic_nanoseconds: int,
     *     origin: 'in_memory_test'
     * }
     */
    private function context(): array
    {
        $context = self::registry()[$this] ?? null;

        if (! \is_array($context)) {
            throw new LogicException('The verified remote consumption grant is unknown or forged.');
        }

        return $context;
    }

    /**
     * @return WeakMap<self, array{
     *     request: ConsumptionClaimRequest,
     *     receipt: ConsumptionReceipt,
     *     batch: LiveProbeAuthorizationBatch,
     *     raw_authorization_set_sha256: string,
     *     repository_root: string,
     *     policy: RemoteConsumptionAuthorityPolicy,
     *     maximum_authorization_ttl_seconds: int,
     *     minimum_authorization_remaining_seconds: int,
     *     maximum_receipt_ttl_seconds: int,
     *     maximum_run_seconds: int,
     *     launch_manifest_sha256: string,
     *     run_started_monotonic_nanoseconds: int,
     *     issued_monotonic_nanoseconds: int,
     *     origin: 'in_memory_test'
     * }>
     */
    private static function registry(): WeakMap
    {
        return self::$contexts ??= new WeakMap;
    }

    private static function monotonicNanoseconds(): int
    {
        $nanoseconds = \hrtime(true);

        if (! \is_int($nanoseconds)) {
            throw new RuntimeException('A monotonic process clock is required for remote consumption grants.');
        }

        return $nanoseconds;
    }
}
