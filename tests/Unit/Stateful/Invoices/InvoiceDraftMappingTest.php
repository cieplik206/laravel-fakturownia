<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Invoices\Exceptions\InvoiceDraftInvalid;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoicePayment;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceValidationProfile;
use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoicePayloadMapper;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceRequestPayload;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceResponseMapper;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;

it('maps the immutable VAT draft to the credential-free top-level request fixture', function (): void {
    $draft = InvoiceFixtures::draft();
    $scope = InvoiceFixtures::scope();
    $identity = RemoteInvoiceIdentity::businessOid(
        $scope,
        'ORDER-123',
        OidUniquenessGate::notPassed(),
    );
    $payload = (new IssueInvoicePayloadMapper)->map(
        $draft,
        InvoiceValidationProfile::KsefStrict,
        $identity,
    );
    $actual = [
        'method' => 'POST',
        'path' => '/invoices.json',
        'authentication' => $payload->authenticationContract(),
        'headers' => $payload->headers(),
        'query' => $payload->query(),
        'body' => $payload->bodyWithoutCredentials(),
    ];

    expect($actual)->toBe(InvoiceFixtures::json('issue-vat-request.json'))
        ->and(json_encode($actual, JSON_THROW_ON_ERROR))->not->toContain('secret')
        ->and(print_r($payload, true))->not->toContain('buyer@example.test')
        ->and(print_r($payload, true))->not->toContain('123-456-78-90')
        ->and($payload->bodyWithoutCredentials()['invoice'])->not->toHaveKey('oid_unique')
        ->and($payload->validation->isValid())->toBeTrue();
});

it('maps an extensible provider response without coercing float money', function (): void {
    $fixture = InvoiceFixtures::json('issue-vat-response.json');
    $result = (new IssueInvoiceResponseMapper)->map($fixture);

    expect($result->remoteId)->toBe('380058094')
        ->and($result->totalGross->decimal())->toBe('100.00')
        ->and($result->positions)->toHaveCount(2)
        ->and($result->extra())->toBe(['provider_future_field' => 'preserved']);

    $fixture['price_gross'] = 100.0;

    expect(fn () => (new IssueInvoiceResponseMapper)->map($fixture))
        ->toThrow(InvalidArgumentException::class);
});

it('reports KSeF profile issues and blocks them only in strict mode', function (): void {
    $base = InvoiceFixtures::draft();
    $draft = new InvoiceDraft(
        kind: $base->kind,
        income: $base->income,
        sellDate: $base->sellDate,
        issueDate: $base->issueDate,
        departmentId: $base->departmentId,
        buyer: $base->buyer,
        payment: $base->payment,
        description: str_repeat('D', 3501),
        positions: [
            new InvoiceLine(
                str_repeat('P', 257),
                '23',
                Money::fromDecimal('100.00', 'PLN'),
                '1',
            ),
        ],
    );
    $identity = RemoteInvoiceIdentity::withoutRemoteUniqueness(InvoiceFixtures::scope());
    $mapper = new IssueInvoicePayloadMapper;

    expect(fn () => $mapper->map($draft, InvoiceValidationProfile::KsefStrict, $identity))
        ->toThrow(InvoiceDraftInvalid::class);

    $payload = $mapper->map($draft, InvoiceValidationProfile::KsefAdvisory, $identity);

    expect($payload->validation->issues)->toHaveCount(2)
        ->and($payload->bodyWithoutCredentials()['invoice']['description'])->toHaveLength(3501)
        ->and($payload->bodyWithoutCredentials()['invoice'])->not->toHaveKey('oid_unique');
});

