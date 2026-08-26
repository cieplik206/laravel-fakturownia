<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Support\Stateful;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoicePayment;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use JsonException;
use RuntimeException;

final class InvoiceFixtures
{
    public static function draft(): InvoiceDraft
    {
        return new InvoiceDraft(
            kind: 'vat',
            income: true,
            sellDate: '2026-08-20',
            issueDate: '2026-08-26',
            departmentId: '376237',
            buyer: new InvoiceBuyer(
                company: true,
                name: 'Firma Testowa Sp. z o.o.',
                taxNumber: '123-456-78-90',
                postCode: '00-001',
                city: 'Warszawa',
                street: 'Testowa 1',
                country: 'PL',
                email: 'buyer@example.test',
                taxNumberKind: '',
            ),
            payment: new InvoicePayment(
                type: 'Przelew',
                status: 'issued',
                paid: Money::fromDecimal('0', 'PLN'),
                dueKind: 'off',
            ),
            description: 'Nr zamówienia: ORDER-123',
            positions: [
                new InvoiceLine(
                    'Produkt testowy [SKU-1]',
                    '23',
                    Money::fromDecimal('89.999', 'PLN'),
                    '1',
                ),
                new InvoiceLine(
                    'Transport DPD',
                    '23',
                    Money::fromDecimal('10', 'PLN'),
                    '1',
                ),
            ],
        );
    }

    public static function scope(): RemoteIdentityScope
    {
        return new RemoteIdentityScope(
            new ConnectionKey('sales'),
            'vat',
            '376237',
        );
    }

    public static function hmac(): HmacSha256
    {
        $keyRing = new class implements LookupHmacKeyRing
        {
            public function activeVersion(): int
            {
                return 7;
            }

            public function readableVersions(): array
            {
                return [7];
            }

            public function hmacSha256(int $version, string $message): string
            {
                return hash_hmac('sha256', $message, 'rt4-test-key-without-production-value');
            }
        };

        return new HmacSha256($keyRing, new CanonicalJsonV1);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function json(string $name): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/Fixtures/Stateful/Invoices/'.$name);

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read RT-4 fixture.');
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('RT-4 fixture must decode to an object.');
        }

        return $decoded;
    }
}
