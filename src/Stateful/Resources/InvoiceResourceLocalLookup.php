<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use InvalidArgumentException;

final readonly class InvoiceResourceLocalLookup
{
    use RejectsNativeSerialization;

    private const string Protocol = 'cieplik206.fakturownia.invoice-resource.local-reference.v1';

    private const int MaximumReadableDigests = 32;

    /** @var non-empty-list<VersionedHmacDigest> */
    public array $digests;

    /**
     * @param  non-empty-list<VersionedHmacDigest>  $digests
     */
    private function __construct(
        public string $referenceType,
        public VersionedHmacDigest $activeDigest,
        array $digests,
    ) {
        if (! in_array($referenceType, InvoiceResource::localReferenceTypes(), true)
            || $activeDigest->domain !== LookupHmacDomain::Intent
            || count($digests) > self::MaximumReadableDigests) {
            throw new InvalidArgumentException('Invoice resource local lookup metadata is invalid.');
        }

        $seenVersions = [];
        $containsActive = false;

        foreach ($digests as $digest) {
            if ($digest->domain !== LookupHmacDomain::Intent || isset($seenVersions[$digest->keyVersion])) {
                throw new InvalidArgumentException('Invoice resource local lookup digests are invalid.');
            }

            $seenVersions[$digest->keyVersion] = true;
            $containsActive = $containsActive || $digest->equals($activeDigest);
        }

        if (! $containsActive) {
            throw new InvalidArgumentException('Invoice resource local lookup does not contain its active digest.');
        }

        usort(
            $digests,
            static fn (VersionedHmacDigest $left, VersionedHmacDigest $right): int => $left->keyVersion <=> $right->keyVersion,
        );
        $this->digests = $digests;
    }

    public static function forTransactionOrder(HmacSha256 $hmac, string $reference): self
    {
        return self::forReference($hmac, InvoiceResource::LocalReferenceType, $reference);
    }

    public static function forCustomerReturn(HmacSha256 $hmac, string $reference): self
    {
        return self::forReference($hmac, InvoiceResource::CorrectionLocalReferenceType, $reference);
    }

    public static function forCostInvoice(HmacSha256 $hmac, string $reference): self
    {
        return self::forReference($hmac, InvoiceResource::CostLocalReferenceType, $reference);
    }

    private static function forReference(HmacSha256 $hmac, string $referenceType, string $reference): self
    {
        self::assertReference($reference);
        $material = (new CanonicalJsonV1)->encode(new CanonicalObject([
            'protocol' => self::Protocol,
            'reference_type' => $referenceType,
            'reference' => $reference,
        ]));
        $active = $hmac->digest(LookupHmacDomain::Intent, $material);
        $readable = $hmac->readableDigests(LookupHmacDomain::Intent, $material);

        if ($readable === []) {
            throw new InvalidArgumentException('Invoice resource local lookup has no readable HMAC version.');
        }

        return new self($referenceType, $active, $readable);
    }

    /** @return array{reference_type: string, reference: string, digest_versions: list<int>} */
    public function __debugInfo(): array
    {
        return [
            'reference_type' => $this->referenceType,
            'reference' => '[REDACTED]',
            'digest_versions' => array_map(
                static fn (VersionedHmacDigest $digest): int => $digest->keyVersion,
                $this->digests,
            ),
        ];
    }

    private static function assertReference(string $reference): void
    {
        if ($reference === ''
            || $reference !== trim($reference)
            || strlen($reference) > 256
            || preg_match('//u', $reference) !== 1
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $reference) === 1) {
            throw new InvalidArgumentException('Invoice resource local reference is invalid.');
        }
    }
}
