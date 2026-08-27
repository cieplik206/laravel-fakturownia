<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SensitiveParameter;

final class NativeBrokerWireValidation
{
    private function __construct() {}

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    public static function assertExactKeys(
        #[SensitiveParameter] array $value,
        array $expected,
        string $context,
    ): void {
        $keys = \array_keys($value);
        \sort($keys, \SORT_STRING);
        \sort($expected, \SORT_STRING);

        if ($keys !== $expected) {
            throw new InvalidArgumentException("The {$context} contains missing or unknown fields.");
        }
    }

    /** @param array<string, mixed> $value */
    public static function string(
        #[SensitiveParameter] array $value,
        string $key,
        string $context,
    ): string {
        $result = $value[$key] ?? null;

        if (! \is_string($result)) {
            throw new InvalidArgumentException("The {$context} field {$key} must be a string.");
        }

        return $result;
    }

    /** @param array<string, mixed> $value */
    public static function integer(
        #[SensitiveParameter] array $value,
        string $key,
        string $context,
    ): int {
        $result = $value[$key] ?? null;

        if (! \is_int($result)) {
            throw new InvalidArgumentException("The {$context} field {$key} must be an integer.");
        }

        return $result;
    }

    public static function assertIdentifier(
        #[SensitiveParameter] string $value,
        string $context,
    ): void {
        if (\preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The {$context} identifier is invalid.");
        }
    }

    public static function assertSha256(
        #[SensitiveParameter] string $value,
        string $context,
    ): void {
        if (\preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The {$context} SHA-256 is invalid.");
        }
    }

    public static function decodeCanonicalBase64(
        #[SensitiveParameter] string $value,
        int $maximumBytes,
        string $context,
    ): string {
        $decoded = \base64_decode($value, true);

        if ($decoded === false
            || \strlen($decoded) > $maximumBytes
            || \base64_encode($decoded) !== $value) {
            throw new InvalidArgumentException("The {$context} is not canonical bounded base64.");
        }

        return $decoded;
    }

    public static function assertCanonicalBase64Bytes(
        #[SensitiveParameter] string $value,
        int $bytes,
        string $context,
    ): void {
        $decoded = self::decodeCanonicalBase64($value, $bytes, $context);

        if (\strlen($decoded) !== $bytes) {
            throw new InvalidArgumentException("The {$context} has an invalid byte length.");
        }
    }

    public static function strictUtcMicrosecondInstant(
        #[SensitiveParameter] string $value,
        string $context,
    ): DateTimeImmutable {
        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($instant === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $instant->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new InvalidArgumentException("The {$context} is not a strict UTC microsecond instant.");
        }

        return $instant;
    }

    public static function assertBoundedValidity(
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        int $maximumSeconds,
        string $context,
    ): void {
        $issuedMicroseconds = self::instantMicroseconds($issuedAt);
        $expiresMicroseconds = self::instantMicroseconds($expiresAt);

        if ($expiresMicroseconds <= $issuedMicroseconds
            || $expiresMicroseconds - $issuedMicroseconds > $maximumSeconds * 1_000_000) {
            throw new InvalidArgumentException("The {$context} validity window is invalid.");
        }
    }

    private static function instantMicroseconds(DateTimeImmutable $instant): int
    {
        return ((int) $instant->format('U') * 1_000_000) + (int) $instant->format('u');
    }
}
