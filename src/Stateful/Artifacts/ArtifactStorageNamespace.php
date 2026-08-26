<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class ArtifactStorageNamespace
{
    use RejectsNativeSerialization;

    public function __construct(public string $disk, public string $prefix)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $disk) !== 1) {
            throw new InvalidArgumentException('The artifact storage namespace disk is invalid.');
        }

        if (strlen($prefix) > 191 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*)*$/D', $prefix) !== 1) {
            throw new InvalidArgumentException('The artifact storage prefix must be a canonical relative namespace.');
        }
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->disk, $other->disk)
            && hash_equals($this->prefix, $other->prefix);
    }

    public function fingerprintSha256(): string
    {
        return hash('sha256', $this->disk."\0".$this->prefix);
    }
}
