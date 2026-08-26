<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;
use Stringable;

final readonly class ContentAddress implements Stringable
{
    use RejectsNativeSerialization;

    private const Prefix = 'sha256:';

    private function __construct(private string $sha256) {}

    public static function fromSha256(string $sha256): self
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new InvalidArgumentException('The artifact SHA-256 digest must use canonical lowercase hexadecimal.');
        }

        return new self($sha256);
    }

    public static function parse(string $contentAddress): self
    {
        if (! str_starts_with($contentAddress, self::Prefix)) {
            throw new InvalidArgumentException('The artifact content address must use the sha256 scheme.');
        }

        return self::fromSha256(substr($contentAddress, strlen(self::Prefix)));
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function __toString(): string
    {
        return self::Prefix.$this->sha256;
    }
}
