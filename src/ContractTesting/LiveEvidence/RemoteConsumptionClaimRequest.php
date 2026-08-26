<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final class RemoteConsumptionClaimRequest implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.remote-consumption-claim-command';

    public const Version = '1';

    /** @var non-empty-list<array{envelope: array<string, mixed>, signature: string}> */
    private readonly array $signedAuthorizations;

    /** @param list<array<array-key, mixed>> $signedAuthorizations */
    public function __construct(
        #[SensitiveParameter] array $signedAuthorizations,
        #[SensitiveParameter] private readonly ConsumptionClaimRequest $claimRequest,
    ) {
        if ($signedAuthorizations === [] || \count($signedAuthorizations) > 16) {
            throw new InvalidArgumentException('A remote consumption claim requires between one and sixteen signed authorizations.');
        }

        $normalized = [];

        foreach ($signedAuthorizations as $authorization) {
            if (\array_is_list($authorization)) {
                throw new InvalidArgumentException('A remote consumption claim contains an invalid signed authorization.');
            }

            $normalized[] = SignedLiveProbeAuthorization::fromArray($authorization)->toArray();
        }

        $this->signedAuthorizations = $normalized;
        CanonicalCodec::encode($this->payload());
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'signed_authorizations' => $this->signedAuthorizations,
            'claim_request' => $this->claimRequest->toArray(),
        ];
    }

    public function canonical(): string
    {
        return CanonicalCodec::encode($this->payload());
    }

    public function sha256(): string
    {
        return \hash('sha256', $this->canonical());
    }

    /** @return array{authorizations: string, claim_request: string} */
    public function __debugInfo(): array
    {
        return [
            'authorizations' => '[REDACTED]',
            'claim_request' => '[REDACTED]',
        ];
    }

    /** @return array{authorizations: string, claim_request: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Remote consumption claim requests cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Remote consumption claim requests cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Remote consumption claim requests cannot be unserialized.');
    }
}
