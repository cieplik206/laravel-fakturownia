<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use Closure;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use WeakMap;

/** @internal A one-shot literal-response seam that can issue only an in-memory-test origin. */
final class PendingLiteralRemoteConsumptionClaim implements JsonSerializable
{
    /**
     * @var WeakMap<self, array{
     *     prepared: array{
     *         authorizations: non-empty-list<SignedLiveProbeAuthorization>,
     *         normalized: non-empty-list<array<string, mixed>>,
     *         request: ConsumptionClaimRequest,
     *         batch: LiveProbeAuthorizationBatch,
     *         policy: RemoteConsumptionAuthorityPolicy,
     *         repository_root: string,
     *         launch_manifest_sha256: string,
     *         raw_authorization_set_sha256: string
     *     },
     *     maximum_authorization_ttl_seconds: int,
     *     minimum_authorization_remaining_seconds: int,
     *     maximum_receipt_ttl_seconds: int,
     *     maximum_run_seconds: int,
     *     run_started_monotonic_nanoseconds: int,
     *     consumed: bool
     * }>|null
     */
    private static ?WeakMap $contexts = null;

    private function __construct() {}

    /**
     * @param array{
     *     prepared: array{
     *         authorizations: non-empty-list<SignedLiveProbeAuthorization>,
     *         normalized: non-empty-list<array<string, mixed>>,
     *         request: ConsumptionClaimRequest,
     *         batch: LiveProbeAuthorizationBatch,
     *         policy: RemoteConsumptionAuthorityPolicy,
     *         repository_root: string,
     *         launch_manifest_sha256: string,
     *         raw_authorization_set_sha256: string
     *     },
     *     maximum_authorization_ttl_seconds: int,
     *     minimum_authorization_remaining_seconds: int,
     *     maximum_receipt_ttl_seconds: int,
     *     maximum_run_seconds: int,
     *     run_started_monotonic_nanoseconds: int,
     *     consumed: bool
     * } $context
     */
    protected static function begin(#[SensitiveParameter] array $context): self
    {
        $claim = new self;
        self::registry()[$claim] = $context;

        return $claim;
    }

    public function claimRequest(): ConsumptionClaimRequest
    {
        return $this->context()['prepared']['request'];
    }

    /** @param array<array-key, mixed> $literalSignedReceipt */
    public function completeLiteralNow(
        int $status,
        #[SensitiveParameter] array $literalSignedReceipt,
    ): VerifiedRemoteConsumptionGrant {
        $context = $this->context();

        if ($context['consumed']) {
            throw new LogicException('The literal remote consumption claim is already completed.');
        }

        $context['consumed'] = true;
        self::registry()[$this] = $context;

        if (! \in_array($status, [200, 201], true)) {
            throw new InvalidArgumentException('The literal remote consumption response is invalid.');
        }

        $receipt = self::receipt($literalSignedReceipt);
        RemoteConsumptionReceiptVerifier::verifyLiteralResultNow(
            $status,
            $receipt,
            $context['prepared']['request'],
            $context['prepared']['policy'],
            $context['maximum_receipt_ttl_seconds'],
        );
        $verifiedContext = [
            'request' => $context['prepared']['request'],
            'receipt' => $receipt,
            'batch' => $context['prepared']['batch'],
            'raw_authorization_set_sha256' => $context['prepared']['raw_authorization_set_sha256'],
            'repository_root' => $context['prepared']['repository_root'],
            'policy' => $context['prepared']['policy'],
            'maximum_authorization_ttl_seconds' => $context['maximum_authorization_ttl_seconds'],
            'minimum_authorization_remaining_seconds' => $context['minimum_authorization_remaining_seconds'],
            'maximum_receipt_ttl_seconds' => $context['maximum_receipt_ttl_seconds'],
            'maximum_run_seconds' => $context['maximum_run_seconds'],
            'launch_manifest_sha256' => $context['prepared']['launch_manifest_sha256'],
            'run_started_monotonic_nanoseconds' => $context['run_started_monotonic_nanoseconds'],
        ];
        $issue = Closure::bind(
            static fn (): VerifiedRemoteConsumptionGrant => VerifiedRemoteConsumptionGrant::issueInMemoryTest($verifiedContext),
            null,
            VerifiedRemoteConsumptionGrant::class,
        );

        return $issue();
    }

    /** @return array{claim: string, request: string, response: string, transport: string} */
    public function __debugInfo(): array
    {
        return [
            'claim' => '[REDACTED]',
            'request' => '[REDACTED]',
            'response' => '[REDACTED]',
            'transport' => 'literal_in_memory_test',
        ];
    }

    /** @return array{claim: string, request: string, response: string, transport: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Pending literal remote consumption claims cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Pending literal remote consumption claims cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Pending literal remote consumption claims cannot be unserialized.');
    }

    /**
     * @return array{
     *     prepared: array{
     *         authorizations: non-empty-list<SignedLiveProbeAuthorization>,
     *         normalized: non-empty-list<array<string, mixed>>,
     *         request: ConsumptionClaimRequest,
     *         batch: LiveProbeAuthorizationBatch,
     *         policy: RemoteConsumptionAuthorityPolicy,
     *         repository_root: string,
     *         launch_manifest_sha256: string,
     *         raw_authorization_set_sha256: string
     *     },
     *     maximum_authorization_ttl_seconds: int,
     *     minimum_authorization_remaining_seconds: int,
     *     maximum_receipt_ttl_seconds: int,
     *     maximum_run_seconds: int,
     *     run_started_monotonic_nanoseconds: int,
     *     consumed: bool
     * }
     */
    private function context(): array
    {
        $context = self::registry()[$this] ?? null;

        if (! \is_array($context)) {
            throw new LogicException('The pending literal remote consumption claim is unknown or forged.');
        }

        return $context;
    }

    /**
     * @return WeakMap<self, array{
     *     prepared: array{
     *         authorizations: non-empty-list<SignedLiveProbeAuthorization>,
     *         normalized: non-empty-list<array<string, mixed>>,
     *         request: ConsumptionClaimRequest,
     *         batch: LiveProbeAuthorizationBatch,
     *         policy: RemoteConsumptionAuthorityPolicy,
     *         repository_root: string,
     *         launch_manifest_sha256: string,
     *         raw_authorization_set_sha256: string
     *     },
     *     maximum_authorization_ttl_seconds: int,
     *     minimum_authorization_remaining_seconds: int,
     *     maximum_receipt_ttl_seconds: int,
     *     maximum_run_seconds: int,
     *     run_started_monotonic_nanoseconds: int,
     *     consumed: bool
     * }>
     */
    private static function registry(): WeakMap
    {
        return self::$contexts ??= new WeakMap;
    }

    /** @param array<array-key, mixed> $value */
    private static function receipt(#[SensitiveParameter] array $value): ConsumptionReceipt
    {
        $keys = \array_keys($value);
        \sort($keys);

        if ($keys !== ['envelope', 'signature']
            || ! \is_array($value['envelope'] ?? null)
            || ! \is_string($value['signature'] ?? null)) {
            throw new InvalidArgumentException('The literal remote consumption response is invalid.');
        }

        return ConsumptionReceipt::fromArray([
            'envelope' => $value['envelope'],
            'signature' => $value['signature'],
        ]);
    }
}
