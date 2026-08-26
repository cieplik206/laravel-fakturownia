<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\ValueObjects;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;
use Stringable;

final readonly class BaseUrl implements Stringable
{
    private SensitiveParameterValue $value;

    private SensitiveParameterValue $host;

    private SensitiveParameterValue $allowedHosts;

    /** @param list<string> $allowedHosts */
    private function __construct(
        #[SensitiveParameter] string $value,
        #[SensitiveParameter] string $host,
        #[SensitiveParameter] array $allowedHosts,
    ) {
        $this->value = new SensitiveParameterValue($value);
        $this->host = new SensitiveParameterValue($host);
        $this->allowedHosts = new SensitiveParameterValue($allowedHosts);
    }

    /** @param array<array-key, string> $allowedHosts */
    public static function fromString(
        #[SensitiveParameter] string $value,
        #[SensitiveParameter] array $allowedHosts,
    ): self {
        $parts = parse_url($value);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('The Fakturownia base URL is malformed.');
        }

        $host = self::safeHost($parts);
        $normalizedAllowedHosts = self::normalizeAllowedHosts($allowedHosts);

        if (! in_array($host, $normalizedAllowedHosts, true)) {
            throw new InvalidArgumentException('The Fakturownia base URL host is not explicitly allowlisted.');
        }

        return new self("https://{$host}", $host, $normalizedAllowedHosts);
    }

    public function host(): string
    {
        $host = $this->host->getValue();

        if (! is_string($host)) {
            throw new LogicException('The Fakturownia base URL host is corrupted.');
        }

        return $host;
    }

    public function equals(self $other): bool
    {
        return $this->value() === $other->value();
    }

    public function allowsHost(#[SensitiveParameter] string $host): bool
    {
        $host = strtolower(trim($host));

        if (! self::isDnsHost($host)) {
            return false;
        }

        return in_array($host, $this->allowedHosts(), true);
    }

    public function __toString(): string
    {
        return $this->value();
    }

    /** @param array<string, int|string> $parts */
    private static function safeHost(#[SensitiveParameter] array $parts): string
    {
        if (($parts['scheme'] ?? null) !== 'https') {
            throw new InvalidArgumentException('The Fakturownia base URL must use HTTPS.');
        }

        if (! isset($parts['host']) || ! is_string($parts['host'])) {
            throw new InvalidArgumentException('The Fakturownia base URL must contain a host.');
        }

        $host = strtolower($parts['host']);

        if (! self::isDnsHost($host)) {
            throw new InvalidArgumentException('The Fakturownia base URL host is invalid.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The Fakturownia base URL must not contain credentials, query parameters, or fragments.');
        }

        if (isset($parts['port'])) {
            throw new InvalidArgumentException('The Fakturownia base URL must not contain an explicit port.');
        }

        if (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true)) {
            throw new InvalidArgumentException('The Fakturownia base URL must not contain a path.');
        }

        return $host;
    }

    /**
     * @param  array<array-key, string>  $allowedHosts
     * @return list<string>
     */
    private static function normalizeAllowedHosts(#[SensitiveParameter] array $allowedHosts): array
    {
        if ($allowedHosts === []) {
            throw new InvalidArgumentException('At least one exact Fakturownia host must be allowlisted.');
        }

        $normalized = [];

        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower(trim($allowedHost));

            if (! self::isDnsHost($allowedHost)) {
                throw new InvalidArgumentException('An allowlisted Fakturownia host is invalid.');
            }

            $normalized[] = $allowedHost;
        }

        return array_values(array_unique($normalized));
    }

    private static function isDnsHost(string $host): bool
    {
        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function value(): string
    {
        $value = $this->value->getValue();

        if (! is_string($value)) {
            throw new LogicException('The Fakturownia base URL is corrupted.');
        }

        return $value;
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        $allowedHosts = $this->allowedHosts->getValue();

        if (! is_array($allowedHosts) || ! array_is_list($allowedHosts)) {
            throw new LogicException('The Fakturownia allowed host policy is corrupted.');
        }

        foreach ($allowedHosts as $allowedHost) {
            if (! is_string($allowedHost)) {
                throw new LogicException('The Fakturownia allowed host policy is corrupted.');
            }
        }

        return $allowedHosts;
    }
}
