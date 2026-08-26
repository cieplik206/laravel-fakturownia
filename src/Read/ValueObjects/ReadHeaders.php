<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\ValueObjects;

use Cieplik206\Fakturownia\Read\Support\PayloadSanitizer;
use InvalidArgumentException;

final readonly class ReadHeaders
{
    /** @var non-empty-list<string> */
    private const RetainedHeaders = [
        'content-length',
        'content-type',
        'retry-after',
        'x-fakturownia-request-id',
        'x-request-id',
    ];

    /** @var array<string, non-empty-list<string>> */
    private array $values;

    /** @param array<string, string|list<string>> $values */
    public function __construct(array $values = [])
    {
        if (count($values) > 128) {
            throw new InvalidArgumentException('The response contains too many headers.');
        }

        $values = PayloadSanitizer::sanitizeArray($values);
        $normalized = [];

        foreach ($values as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('A response header name must be a string.');
            }

            $lowerName = strtolower($name);

            if (preg_match("/^[!#$%&'*+.^_`|~0-9a-z-]+$/", $lowerName) !== 1) {
                throw new InvalidArgumentException('A response header name is invalid.');
            }

            if (! in_array($lowerName, self::RetainedHeaders, true)) {
                continue;
            }

            if (array_key_exists($lowerName, $normalized)) {
                throw new InvalidArgumentException('A response header name is duplicated with different casing.');
            }

            $items = is_string($value) ? [$value] : array_values($value);

            if ($items === []) {
                throw new InvalidArgumentException('A response header must contain at least one value.');
            }

            foreach ($items as $item) {
                if (! is_string($item)) {
                    throw new InvalidArgumentException('A response header value must be a string.');
                }

                if (strlen($item) > 8192 || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $item) === 1) {
                    throw new InvalidArgumentException('A response header value is invalid.');
                }
            }

            $normalized[$lowerName] = $items;
        }

        ksort($normalized, SORT_STRING);
        $this->values = $normalized;
    }

    public function first(string $name): ?string
    {
        return $this->values[strtolower($name)][0] ?? null;
    }

    /** @return list<string> */
    public function values(string $name): array
    {
        return $this->values[strtolower($name)] ?? [];
    }

    /** @return array<string, non-empty-list<string>> */
    public function all(): array
    {
        return $this->values;
    }

    public function providerRequestId(): ?string
    {
        $requestId = $this->first('x-fakturownia-request-id') ?? $this->first('x-request-id');

        if ($requestId === null || preg_match('/^[\x21-\x7E]{1,200}$/', $requestId) !== 1) {
            return null;
        }

        return $requestId;
    }
}
