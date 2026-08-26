<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\ValueObjects;

use InvalidArgumentException;

final readonly class Pagination
{
    public const MaximumPerPage = 100;

    public const MaximumStreamPages = 100;

    public function __construct(
        public int $page = 1,
        public int $perPage = self::MaximumPerPage,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('The page number must be at least one.');
        }

        if ($perPage < 1 || $perPage > self::MaximumPerPage) {
            throw new InvalidArgumentException('The page size must be between one and 100.');
        }
    }

    public function withPage(int $page): self
    {
        return new self($page, $this->perPage);
    }

    public static function assertMaximumPages(int $maximumPages): void
    {
        if ($maximumPages < 1 || $maximumPages > self::MaximumStreamPages) {
            throw new InvalidArgumentException('The stream page limit must be between one and 100.');
        }
    }
}
