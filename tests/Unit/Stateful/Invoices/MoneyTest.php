<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Invoices\Money;

it('rounds canonical decimal strings without crossing a float boundary', function (string $input, string $expected, int $minorUnits): void {
    $money = Money::fromDecimal($input, 'PLN');

    expect($money->decimal())->toBe($expected)
        ->and($money->minorUnits)->toBe($minorUnits)
        ->and((string) $money)->toBe($expected.' PLN');
})->with([
    'integer' => ['10', '10.00', 1000],
    'one fraction digit' => ['10.2', '10.20', 1020],
    'binary float shaped source string' => ['84.98999999999999', '84.99', 8499],
    'positive midpoint rounds away from zero' => ['1.005', '1.01', 101],
    'negative midpoint rounds away from zero' => ['-1.005', '-1.01', -101],
    'negative value rounds towards zero below midpoint' => ['-1.004', '-1.00', -100],
]);

it('rejects non canonical, ambiguous, and overflowing money values', function (string $input): void {
    expect(fn (): Money => Money::fromDecimal($input, 'PLN'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'float notation' => ['1e2'],
    'comma decimal' => ['10,20'],
    'leading zero' => ['01.00'],
    'leading plus' => ['+1.00'],
    'whitespace' => [' 1.00'],
    'overflow' => ['999999999999999999999999.00'],
]);

it('adds only matching currency and scale with exact minor units', function (): void {
    $sum = Money::fromDecimal('10.01', 'PLN')->plus(Money::fromDecimal('2.99', 'PLN'));

    expect($sum->decimal())->toBe('13.00')
        ->and(fn (): Money => $sum->plus(Money::fromDecimal('1.00', 'EUR')))
        ->toThrow(InvalidArgumentException::class);
});

it('multiplies minor units without crossing a float boundary', function (): void {
    expect(Money::fromDecimal('19.99')->multipliedBy(3)->decimal())->toBe('59.97')
        ->and(fn (): Money => Money::fromDecimal('1')->multipliedBy(-1))
        ->toThrow(InvalidArgumentException::class);
});

it('exposes only a string amount at the public decimal constructor', function (): void {
    $amount = (new ReflectionMethod(Money::class, 'fromDecimal'))->getParameters()[0];

    expect((string) $amount->getType())->toBe('string');
});
