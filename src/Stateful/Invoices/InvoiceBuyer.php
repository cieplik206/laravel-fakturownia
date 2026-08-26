<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use InvalidArgumentException;

final readonly class InvoiceBuyer
{
    public function __construct(
        public bool $company,
        public string $name,
        public ?string $taxNumber,
        public string $postCode,
        public string $city,
        public string $street,
        public string $country,
        public string $email,
        public string $lastName = '',
        public ?string $firstName = null,
        public ?string $taxNumberKind = null,
    ) {
        if (! self::validText($name, 256)
            || ! self::validOptionalText($taxNumber, 64, true)
            || ! self::validOptionalText($postCode, 32, true)
            || ! self::validOptionalText($city, 128, true)
            || ! self::validOptionalText($street, 256, true)
            || ! self::validOptionalText($country, 64, true)
            || ! self::validOptionalText($lastName, 128, true)
            || ! self::validOptionalText($firstName, 128, true)
            || ! self::validOptionalText($taxNumberKind, 32, true)
            || ! self::validEmail($email)) {
            throw new InvalidArgumentException('Invoice buyer fields exceed the bounded outbound contract.');
        }
    }

    public function normalizedTaxIdentity(): ?string
    {
        if ($this->taxNumber === null || trim($this->taxNumber) === '') {
            return null;
        }

        $normalized = preg_replace('/[\s.\-]+/u', '', strtoupper(trim($this->taxNumber)));

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }

    private static function validText(string $value, int $maximumLength): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= $maximumLength
            && preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }

    private static function validOptionalText(
        ?string $value,
        int $maximumLength,
        bool $allowsEmpty,
    ): bool {
        if ($value === null || ($allowsEmpty && $value === '')) {
            return true;
        }

        return self::validText($value, $maximumLength);
    }

    private static function validEmail(string $email): bool
    {
        if ($email === '') {
            return true;
        }

        return self::validText($email, 254)
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
