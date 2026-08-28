<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class ConcurrentBrokeredEffectExecutionProposal implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.concurrent-effect-execution-proposal';

    public const Version = '1';

    /** @param array{BrokeredEffectExecutionProposal, BrokeredEffectExecutionProposal} $proposals */
    private function __construct(public array $proposals) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        NativeBrokerWireValidation::assertExactKeys(
            $value,
            ['contract', 'version', 'proposals'],
            'concurrent brokered effect proposal',
        );
        $documents = $value['proposals'] ?? null;

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version
            || ! \is_array($documents)
            || ! \array_is_list($documents)
            || \count($documents) !== 2
            || ! \is_array($documents[0] ?? null)
            || \array_is_list($documents[0])
            || ! \is_array($documents[1] ?? null)
            || \array_is_list($documents[1])) {
            throw new InvalidArgumentException('A concurrent brokered effect requires exactly two proposal objects.');
        }

        $batch = new self([
            BrokeredEffectExecutionProposal::fromArray($documents[0]),
            BrokeredEffectExecutionProposal::fromArray($documents[1]),
        ]);
        $batch->assertSameOidPair();

        return $batch;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'proposals' => \array_map(
                static fn (BrokeredEffectExecutionProposal $proposal): array => $proposal->toArray(),
                $this->proposals,
            ),
        ];
    }

    /** @return array{concurrent_brokered_effect_execution_proposal: string} */
    public function __debugInfo(): array
    {
        return ['concurrent_brokered_effect_execution_proposal' => '[REDACTED]'];
    }

    /** @return array{concurrent_brokered_effect_execution_proposal: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Concurrent brokered effect proposals cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Concurrent brokered effect proposals cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Concurrent brokered effect proposals cannot be unserialized.');
    }

    private function assertSameOidPair(): void
    {
        [$first, $second] = $this->proposals;

        if ($first->evidenceContract !== SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract
            || $first->profile !== 'invoice_identity'
            || $first->targetKey !== 'primary'
            || $first->capability !== 'invoice.vat.issue'
            || $first->effectId === $second->effectId
            || $first->effectSequence === $second->effectSequence) {
            throw new InvalidArgumentException('The concurrent brokered effect pair has an invalid identity.');
        }

        foreach ([
            [$first->evidenceContract, $second->evidenceContract],
            [$first->profile, $second->profile],
            [$first->targetKey, $second->targetKey],
            [$first->capability, $second->capability],
            [$first->semanticEffect, $second->semanticEffect],
            [$first->httpMethod, $second->httpMethod],
            [$first->endpointTemplate, $second->endpointTemplate],
            [$first->providerPath, $second->providerPath],
            [$first->requestBodyBase64, $second->requestBodyBase64],
            [(string) $first->connectTimeoutMs, (string) $second->connectTimeoutMs],
            [(string) $first->requestTimeoutMs, (string) $second->requestTimeoutMs],
            [(string) $first->maximumResponseBytes, (string) $second->maximumResponseBytes],
        ] as [$expected, $actual]) {
            if (! \hash_equals($expected, $actual)) {
                throw new InvalidArgumentException('The concurrent brokered effects do not bind one exact same-OID request.');
            }
        }
    }
}
