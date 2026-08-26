<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\Administration\AdministrationReadScope;
use InvalidArgumentException;
use ReflectionReference;

final readonly class AccountInvoiceReadPage
{
    /** @var list<InvoiceResponseData> */
    private array $items;

    /** @param array<array-key, mixed> $items */
    public function __construct(
        private string $scopeFingerprint,
        public int $number,
        public int $perPage,
        array $items,
        public string $requestFingerprint,
        public ?string $providerRequestId = null,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $scopeFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $requestFingerprint) !== 1) {
            throw new InvalidArgumentException('The account invoice read page binding is invalid.');
        }

        if ($number < 1 || $perPage < 1 || $perPage > 100 || count($items) > $perPage) {
            throw new InvalidArgumentException('The account invoice read page is invalid.');
        }

        $validatedItems = [];
        $seenRemoteIds = [];

        foreach ($items as $key => $item) {
            if (ReflectionReference::fromArrayElement($items, $key) !== null
                || ! $item instanceof InvoiceResponseData) {
                throw new InvalidArgumentException('The account invoice read page contains an invalid item.');
            }

            if (isset($seenRemoteIds[$item->remoteId])) {
                throw new InvalidArgumentException('The account invoice read page contains duplicate remote IDs.');
            }

            $seenRemoteIds[$item->remoteId] = true;
            $validatedItems[] = $item;
        }

        $this->items = $validatedItems;
    }

    /** @return list<InvoiceResponseData> */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isTerminal(): bool
    {
        return $this->count() < $this->perPage;
    }

    public function matchesScope(AdministrationReadScope $scope): bool
    {
        return hash_equals($this->scopeFingerprint, $scope->fingerprint());
    }
}
