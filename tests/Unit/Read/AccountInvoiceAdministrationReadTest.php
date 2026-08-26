<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\Client\ValueObjects\SecretValue;
use Cieplik206\Fakturownia\Read\Administration\AdministrationOperatorReference;
use Cieplik206\Fakturownia\Read\Administration\AdministrationReadScope;
use Cieplik206\Fakturownia\Read\Data\AccountInvoiceListQuery;
use Cieplik206\Fakturownia\Read\Exceptions\UnsupportedCapability;
use Cieplik206\Fakturownia\Read\FakturowniaReadClient;
use Cieplik206\Fakturownia\Read\Requests\ListAccountInvoicesRequest;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Cieplik206\Fakturownia\Stateful\FakturowniaConnection;
use Cieplik206\Fakturownia\Testing\Read\FrozenReadClock;
use Cieplik206\Fakturownia\Testing\Read\LiteralJsonExchange;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadCapabilityGate;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadRequestExecutor;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;
use LogicException;

it('reads one bounded account invoice page with an exact audit scope in the offline contract', function (): void {
    $query = new AccountInvoiceListQuery(new Pagination(page: 2, perPage: 1));
    $request = new ListAccountInvoicesRequest($query);
    $body = json_encode([[
        'id' => 730000101,
        'number' => 'FV/1/08/2026',
        'kind' => 'vat',
        'status' => 'issued',
        'issue_date' => '2026-08-25',
        'price_gross' => '123.4500',
        'currency' => 'PLN',
    ]], JSON_THROW_ON_ERROR);
    $executor = new LiteralReadRequestExecutor([
        LiteralJsonExchange::response($request, 200, [
            'content-type' => 'application/json',
            'content-length' => (string) strlen($body),
            'x-fakturownia-request-id' => 'req-account-page',
        ], $body),
    ]);
    $client = new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate([ReadCapability::AccountInvoiceList]),
        new FrozenReadClock(1_788_192_000),
    );
    $operator = new AdministrationOperatorReference('pms-user:42');
    $scope = new AdministrationReadScope(new ConnectionKey('primary'), $operator);

    $page = $client->accountInvoices()->list($scope, $query);
    $sent = $executor->requests()[0];

    expect($page->number)->toBe(2)
        ->and($page->perPage)->toBe(1)
        ->and($page->count())->toBe(1)
        ->and($page->providerRequestId)->toBe('req-account-page')
        ->and($page->matchesScope($scope))->toBeTrue()
        ->and($page->matchesScope(new AdministrationReadScope(
            new ConnectionKey('foreign'),
            $operator,
        )))->toBeFalse()
        ->and($page->items()[0]->remoteId)->toBe('730000101')
        ->and($page->items()[0]->priceGross?->value)->toBe('123.45')
        ->and($sent->operation())->toBe('account.invoice.read')
        ->and($sent->path())->toBe('/invoices.json')
        ->and($sent->query()->all())->toBe([
            'kinds' => ['accounting_only'],
            'order' => 'number.desc',
            'page' => 2,
            'per_page' => 1,
            'period' => 'last_month',
        ]);

    $executor->assertExhausted();
});

it('keeps the production connection unavailable before network I/O while evidence is deferred', function (): void {
    $connectionKey = new ConnectionKey('primary');
    $connection = new FakturowniaConnection(
        $connectionKey,
        DeploymentStage::Production,
        (new ConnectionConfig(
            BaseUrl::fromString('https://tenant.fakturownia.pl', ['tenant.fakturownia.pl']),
            SecretValue::fromPlaintext('offline-test-token'),
        ))->createClient(),
    );

    try {
        $connection->accountInvoices(
            new AdministrationOperatorReference('pms-user:42'),
            new AccountInvoiceListQuery(new Pagination(1, 25)),
        );
    } catch (UnsupportedCapability $exception) {
        expect($exception->capability)->toBe(ReadCapability::AccountInvoiceList)
            ->and($connection->key()->equals($connectionKey))->toBeTrue();

        return;
    }

    throw new LogicException('The deferred account.invoice.read capability unexpectedly reached transport.');
});

it('does not expose account invoice filter overrides', function (): void {
    $query = new AccountInvoiceListQuery(new Pagination(1, 100));

    expect(array_keys(get_object_vars($query)))->toBe(['pagination'])
        ->and($query->toQuery())->toBe([
            'page' => 1,
            'per_page' => 100,
            'period' => 'last_month',
            'kinds' => ['accounting_only'],
            'order' => 'number.desc',
        ]);
});

it('rejects invalid operator references and unbounded pagination', function (Closure $operation): void {
    expect($operation)->toThrow(InvalidArgumentException::class);
})->with([
    'raw numeric operator' => [fn (): AdministrationOperatorReference => new AdministrationOperatorReference('42')],
    'operator whitespace' => [fn (): AdministrationOperatorReference => new AdministrationOperatorReference('pms-user:42 ')],
    'zero page' => [fn (): AccountInvoiceListQuery => new AccountInvoiceListQuery(new Pagination(0, 25))],
    'oversized page' => [fn (): AccountInvoiceListQuery => new AccountInvoiceListQuery(new Pagination(1, 101))],
]);

it('rejects native serialization of operator and scope audit values', function (): void {
    $operator = new AdministrationOperatorReference('pms-user:42');
    $scope = new AdministrationReadScope(new ConnectionKey('primary'), $operator);

    expect(fn (): string => serialize($operator))->toThrow(LogicException::class)
        ->and(fn (): string => serialize($scope))->toThrow(LogicException::class)
        ->and($scope->matchesConnection(new ConnectionKey('primary')))->toBeTrue()
        ->and($scope->matchesOperator($operator))->toBeTrue()
        ->and($scope->jsonSerialize())->toBe([
            'provider' => 'fakturownia',
            'connection' => '[REDACTED]',
            'operator' => '[REDACTED]',
        ]);
});
