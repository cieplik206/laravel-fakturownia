<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Sync;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class IncrementalSyncPlanner
{
    public const int MaximumPageSize = 100;

    public const int MaximumOverlapSeconds = 604_800;

    public function queryStartAt(IncrementalSyncCheckpoint $checkpoint, int $overlapSeconds): ?DateTimeImmutable
    {
        if ($overlapSeconds < 0 || $overlapSeconds > self::MaximumOverlapSeconds) {
            throw new InvalidArgumentException('The incremental sync overlap must be between zero and seven days.');
        }

        return $checkpoint->cursor?->updatedAt->modify("-{$overlapSeconds} seconds");
    }

    /**
     * @param  list<IncrementalSyncObservation>  $observations
     */
    public function preparePage(
        IncrementalSyncCheckpoint $checkpoint,
        array $observations,
    ): IncrementalSyncPage {
        $inputCount = count($observations);

        if ($inputCount > self::MaximumPageSize) {
            throw new InvalidArgumentException('An incremental sync page may contain at most 100 observations.');
        }

        $byRemoteIdentity = [];
        $keyVersion = null;

        foreach ($observations as $observation) {
            if (! $observation->attestation->scope->equals($checkpoint->scope)) {
                throw new InvalidArgumentException('An incremental sync page cannot mix scopes.');
            }

            $observationKeyVersion = $observation->attestation->keyVersion();
            $keyVersion ??= $observationKeyVersion;

            if ($keyVersion !== $observationKeyVersion) {
                throw new InvalidArgumentException('An incremental sync page cannot mix HMAC key versions.');
            }

            $identity = $observation->attestation->remoteIdentity->hex;
            $existing = $byRemoteIdentity[$identity] ?? null;

            if (! $existing instanceof IncrementalSyncObservation) {
                $byRemoteIdentity[$identity] = $observation;

                continue;
            }

            $cursorComparison = $observation->cursor->compare($existing->cursor);

            if ($cursorComparison === 0 && ! $observation->attestation->sameSnapshot($existing->attestation)) {
                throw new InvalidArgumentException(
                    'One remote identity and cursor cannot describe contradictory snapshots.',
                );
            }

            if ($cursorComparison > 0) {
                $byRemoteIdentity[$identity] = $observation;
            }
        }

        $deduplicated = array_values($byRemoteIdentity);
        usort(
            $deduplicated,
            static fn (IncrementalSyncObservation $left, IncrementalSyncObservation $right): int => $left->cursor->compare($right->cursor),
        );

        $nextCursor = $checkpoint->cursor;

        foreach ($deduplicated as $observation) {
            if ($nextCursor === null || $observation->cursor->isAfter($nextCursor)) {
                $nextCursor = $observation->cursor;
            }
        }

        return new IncrementalSyncPage(
            scope: $checkpoint->scope,
            observations: $deduplicated,
            inputCount: $inputCount,
            duplicateCount: $inputCount - count($deduplicated),
            nextCursor: $nextCursor,
        );
    }
}
