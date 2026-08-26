<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use LogicException;

final readonly class ArtifactPurgePermitIssuer
{
    use RejectsNativeSerialization;

    private function __construct(private ArtifactPurgePermitKey $key) {}

    public static function create(): self
    {
        return new self(ArtifactPurgePermitKey::generate());
    }

    public function verifier(): ArtifactPurgePermitVerifier
    {
        return new ArtifactPurgePermitVerifier($this->key);
    }

    public function issueOrphan(
        ArtifactStorageNamespace $storageNamespace,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): ArtifactPurgePermit {
        return $this->issue(
            ArtifactPurgePurpose::Orphan,
            ArtifactPurgeClaims::orphan($storageNamespace, $observation, $deadline),
        );
    }

    public function issueExpired(
        ArtifactStorageNamespace $storageNamespace,
        ArtifactMaintenanceRecord $record,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): ArtifactPurgePermit {
        return $this->issue(
            ArtifactPurgePurpose::Expired,
            ArtifactPurgeClaims::expired($storageNamespace, $record, $observation, $deadline),
        );
    }

    /** @return array{artifact_purge_issuer: string} */
    public function __debugInfo(): array
    {
        return ['artifact_purge_issuer' => '[REDACTED]'];
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Artifact purge permit issuers cannot be cloned.');
    }

    private function issue(ArtifactPurgePurpose $purpose, string $claimsSha256): ArtifactPurgePermit
    {
        $nonce = bin2hex(random_bytes(32));
        $message = $purpose->value."\0".$nonce."\0".$claimsSha256;

        return new ArtifactPurgePermit(
            $this->key,
            $purpose,
            $nonce,
            $claimsSha256,
            $this->key->sign($message),
        );
    }
}
