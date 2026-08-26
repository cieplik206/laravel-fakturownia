<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;
use LogicException;

final readonly class ArtifactPurgePermit
{
    use RejectsNativeSerialization;

    public function __construct(
        ArtifactPurgePermitKey $key,
        private ArtifactPurgePurpose $purpose,
        private string $nonce,
        private string $claimsSha256,
        private string $signature,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $claimsSha256) !== 1
            || ! $key->verifies($this->message(), $signature)) {
            throw new InvalidArgumentException('The artifact purge permit envelope is invalid.');
        }
    }

    public function authenticates(
        ArtifactPurgePermitKey $key,
        ArtifactPurgePurpose $purpose,
        string $claimsSha256,
    ): bool {
        return $this->purpose === $purpose
            && hash_equals($this->claimsSha256, $claimsSha256)
            && $key->verifies($this->message(), $this->signature);
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    /** @return array{artifact_purge_permit: string} */
    public function __debugInfo(): array
    {
        return ['artifact_purge_permit' => '[REDACTED]'];
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Artifact purge permits cannot be cloned.');
    }

    private function message(): string
    {
        return $this->purpose->value."\0".$this->nonce."\0".$this->claimsSha256;
    }
}
