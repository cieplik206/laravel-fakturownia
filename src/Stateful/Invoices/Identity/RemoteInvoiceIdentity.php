<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Identity;

use InvalidArgumentException;

final readonly class RemoteInvoiceIdentity
{
    private function __construct(
        public RemoteIdentityScope $scope,
        public RemoteIdentityPolicy $policy,
        private ?string $businessOid,
        private ?string $technicalOid,
        private ?string $transactionOrderReference,
        private OidUniquenessGate $uniquenessGate,
    ) {}

    public static function businessOid(
        RemoteIdentityScope $scope,
        string $businessOid,
        OidUniquenessGate $uniquenessGate,
    ): self {
        self::assertReference($businessOid, 'Business OID');

        return new self(
            $scope,
            RemoteIdentityPolicy::BusinessOid,
            $businessOid,
            null,
            $businessOid,
            $uniquenessGate,
        );
    }

    public static function technicalOidWithTransactionOrder(
        RemoteIdentityScope $scope,
        string $technicalOid,
        string $transactionOrderReference,
        OidUniquenessGate $uniquenessGate,
    ): self {
        self::assertReference($technicalOid, 'Technical OID');
        self::assertReference($transactionOrderReference, 'Transaction order reference');

        return new self(
            $scope,
            RemoteIdentityPolicy::TechnicalOidWithTransactionOrder,
            null,
            $technicalOid,
            $transactionOrderReference,
            $uniquenessGate,
        );
    }

    public static function withoutRemoteUniqueness(RemoteIdentityScope $scope): self
    {
        return new self(
            $scope,
            RemoteIdentityPolicy::NoRemoteUniqueness,
            null,
            null,
            null,
            OidUniquenessGate::notPassed(),
        );
    }

    public function oid(): ?string
    {
        return match ($this->policy) {
            RemoteIdentityPolicy::BusinessOid => $this->businessOid,
            RemoteIdentityPolicy::TechnicalOidWithTransactionOrder => $this->technicalOid,
            RemoteIdentityPolicy::NoRemoteUniqueness => null,
        };
    }

    public function transactionOrderReference(): ?string
    {
        return $this->transactionOrderReference;
    }

    public function usesOidUnique(): bool
    {
        return $this->oid() !== null && $this->uniquenessGate->allows();
    }

    public function exactLocator(): ?ExactOidLocator
    {
        $oid = $this->oid();

        return $oid === null || ! $this->uniquenessGate->allows()
            ? null
            : new ExactOidLocator($this->scope, $oid);
    }

    /** @return array{policy: string, scope: string, oid: string, transaction_order: string} */
    public function __debugInfo(): array
    {
        return [
            'policy' => $this->policy->value,
            'scope' => '[REDACTED]',
            'oid' => '[REDACTED]',
            'transaction_order' => '[REDACTED]',
        ];
    }

    private static function assertReference(string $value, string $subject): void
    {
        if (trim($value) === '' || strlen($value) > 256 || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) === 1) {
            throw new InvalidArgumentException("{$subject} must be a bounded printable value.");
        }
    }
}
