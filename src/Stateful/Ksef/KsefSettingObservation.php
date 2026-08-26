<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

final readonly class KsefSettingObservation
{
    private function __construct(
        public string $connectionFingerprintSha256,
        public KsefAccountSetting $setting,
        public string|bool|null $value,
        public KsefSettingEvidenceSource $source,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $validUntil,
        public string $evidenceMacSha256,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $connectionFingerprintSha256) !== 1) {
            throw new InvalidArgumentException('KSeF setting evidence requires an exact connection fingerprint.');
        }

        if (is_string($value) && ($value === '' || $value !== trim($value) || strlen($value) > 128)) {
            throw new InvalidArgumentException('The observed KSeF setting value is invalid.');
        }

        $ttlSeconds = $validUntil->getTimestamp() - $observedAt->getTimestamp();

        if ($ttlSeconds <= 0 || $ttlSeconds > $source->maximumTtlSeconds()) {
            throw new InvalidArgumentException('KSeF setting evidence must have a bounded validity window.');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/', $evidenceMacSha256) !== 1) {
            throw new InvalidArgumentException('KSeF setting evidence requires an exact HMAC-SHA-256 tag.');
        }

        $expectedType = match ($setting) {
            KsefAccountSetting::GovAutoSendMode => 'string_or_null',
            KsefAccountSetting::ValidateInvoicesForGov, KsefAccountSetting::BuyerCompany => 'bool',
        };

        if (($expectedType === 'bool' && ! is_bool($value))
            || ($expectedType === 'string_or_null' && ! is_string($value) && $value !== null)) {
            throw new InvalidArgumentException('The observed KSeF setting has an invalid value type.');
        }
    }

    public static function fromSignedEvidence(
        string $connectionFingerprintSha256,
        KsefAccountSetting $setting,
        string|bool|null $value,
        KsefSettingEvidenceSource $source,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $validUntil,
        string $evidenceMacSha256,
    ): self {
        return new self(
            $connectionFingerprintSha256,
            $setting,
            $value,
            $source,
            $observedAt,
            $validUntil,
            $evidenceMacSha256,
        );
    }

    public function isFreshAt(DateTimeImmutable $now): bool
    {
        return $this->observedAt <= $now
            && $this->validUntil > $now;
    }

    public function evidencePayload(): string
    {
        $utc = new DateTimeZone('UTC');

        return json_encode([
            'connection_fingerprint_sha256' => $this->connectionFingerprintSha256,
            'setting' => $this->setting->value,
            'value' => $this->value,
            'source' => $this->source->value,
            'observed_at' => $this->observedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.uP'),
            'valid_until' => $this->validUntil->setTimezone($utc)->format('Y-m-d\TH:i:s.uP'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('KSeF setting observations cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('KSeF setting observations cannot be unserialized.');
    }
}
