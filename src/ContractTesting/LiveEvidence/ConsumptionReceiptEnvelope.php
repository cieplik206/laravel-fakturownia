<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class ConsumptionReceiptEnvelope
{
    public const Contract = 'cieplik206.fakturownia.authorization-consumption-receipt';

    public const Version = '1';

    public const Algorithm = 'Ed25519';

    public function __construct(
        #[SensitiveParameter] public string $signerId,
        #[SensitiveParameter] public string $issuedAt,
        #[SensitiveParameter] public string $expiresAt,
        #[SensitiveParameter] public ClaimCursor $claimCursor,
        #[SensitiveParameter] public ConsumptionDisposition $disposition,
        #[SensitiveParameter] public ConsumptionClaimRequest $claimRequest,
    ) {
        if (\preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $signerId) !== 1
            || $signerId !== $claimRequest->authorityId) {
            throw new InvalidArgumentException('The consumption receipt signer must be the authority requested by the claim.');
        }

        if ($claimCursor->storeId !== $claimRequest->storeId) {
            throw new InvalidArgumentException('The consumption receipt cursor must belong to the requested store.');
        }

        $issued = self::strictUtcMicrosecondInstant($issuedAt, 'issued time');
        $expires = self::strictUtcMicrosecondInstant($expiresAt, 'expiry time');

        if (self::instantMicroseconds($issued) >= self::instantMicroseconds($expires)) {
            throw new InvalidArgumentException('The consumption receipt expiry must be after its issue time.');
        }
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        self::assertExactKeys($value, [
            'contract',
            'version',
            'algorithm',
            'signer_id',
            'issued_at',
            'expires_at',
            'claim_cursor',
            'disposition',
            'claim_request',
            'claim_request_sha256',
        ]);

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version
            || ($value['algorithm'] ?? null) !== self::Algorithm
            || ! \is_array($value['claim_cursor'] ?? null)
            || ! \is_array($value['claim_request'] ?? null)
            || ! \is_string($value['disposition'] ?? null)) {
            throw new InvalidArgumentException('The consumption receipt envelope must use the exact version 1 contract.');
        }

        $request = ConsumptionClaimRequest::fromArray($value['claim_request']);

        if (! \is_string($value['claim_request_sha256'] ?? null)
            || ! \hash_equals($request->sha256(), $value['claim_request_sha256'])) {
            throw new InvalidArgumentException('The consumption receipt does not bind the exact canonical claim request.');
        }

        $disposition = ConsumptionDisposition::tryFrom($value['disposition']);

        if (! $disposition instanceof ConsumptionDisposition) {
            throw new InvalidArgumentException('The consumption receipt disposition is invalid.');
        }

        return new self(
            self::string($value, 'signer_id'),
            self::string($value, 'issued_at'),
            self::string($value, 'expires_at'),
            ClaimCursor::fromArray($value['claim_cursor']),
            $disposition,
            $request,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'algorithm' => self::Algorithm,
            'signer_id' => $this->signerId,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'claim_cursor' => $this->claimCursor->toArray(),
            'disposition' => $this->disposition->value,
            'claim_request' => $this->claimRequest->toArray(),
            'claim_request_sha256' => $this->claimRequest->sha256(),
        ];
    }

    public function canonical(): string
    {
        return CanonicalCodec::encode($this->toArray());
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private static function assertExactKeys(
        #[SensitiveParameter] array $value,
        array $expectedKeys,
    ): void {
        $keys = \array_keys($value);
        \sort($keys);
        \sort($expectedKeys);

        if ($keys !== $expectedKeys) {
            throw new InvalidArgumentException('The consumption receipt envelope contains missing or unknown fields.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function string(#[SensitiveParameter] array $value, string $key): string
    {
        $string = $value[$key] ?? null;

        if (! \is_string($string)) {
            throw new InvalidArgumentException("The consumption receipt field {$key} must be a string.");
        }

        return $string;
    }

    private static function strictUtcMicrosecondInstant(
        #[SensitiveParameter] string $value,
        string $label,
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
            throw new InvalidArgumentException("The consumption receipt {$label} is not a strict UTC microsecond instant.");
        }

        return $instant;
    }

    private static function instantMicroseconds(DateTimeImmutable $instant): int
    {
        return ((int) $instant->format('U') * 1_000_000) + (int) $instant->format('u');
    }
}
