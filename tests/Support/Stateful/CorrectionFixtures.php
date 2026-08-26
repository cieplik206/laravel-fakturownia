<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Support\Stateful;

use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionDraft;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionLine;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionLineMode;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionPositionAttributes;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionPositionKind;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final class CorrectionFixtures
{
    public static function identity(
        int $departmentId = 839_841,
        string $localReference = 'return:123',
    ): RemoteInvoiceIdentity {
        return RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
            new RemoteIdentityScope(
                new ConnectionKey('primary'),
                'correction',
                (string) $departmentId,
            ),
            '0198ea14-e955-7ac1-b0c5-2b9397a90e51',
            $localReference,
            OidUniquenessGate::notPassed(),
        );
    }

    public static function buyer(): InvoiceBuyer
    {
        return new InvoiceBuyer(
            company: false,
            name: 'Jan Kowalski',
            taxNumber: null,
            postCode: '00-001',
            city: 'Warszawa',
            street: 'Testowa 1',
            country: 'PL',
            email: 'jan@example.test',
            lastName: 'Kowalski',
            firstName: 'Jan',
        );
    }

    public static function line(string $name = 'Produkt testowy'): CorrectionLine
    {
        $before = new CorrectionPositionAttributes(
            kind: CorrectionPositionKind::Before,
            name: $name,
            quantity: '2.00',
            totalGross: Money::fromDecimal('100.00', 'PLN'),
            tax: '23',
            priceNet: Money::fromDecimal('40.65', 'PLN'),
            priceGross: Money::fromDecimal('50.00', 'PLN'),
            totalNet: Money::fromDecimal('81.30', 'PLN'),
        );
        $after = new CorrectionPositionAttributes(
            kind: CorrectionPositionKind::After,
            name: $name,
            quantity: '1.00',
            totalGross: Money::fromDecimal('50.00', 'PLN'),
            tax: '23',
            priceNet: Money::fromDecimal('40.65', 'PLN'),
            priceGross: Money::fromDecimal('50.00', 'PLN'),
            totalNet: Money::fromDecimal('40.65', 'PLN'),
        );

        return new CorrectionLine(
            name: $name,
            quantity: '-1.00',
            totalGross: Money::fromDecimal('-50.00', 'PLN'),
            tax: '23',
            before: $before,
            after: $after,
            priceNet: Money::fromDecimal('-40.65', 'PLN'),
            priceGross: Money::fromDecimal('-50.00', 'PLN'),
            totalNet: Money::fromDecimal('-40.65', 'PLN'),
            mode: CorrectionLineMode::Quantity,
        );
    }

    /** @param non-empty-list<CorrectionLine>|null $positions */
    public static function draft(
        ?array $positions = null,
        string $reason = 'Zwrot towaru',
    ): CorrectionDraft {
        return new CorrectionDraft(
            sourceInvoiceId: 'source-123',
            departmentId: 839_841,
            reason: $reason,
            buyer: self::buyer(),
            positions: $positions ?? [self::line()],
            issueDate: '2026-08-26',
            sellDate: '2026-08-25',
            clientId: 'client-77',
        );
    }
}
