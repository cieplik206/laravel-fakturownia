<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;
use Stringable;
use Symfony\Component\Uid\Ulid;

final readonly class ArtifactId implements Stringable
{
    use RejectsNativeSerialization;

    public string $value;

    public function __construct(string $value)
    {
        if (! Ulid::isValid($value)) {
            throw new InvalidArgumentException('Artifact ID must be a valid ULID.');
        }

        $this->value = (string) Ulid::fromString($value);
    }

    public static function fromRevisionHmac(string $revisionKeyHmac): self
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $revisionKeyHmac) !== 1) {
            throw new InvalidArgumentException('Artifact revision HMAC must use lowercase hexadecimal.');
        }

        $binary = hex2bin(substr($revisionKeyHmac, 0, 32));

        if (! is_string($binary)) {
            throw new InvalidArgumentException('Artifact revision HMAC cannot be decoded.');
        }

        return new self((string) Ulid::fromBinary($binary));
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
