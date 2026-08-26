<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\InvoiceFingerprint;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityPolicy;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceResponseMapper;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;

it('keeps exact business OID uniqueness fail closed without a broker-backed live receipt', function (): void {
    $scope = InvoiceFixtures::scope();
    $notPassed = RemoteInvoiceIdentity::businessOid(
        $scope,
        'ORDER-123',
        OidUniquenessGate::notPassed(),
    );
    $gateReflection = new ReflectionClass(OidUniquenessGate::class);

    expect($notPassed->policy)->toBe(RemoteIdentityPolicy::BusinessOid)
        ->and($notPassed->oid())->toBe('ORDER-123')
        ->and($notPassed->usesOidUnique())->toBeFalse()
        ->and($notPassed->exactLocator())->toBeNull()
        ->and($gateReflection->hasMethod('passed'))->toBeFalse()
        ->and(print_r($notPassed, true))->not->toContain('ORDER-123');
});

it('requires an explicit technical OID and preserves the transaction order separately', function (): void {
    $scope = InvoiceFixtures::scope();
    $identity = RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
        $scope,
        'TECH-EXPLICIT-001',
        'ORDER-123',
        OidUniquenessGate::notPassed(),
    );
    $none = RemoteInvoiceIdentity::withoutRemoteUniqueness($scope);

    expect($identity->policy)->toBe(RemoteIdentityPolicy::TechnicalOidWithTransactionOrder)
        ->and($identity->oid())->toBe('TECH-EXPLICIT-001')
        ->and($identity->transactionOrderReference())->toBe('ORDER-123')
        ->and($none->policy)->toBe(RemoteIdentityPolicy::NoRemoteUniqueness)
        ->and($none->oid())->toBeNull()
        ->and($none->exactLocator())->toBeNull();

    expect(fn () => RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
        $scope,
        '',
        'ORDER-123',
        OidUniquenessGate::notPassed(),
    ))->toThrow(InvalidArgumentException::class);
});

it('produces the same semantic fingerprint for the draft and matching provider result', function (): void {
    $fingerprint = new InvoiceFingerprint(InvoiceFixtures::hmac());
    $draftDigest = $fingerprint->fromDraft(InvoiceFixtures::draft());
    $result = (new IssueInvoiceResponseMapper)->map(InvoiceFixtures::json('issue-vat-response.json'));
    $resultDigest = $fingerprint->fromResult($result);

    expect($draftDigest->equals($resultDigest))->toBeTrue()
        ->and($draftDigest->keyVersion)->toBe(7)
        ->and($draftDigest->hex)->toMatch('/\A[a-f0-9]{64}\z/');

    $changed = InvoiceFixtures::json('issue-vat-response.json');
    $changed['issue_date'] = '2026-08-27';
    $changedDigest = $fingerprint->fromResult((new IssueInvoiceResponseMapper)->map($changed));

    expect($draftDigest->equals($changedDigest))->toBeFalse();
});
