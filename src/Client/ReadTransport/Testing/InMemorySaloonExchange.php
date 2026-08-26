<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\ReadTransport\Testing;

use InvalidArgumentException;

/** @internal */
final readonly class InMemorySaloonExchange
{
    /** @param array<string, list<string>> $headers */
    private function __construct(
        public ?int $statusCode,
        public array $headers,
        public string $body,
        public ?string $failureMessage,
    ) {}

    /** @param array<string, list<string>> $headers */
    public static function response(int $statusCode, array $headers, string $body = ''): self
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('The in-memory response status is invalid.');
        }

        return new self($statusCode, $headers, $body, null);
    }

    public static function failure(string $unsafeMessage): self
    {
        return new self(null, [], '', $unsafeMessage);
    }
}
