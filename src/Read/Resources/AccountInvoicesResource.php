<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Resources;

use Cieplik206\Fakturownia\Client\Attributes\RequiresCapability;
use Cieplik206\Fakturownia\Read\Administration\AdministrationReadScope;
use Cieplik206\Fakturownia\Read\Data\AccountInvoiceListQuery;
use Cieplik206\Fakturownia\Read\Data\AccountInvoiceReadPage;
use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;
use Cieplik206\Fakturownia\Read\Requests\ListAccountInvoicesRequest;

final readonly class AccountInvoicesResource extends ReadResource
{
    #[RequiresCapability('account.invoice.read', ListAccountInvoicesRequest::class)]
    public function list(
        AdministrationReadScope $scope,
        AccountInvoiceListQuery $query = new AccountInvoiceListQuery,
    ): AccountInvoiceReadPage {
        $request = new ListAccountInvoicesRequest($query);
        $response = $this->execute($request);
        $payloads = $this->decoder->list($request, $response);
        $this->assertRemotePageSize($request, $response, count($payloads), $query->pagination->perPage);
        $items = array_map(
            static fn (array $payload): InvoiceResponseData => InvoiceResponseData::fromPayload($payload, $request->operation()),
            $payloads,
        );

        return new AccountInvoiceReadPage(
            scopeFingerprint: $scope->fingerprint(),
            number: $query->pagination->page,
            perPage: $query->pagination->perPage,
            items: $items,
            requestFingerprint: $this->scopeBoundRequestFingerprint($scope, $request),
            providerRequestId: $response->headers->providerRequestId(),
        );
    }

    private function scopeBoundRequestFingerprint(
        AdministrationReadScope $scope,
        ListAccountInvoicesRequest $request,
    ): string {
        return hash('sha256', $scope->fingerprint()."\0".$request->fingerprint());
    }
}
