<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Shadow;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ShadowDifference implements JsonSerializable
{
    public function __construct(
        public string $path,
        public ShadowDifferenceKind $kind,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_.]{0,127}\z/', $path) !== 1) {
            throw new InvalidArgumentException('A shadow difference path must be a bounded canonical field path.');
        }
    }

    /** @return array{path: string, kind: string} */
    public function jsonSerialize(): array
    {
        return [
            'path' => $this->path,
            'kind' => $this->kind->value,
        ];
    }
}
