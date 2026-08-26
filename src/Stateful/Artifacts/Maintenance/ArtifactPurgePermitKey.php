<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use LogicException;
use SensitiveParameter;

final readonly class ArtifactPurgePermitKey
{
    use RejectsNativeSerialization;

    private function __construct(#[SensitiveParameter] private string $secret) {}

    public static function generate(): self
    {
        return new self(random_bytes(32));
    }

    public function sign(#[SensitiveParameter] string $message): string
    {
        return hash_hmac('sha256', $message, $this->secret);
    }

    public function verifies(#[SensitiveParameter] string $message, string $signature): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && hash_equals($this->sign($message), $signature);
    }

    /** @return array{artifact_purge_key: string} */
    public function __debugInfo(): array
    {
        return ['artifact_purge_key' => '[REDACTED]'];
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Artifact purge permit keys cannot be cloned.');
    }
}
