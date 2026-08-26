<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\Evidence;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SensitiveParameter;

/** @internal */
trait ValidatesDiagnosticEvidence
{
    /** @param array<string, mixed> $document */
    private static function assertCanonicalDocument(#[SensitiveParameter] array $document): void
    {
        CanonicalCodec::encode($document);
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function assertExactKeys(#[SensitiveParameter] array $value, array $expected, string $label): void
    {
        $actual = \array_keys($value);
        \sort($actual, \SORT_STRING);
        \sort($expected, \SORT_STRING);

        if ($actual !== $expected) {
            throw new InvalidArgumentException("The {$label} fields are not exact.");
        }
    }

    /** @param array<string, mixed> $value */
    private static function string(#[SensitiveParameter] array $value, string $key, string $label): string
    {
        $candidate = $value[$key] ?? null;

        if (! \is_string($candidate)) {
            throw new InvalidArgumentException("The {$label} must be a string.");
        }

        return $candidate;
    }

    /** @param array<string, mixed> $value */
    private static function integer(#[SensitiveParameter] array $value, string $key, string $label): int
    {
        $candidate = $value[$key] ?? null;

        if (! \is_int($candidate)) {
            throw new InvalidArgumentException("The {$label} must be an integer.");
        }

        return $candidate;
    }

    /** @param array<string, mixed> $value */
    private static function boolean(#[SensitiveParameter] array $value, string $key, string $label): bool
    {
        $candidate = $value[$key] ?? null;

        if (! \is_bool($candidate)) {
            throw new InvalidArgumentException("The {$label} must be a boolean.");
        }

        return $candidate;
    }

    /** @return array<string, mixed> */
    private static function object(#[SensitiveParameter] mixed $value, string $label): array
    {
        if (! \is_array($value) || \array_is_list($value)) {
            throw new InvalidArgumentException("The {$label} must be an object.");
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function list(#[SensitiveParameter] mixed $value, string $label, int $maximum): array
    {
        if (! \is_array($value) || ! \array_is_list($value) || \count($value) > $maximum) {
            throw new InvalidArgumentException("The {$label} must be a bounded list.");
        }

        return $value;
    }

    private static function assertIdentifier(#[SensitiveParameter] string $value, string $label): void
    {
        if (\preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The {$label} is invalid.");
        }
    }

    private static function assertSha256(#[SensitiveParameter] string $value, string $label): void
    {
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The {$label} must be a lower-case SHA-256 digest.");
        }
    }

    private static function assertRepositoryCommit(#[SensitiveParameter] string $value): void
    {
        if (\preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The evidence repository commit is invalid.');
        }
    }

    private static function strictUtcMicrosecondInstant(#[SensitiveParameter] string $value, string $label): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (! $instant instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $instant->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new InvalidArgumentException("The {$label} must use strict UTC microseconds.");
        }

        return $instant;
    }

    private static function microseconds(DateTimeImmutable $instant): int
    {
        return ((int) $instant->format('U') * 1_000_000) + (int) $instant->format('u');
    }

    /** @param array<string, mixed> $provider */
    private static function assertProvider(#[SensitiveParameter] array $provider): void
    {
        self::assertExactKeys($provider, ['profile', 'connection_scope_hmac_sha256', 'hmac_policy'], 'evidence provider');
        $profile = self::string($provider, 'profile', 'provider profile');

        if (! \in_array($profile, ['demo_pl', 'demo_regional', 'ksef_demo'], true)) {
            throw new InvalidArgumentException('The evidence provider profile is not an allowlisted public profile class.');
        }
        self::assertSha256(
            self::string($provider, 'connection_scope_hmac_sha256', 'connection scope HMAC'),
            'connection scope HMAC',
        );
        $hmacPolicy = self::object($provider['hmac_policy'] ?? null, 'evidence HMAC policy');
        self::assertExactKeys(
            $hmacPolicy,
            ['contract', 'version', 'key_id_sha256', 'framing'],
            'evidence HMAC policy',
        );

        if (self::string($hmacPolicy, 'contract', 'HMAC policy contract') !== 'cieplik206.fakturownia.diagnostic-hmac-policy'
            || self::string($hmacPolicy, 'version', 'HMAC policy version') !== '1'
            || self::string($hmacPolicy, 'framing', 'HMAC policy framing') !== 'canonical_length_prefixed_field_domains_v1') {
            throw new InvalidArgumentException('The evidence HMAC policy is invalid.');
        }
        self::assertSha256(self::string($hmacPolicy, 'key_id_sha256', 'HMAC key ID'), 'HMAC key ID');
    }

    /** @param array<string, mixed> $harness */
    private static function assertHarness(#[SensitiveParameter] array $harness): void
    {
        self::assertExactKeys($harness, [
            'repository_commit',
            'code_sha256',
            'launch_manifest_sha256',
            'dependency_lock_sha256',
            'php_runtime_sha256',
            'saloon_package_version',
            'saloon_package_tree_sha256',
            'saloon_runtime_sha256',
        ], 'evidence harness');
        self::assertRepositoryCommit(self::string($harness, 'repository_commit', 'repository commit'));

        foreach (['code_sha256', 'launch_manifest_sha256', 'dependency_lock_sha256', 'php_runtime_sha256', 'saloon_package_tree_sha256', 'saloon_runtime_sha256'] as $field) {
            self::assertSha256(self::string($harness, $field, $field), $field);
        }

        $saloonVersion = self::string($harness, 'saloon_package_version', 'Saloon package version');

        if (\preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.-]+)?\z/D', $saloonVersion) !== 1) {
            throw new InvalidArgumentException('The Saloon package version is invalid.');
        }
    }

    /** @param array<string, mixed> $run */
    private static function assertRun(#[SensitiveParameter] array $run): void
    {
        self::assertExactKeys($run, ['run_id', 'started_at', 'finished_at', 'environment'], 'evidence run');
        $runId = self::string($run, 'run_id', 'run ID');
        $environment = self::string($run, 'environment', 'run environment');

        if (\preg_match('/\A[a-f0-9]{32}\z/D', $runId) !== 1) {
            throw new InvalidArgumentException('The evidence run ID is invalid.');
        }

        if (! \in_array($environment, ['demo_pl', 'demo_regional', 'ksef_demo'], true)) {
            throw new InvalidArgumentException('The evidence run environment is invalid.');
        }
        $startedAt = self::strictUtcMicrosecondInstant(self::string($run, 'started_at', 'run start'), 'run start');
        $finishedAt = self::strictUtcMicrosecondInstant(self::string($run, 'finished_at', 'run finish'), 'run finish');

        if (self::microseconds($startedAt) > self::microseconds($finishedAt)
            || self::microseconds($finishedAt) - self::microseconds($startedAt) > 21_600_000_000) {
            throw new InvalidArgumentException('The evidence run interval is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $provider
     * @param  array<string, mixed>  $run
     */
    private static function assertProviderRunBinding(
        #[SensitiveParameter] array $provider,
        #[SensitiveParameter] array $run,
    ): void {
        if (self::string($provider, 'profile', 'provider profile')
            !== self::string($run, 'environment', 'run environment')) {
            throw new InvalidArgumentException('The evidence provider profile does not match its run environment.');
        }
    }

    /** @param array<string, mixed> $fixture */
    private static function assertFixture(#[SensitiveParameter] array $fixture, string $expectedContract): void
    {
        self::assertExactKeys($fixture, ['contract', 'version', 'sha256', 'bytes'], 'evidence fixture');

        if (self::string($fixture, 'contract', 'fixture contract') !== $expectedContract
            || self::string($fixture, 'version', 'fixture version') !== '1') {
            throw new InvalidArgumentException('The evidence fixture contract or version is invalid.');
        }

        self::assertSha256(self::string($fixture, 'sha256', 'fixture SHA-256'), 'fixture SHA-256');
        $bytes = self::integer($fixture, 'bytes', 'fixture byte count');

        if ($bytes < 2 || $bytes > 16_777_216) {
            throw new InvalidArgumentException('The evidence fixture byte count is invalid.');
        }
    }

    /** @param array<string, mixed> $document */
    private static function assertPayloadSha256(#[SensitiveParameter] array $document): void
    {
        $expected = self::string($document, 'payload_sha256', 'payload SHA-256');
        self::assertSha256($expected, 'payload SHA-256');
        unset($document['payload_sha256']);
        $actual = \hash('sha256', CanonicalCodec::encode($document));

        if (! \hash_equals($expected, $actual)) {
            throw new InvalidArgumentException('The evidence payload SHA-256 does not match its canonical fields.');
        }
    }

    /** @param array<string, mixed> $run */
    private static function assertInstantWithinRun(
        #[SensitiveParameter] string $value,
        #[SensitiveParameter] array $run,
        string $label,
    ): void {
        $instant = self::strictUtcMicrosecondInstant($value, $label);
        $started = self::strictUtcMicrosecondInstant(self::string($run, 'started_at', 'run start'), 'run start');
        $finished = self::strictUtcMicrosecondInstant(self::string($run, 'finished_at', 'run finish'), 'run finish');
        $microseconds = self::microseconds($instant);

        if ($microseconds < self::microseconds($started)
            || $microseconds > self::microseconds($finished)) {
            throw new InvalidArgumentException("The {$label} is outside the run interval.");
        }
    }
}