it('preserves a legacy country name for standard mapping but rejects it for strict KSeF', function (): void {
    $base = InvoiceFixtures::draft();
    $buyer = new InvoiceBuyer(
        company: $base->buyer->company,
        name: $base->buyer->name,
        taxNumber: $base->buyer->taxNumber,
        postCode: $base->buyer->postCode,
        city: $base->buyer->city,
        street: $base->buyer->street,
        country: 'Polska',
        email: $base->buyer->email,
        taxNumberKind: $base->buyer->taxNumberKind,
    );
    $draft = new InvoiceDraft(
        kind: $base->kind,
        income: $base->income,
        sellDate: $base->sellDate,
        issueDate: $base->issueDate,
        departmentId: $base->departmentId,
        buyer: $buyer,
        payment: $base->payment,
        description: $base->description,
        positions: $base->positions,
    );
    $identity = RemoteInvoiceIdentity::withoutRemoteUniqueness(InvoiceFixtures::scope());
    $mapper = new IssueInvoicePayloadMapper;

    expect($mapper->map($draft, InvoiceValidationProfile::Standard, $identity)
        ->bodyWithoutCredentials()['invoice']['buyer_country'])->toBe('Polska')
        ->and(fn () => $mapper->map($draft, InvoiceValidationProfile::KsefStrict, $identity))
        ->toThrow(InvoiceDraftInvalid::class);
});

