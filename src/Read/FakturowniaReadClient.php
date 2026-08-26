<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read;

use Cieplik206\Fakturownia\Read\Contracts\ReadCapabilityGate;
use Cieplik206\Fakturownia\Read\Contracts\ReadClock;
use Cieplik206\Fakturownia\Read\Contracts\ReadRequestExecutor;
use Cieplik206\Fakturownia\Read\Resources\AccountInvoicesResource;
use Cieplik206\Fakturownia\Read\Resources\ClientsResource;
use Cieplik206\Fakturownia\Read\Resources\InvoicesResource;
use Cieplik206\Fakturownia\Read\Resources\PaymentsResource;
use Cieplik206\Fakturownia\Read\Resources\ProductsResource;
use Cieplik206\Fakturownia\Read\Resources\ProformasResource;
use Cieplik206\Fakturownia\Read\Retry\SystemReadClock;
use Cieplik206\Fakturownia\Read\Support\ResponseDecoder;
use JsonSerializable;
use LogicException;

final readonly class FakturowniaReadClient implements JsonSerializable
{
    private AccountInvoicesResource $accountInvoices;

    private InvoicesResource $invoices;

    private ClientsResource $clients;

    private ProductsResource $products;

    private PaymentsResource $payments;

    private ProformasResource $proformas;

    public function __construct(
        ReadRequestExecutor $executor,
        ReadCapabilityGate $capabilities,
        ?ReadClock $clock = null,
    ) {
        $clock ??= new SystemReadClock;
        $decoder = new ResponseDecoder($clock);
        $this->accountInvoices = new AccountInvoicesResource($executor, $capabilities, $decoder, $clock);
        $this->invoices = new InvoicesResource($executor, $capabilities, $decoder, $clock);
        $this->clients = new ClientsResource($executor, $capabilities, $decoder, $clock);
        $this->products = new ProductsResource($executor, $capabilities, $decoder, $clock);
        $this->payments = new PaymentsResource($executor, $capabilities, $decoder, $clock);
        $this->proformas = new ProformasResource($executor, $capabilities, $decoder, $clock);
    }

    public function accountInvoices(): AccountInvoicesResource
    {
        return $this->accountInvoices;
    }

    public function invoices(): InvoicesResource
    {
        return $this->invoices;
    }

    public function clients(): ClientsResource
    {
        return $this->clients;
    }

    public function products(): ProductsResource
    {
        return $this->products;
    }

    public function payments(): PaymentsResource
    {
        return $this->payments;
    }

    public function proformas(): ProformasResource
    {
        return $this->proformas;
    }

    /** @return array{transport: string, credentials: string} */
    public function __debugInfo(): array
    {
        return ['transport' => 'sealed-read-executor', 'credentials' => '[REDACTED]'];
    }

    /** @return array{transport: string, credentials: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Credentialed read clients cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Credentialed read clients cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Credentialed read clients cannot be unserialized.');
    }
}
