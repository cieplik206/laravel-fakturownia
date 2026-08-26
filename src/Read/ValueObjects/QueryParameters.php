<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\ValueObjects;

use Cieplik206\Fakturownia\Read\Support\PayloadSanitizer;
use InvalidArgumentException;
use JsonException;

final readonly class QueryParameters
{
    /** @var array<string, bool|int|string|list<bool|int|string>> */
    private array $values;

    /** @param array<string, bool|int|string|list<bool|int|string>|null> $values */
    public function __construct(array $values = [])
    {
        $filtered = array_filter($values, static fn (mixed $value): bool => $value !== null);
        $sanitized = PayloadSanitizer::sanitizeArray($filtered);
        $normalized = [];

        foreach ($sanitized as $name => $value) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_]*(?:\[\])?$/', $name) !== 1) {
                throw new InvalidArgumentException('A query parameter name is invalid.');
            }

            if (in_array(strtolower(rtrim($name, '[]')), ['api_token', 'authorization', 'token'], true)) {
                throw new InvalidArgumentException('Credentials must not be present in a read request descriptor.');
            }

            if (is_bool($value) || is_int($value) || is_string($value)) {
                $normalized[$name] = $value;

                continue;
            }

            if (! is_array($value) || ! array_is_list($value)) {
                throw new InvalidArgumentException('Query parameter values must be scalars or scalar lists.');
            }

            $items = [];

            foreach ($value as $item) {
                if (! is_bool($item) && ! is_int($item) && ! is_string($item)) {
                    throw new InvalidArgumentException('A query parameter list contains an invalid item.');
                }

                $items[] = $item;
            }

            $normalized[$name] = $items;
        }

        ksort($normalized, SORT_STRING);
        $this->values = $normalized;
    }

    /** @return array<string, bool|int|string|list<bool|int|string>> */
    public function all(): array
    {
        return $this->values;
    }

    /** @throws JsonException */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
