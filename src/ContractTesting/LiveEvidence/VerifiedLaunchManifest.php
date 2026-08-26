<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use JsonSerializable;
use LogicException;

/**
 * Reserved boundary for the future native-broker launch-manifest handoff.
 * PHP cannot authenticate a peer UID through SO_PEERCRED without extending
 * the wire protocol, so this boundary intentionally performs no FD or socket I/O.
 */
final class VerifiedLaunchManifest implements JsonSerializable
{
    private function __construct() {}

    public static function consumeFromSupervisorFd6(): never
    {
        throw new BrokeredExecutionRequiredException(
            'A native broker must authenticate and attest the supervised launch manifest.',
        );
    }

    public function sha256(): never
    {
        throw new BrokeredExecutionRequiredException(
            'PHP-local launch-manifest objects are not trusted without a native broker attestation.',
        );
    }

    /** @return array{launch_manifest: string} */
    public function __debugInfo(): array
    {
        return ['launch_manifest' => '[REDACTED]'];
    }

    /** @return array{launch_manifest: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Verified launch manifests cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Verified launch manifests cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Verified launch manifests cannot be unserialized.');
    }
}