it('enforces hard outbound bounds independently from advisory validation', function (): void {
    $base = InvoiceFixtures::draft();

    expect(fn (): InvoiceLine => new InvoiceLine(
        "Product\nhidden",
        '23',
        Money::fromDecimal('10.00', 'PLN'),
        '1',
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceLine => new InvoiceLine(
            "Product\xFF",
            '23',
            Money::fromDecimal('10.00', 'PLN'),
            '1',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceLine => new InvoiceLine(
            str_repeat('p', 1025),
            '23',
            Money::fromDecimal('10.00', 'PLN'),
            '1',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceLine => new InvoiceLine(
            'Product',
            str_repeat('9', 1000),
            Money::fromDecimal('10.00', 'PLN'),
            '1',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceLine => new InvoiceLine(
            'Product',
            '23',
            Money::fromDecimal('10.00', 'PLN'),
            '1000000000000',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceLine => new InvoiceLine(
            'Product',
            '23.12345',
            Money::fromDecimal('10.00', 'PLN'),
            '1',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoicePayment => new InvoicePayment(
            "transfer\nunsafe",
            'issued',
            Money::fromDecimal('0.00', 'PLN'),
            'date',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoicePayment => new InvoicePayment(
            str_repeat('t', 129),
            'issued',
            Money::fromDecimal('0.00', 'PLN'),
            'date',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoicePayment => new InvoicePayment(
            'transfer',
            'issued',
            Money::fromDecimal('0.00', 'PLN'),
            'date',
            "2026-08-26\n",
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceDraft => new InvoiceDraft(
            kind: $base->kind,
            income: $base->income,
            sellDate: $base->sellDate,
            issueDate: $base->issueDate,
            departmentId: $base->departmentId,
            buyer: $base->buyer,
            payment: $base->payment,
            description: str_repeat('d', 10_001),
            positions: $base->positions,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceDraft => new InvoiceDraft(
            kind: $base->kind,
            income: $base->income,
            sellDate: $base->sellDate,
            issueDate: $base->issueDate,
            departmentId: $base->departmentId,
            buyer: $base->buyer,
            payment: $base->payment,
            description: $base->description,
            positions: array_fill(0, 1001, $base->positions[0]),
        ))->toThrow(InvalidArgumentException::class);
});

it('constructs the credential-free request only from typed immutable inputs', function (): void {
    $request = (new IssueInvoicePayloadMapper)->map(
        InvoiceFixtures::draft(),
        InvoiceValidationProfile::Standard,
        RemoteInvoiceIdentity::businessOid(
            InvoiceFixtures::scope(),
            'ORDER-123',
            OidUniquenessGate::notPassed(),
        ),
    );
    $constructor = new ReflectionMethod(IssueInvoiceRequestPayload::class, '__construct');
    $body = $request->bodyWithoutCredentials();

    array_walk_recursive($body, static function (mixed $value): void {
        expect(is_string($value) || is_int($value) || is_bool($value) || $value === null)
            ->toBeTrue();
    });

    $mutated = $body;
    $mutated['invoice']['positions'][0]['name'] = 'caller mutation';

    expect($constructor->isPrivate())->toBeTrue()
        ->and(json_encode($body, JSON_THROW_ON_ERROR))
        ->not->toMatch('/(?:api[_-]?token|access[_-]?token|authorization|password|secret|credential)/i')
        ->and($request->bodyWithoutCredentials()['invoice']['positions'][0]['name'])
        ->toBe('Produkt testowy [SKU-1]')
        ->and(print_r($request, true))->not->toContain('ORDER-123')
        ->and(fn (): string => serialize($request))
        ->toThrow(LogicException::class);
});

it('revalidates a typed draft at the package-owned request boundary', function (): void {
    $serialized = serialize(InvoiceFixtures::draft());
    $forged = unserialize(str_replace(
        'Produkt testowy [SKU-1]',
        "Produkt\ntestowy [SKU-1]",
        $serialized,
    ));

    if (! $forged instanceof InvoiceDraft) {
        throw new LogicException('The hostile fixture must decode to an invoice draft.');
    }

    expect(fn () => IssueInvoiceRequestPayload::fromDraft(
        $forged,
        InvoiceValidationProfile::Standard,
        RemoteInvoiceIdentity::businessOid(
            InvoiceFixtures::scope(),
            'ORDER-123',
            OidUniquenessGate::notPassed(),
        ),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects native unserialize bypass for package-owned requests', function (): void {
    $class = IssueInvoiceRequestPayload::class;
    $serialized = sprintf('O:%d:"%s":0:{}', strlen($class), $class);

    expect(fn (): mixed => unserialize($serialized))
        ->toThrow(LogicException::class);
});

it('rejects unbounded invalid or credential-bearing issued invoice responses', function (
    Closure $mutate,
): void {
    $payload = InvoiceFixtures::json('issue-vat-response.json');
    $mutate($payload);

    expect(fn () => (new IssueInvoiceResponseMapper)->map($payload))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'nested credential' => [static function (array &$payload): void {
        $payload['provider_meta'] = ['api_token' => 'must-not-survive'];
    }],
    'mutable object' => [static function (array &$payload): void {
        $payload['provider_meta'] = new stdClass;
    }],
    'invalid UTF-8 extra' => [static function (array &$payload): void {
        $payload['provider_meta'] = "invalid\xFF";
    }],
    'control character extra' => [static function (array &$payload): void {
        $payload['provider_meta'] = "hidden\nvalue";
    }],
    'oversized extra' => [static function (array &$payload): void {
        $payload['provider_meta'] = str_repeat('x', 1_048_577);
    }],
    'invalid invoice date' => [static function (array &$payload): void {
        $payload['issue_date'] = '2026-99-99';
    }],
    'unbounded number' => [static function (array &$payload): void {
        $payload['number'] = str_repeat('n', 192);
    }],
    'control character status' => [static function (array &$payload): void {
        $payload['status'] = "issued\nforged";
    }],
    'invalid UTF-8 kind' => [static function (array &$payload): void {
        $payload['kind'] = "vat\xFF";
    }],
    'unbounded OID' => [static function (array &$payload): void {
        $payload['oid'] = str_repeat('o', 257);
    }],
    'too many positions' => [static function (array &$payload): void {
        $payload['positions'] = array_fill(0, 1001, $payload['positions'][0]);
    }],
]);

it('keeps bounded provider response extras deeply immutable', function (): void {
    $payload = InvoiceFixtures::json('issue-vat-response.json');
    $payload['provider_meta'] = [
        'state' => 'accepted',
        'sequence' => 7,
        'terminal' => true,
    ];
    $result = (new IssueInvoiceResponseMapper)->map($payload);
    $extra = $result->extra();
    $extra['provider_meta']['state'] = 'caller mutation';

    expect($result->extra()['provider_meta'])->toBe([
        'state' => 'accepted',
        'sequence' => 7,
        'terminal' => true,
    ])->and(print_r($result, true))->not->toContain('accepted')
        ->and(fn (): string => serialize($result))
        ->toThrow(LogicException::class);
});

it('rejects native unserialize bypass for issued invoice results', function (): void {
    $class = IssuedInvoiceResult::class;
    $serialized = sprintf('O:%d:"%s":0:{}', strlen($class), $class);

    expect(fn (): mixed => unserialize($serialized))
        ->toThrow(LogicException::class);
});
