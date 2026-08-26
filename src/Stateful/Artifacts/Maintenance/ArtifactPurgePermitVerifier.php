<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Exceptions\ArtifactPurgeUnauthorized;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use LogicException;

final class ArtifactPurgePermitVerifier
{
    use RejectsNativeSerialization;

    /** @var array<string, true> */
    private array $consumedNonces = [];

    public function __construct(private readonly ArtifactPurgePermitKey $key) {}

    public function consumeOrphan(
        ArtifactPurgePermit $permit,
        ArtifactStorageNamespace $storageNamespace,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): void {
        $this->consume(
            $permit,
            ArtifactPurgePurpose::Orphan,
            ArtifactPurgeClaims::orphan($storageNamespace, $observation, $deadline),
        );
    }

    public function consumeExpired(
        ArtifactPurgePermit $permit,
        ArtifactStorageNamespace $storageNamespace,
        ArtifactMaintenanceRecord $record,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): void {
        $this->consume(
            $permit,
            ArtifactPurgePurpose::Expired,
            ArtifactPurgeClaims::expired($storageNamespace, $record, $observation, $deadline),
        );
    }

    public function assertConsumed(ArtifactPurgePermit $permit): void
    {
        if (! isset($this->consumedNonces[$permit->nonce()])) {
            throw ArtifactPurgeUnauthorized::invalidPermit();
        }
    }

    /** @return array{artifact_purge_verifier: string} */
    public function __debugInfo(): array
    {
        return ['artifact_purge_verifier' => '[REDACTED]'];
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Artifact purge permit verifiers cannot be cloned.');
    }

    private function consume(
        ArtifactPurgePermit $permit,
        ArtifactPurgePurpose $purpose,
        string $claimsSha256,
    ): void {
        $nonce = $permit->nonce();

        if (isset($this->consumedNonces[$nonce])
            || ! $permit->authenticates($this->key, $purpose, $claimsSha256)) {
            throw ArtifactPurgeUnauthorized::invalidPermit();
        }

        $this->consumedNonces[$nonce] = true;
    }
}
