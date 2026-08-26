<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Shadow;

use InvalidArgumentException;
use JsonSerializable;

final readonly class InvoiceShadowComparisonResult implements JsonSerializable
{
    /** @var list<ShadowDifference> */
    public array $differences;

    /** @param array<mixed> $differences */
    public function __construct(array $differences)
    {
        foreach ($differences as $difference) {
            if (! $difference instanceof ShadowDifference) {
                throw new InvalidArgumentException('A shadow comparison result contains an invalid difference.');
            }
        }

        $this->differences = array_values($differences);
    }

    public function matches(): bool
    {
        return $this->differences === [];
    }

    /** @return array{matches: bool, difference_count: int, differences: list<ShadowDifference>} */
    public function jsonSerialize(): array
    {
        return [
            'matches' => $this->matches(),
            'difference_count' => count($this->differences),
            'differences' => $this->differences,
        ];
    }
}
