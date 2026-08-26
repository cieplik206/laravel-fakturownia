<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use InvalidArgumentException;
use ReflectionReference;

/**
 * @template T of object
 */
final readonly class ReadPage
{
    /** @var list<T> */
    private array $items;

    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public int $number,
        public int $perPage,
        array $items,
        public ?string $providerRequestId = null,
    ) {
        if ($number < 1 || $perPage < 1 || $perPage > 100 || count($items) > $perPage) {
            throw new InvalidArgumentException('The typed read page is invalid.');
        }

        $normalized = [];

        foreach ($items as $key => $item) {
            if (ReflectionReference::fromArrayElement($items, $key) !== null) {
                throw new InvalidArgumentException('The typed read page must not contain references.');
            }

            $normalized[] = $item;
        }

        $this->items = $normalized;
    }

    /** @return list<T> */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isTerminal(): bool
    {
        return $this->count() < $this->perPage;
    }
}
