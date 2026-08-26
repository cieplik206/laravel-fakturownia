<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Resources;

use Cieplik206\Fakturownia\Client\Attributes\RequiresCapability;
use Cieplik206\Fakturownia\Read\Data\ExactOidInvoiceQuery;
use Cieplik206\Fakturownia\Read\Data\InvoiceListQuery;
use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;
use Cieplik206\Fakturownia\Read\Data\ReadPage;
use Cieplik206\Fakturownia\Read\Exceptions\PaginationLimitReached;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoiceAttachmentsZipRequest;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoiceKsefUpoRequest;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoiceKsefXmlRequest;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoicePdfRequest;
use Cieplik206\Fakturownia\Read\Requests\FindInvoicesByExactOidRequest;
use Cieplik206\Fakturownia\Read\Requests\GetInvoiceRequest;
use Cieplik206\Fakturownia\Read\Requests\ListInvoicesRequest;
use Cieplik206\Fakturownia\Read\Responses\ReadArtifactStream;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use Generator;

final readonly class InvoicesResource extends ReadResource
{
    #[RequiresCapability('invoice.read.get', GetInvoiceRequest::class)]
    public function get(string $invoiceId): InvoiceResponseData
    {
        $request = new GetInvoiceRequest($invoiceId);
        $response = $this->execute($request);

        return InvoiceResponseData::fromPayload(
            $this->decoder->object($request, $response),
            $request->operation(),
        );
    }

    /** @return ReadPage<InvoiceResponseData> */
    #[RequiresCapability('invoice.read.list', ListInvoicesRequest::class)]
    public function list(InvoiceListQuery $query = new InvoiceListQuery): ReadPage
    {
        $request = new ListInvoicesRequest($query);
        $response = $this->execute($request);
        $payloads = $this->decoder->list($request, $response);
        $this->assertRemotePageSize($request, $response, count($payloads), $query->pagination->perPage);
        $items = array_map(
            static fn (array $payload): InvoiceResponseData => InvoiceResponseData::fromPayload($payload, $request->operation()),
            $payloads,
        );

        return new ReadPage(
            $query->pagination->page,
            $query->pagination->perPage,
            $items,
            $response->headers->providerRequestId(),
        );
    }

    /** @return ReadPage<InvoiceResponseData> */
    #[RequiresCapability('invoice.read.list', FindInvoicesByExactOidRequest::class)]
    public function listByExactOid(ExactOidInvoiceQuery $query): ReadPage
    {
        $request = new FindInvoicesByExactOidRequest($query);
        $response = $this->execute($request);
        $payloads = $this->decoder->list($request, $response);
        $this->assertRemotePageSize($request, $response, count($payloads), $query->pagination->perPage);
        $items = array_map(
            static fn (array $payload): InvoiceResponseData => InvoiceResponseData::fromPayload($payload, $request->operation()),
            $payloads,
        );

        return new ReadPage(
            $query->pagination->page,
            $query->pagination->perPage,
            $items,
            $response->headers->providerRequestId(),
        );
    }

    /** @return iterable<InvoiceResponseData> */
    #[RequiresCapability('invoice.read.list', ListInvoicesRequest::class)]
    public function stream(InvoiceListQuery $query = new InvoiceListQuery, int $maximumPages = Pagination::MaximumStreamPages): iterable
    {
        Pagination::assertMaximumPages($maximumPages);
        $this->capabilities->assertSupported((new ListInvoicesRequest($query))->capability());

        return $this->iterate($query, $maximumPages);
    }

    /** @return iterable<InvoiceResponseData> */
    #[RequiresCapability('invoice.read.list', FindInvoicesByExactOidRequest::class)]
    public function streamByExactOid(
        ExactOidInvoiceQuery $query,
        int $maximumPages = Pagination::MaximumStreamPages,
    ): iterable {
        $this->assertExactOidScanBounds($query, $maximumPages);
        $this->capabilities->assertSupported((new FindInvoicesByExactOidRequest($query))->capability());

        return $this->iterateByExactOid($query, $maximumPages);
    }

    #[RequiresCapability('invoice.pdf.stream', DownloadInvoicePdfRequest::class)]
    public function pdf(string $invoiceId): ReadArtifactStream
    {
        return $this->artifact(new DownloadInvoicePdfRequest($invoiceId));
    }

    #[RequiresCapability('invoice.attachments.zip.stream', DownloadInvoiceAttachmentsZipRequest::class)]
    public function attachmentsZip(string $invoiceId): ReadArtifactStream
    {
        return $this->artifact(new DownloadInvoiceAttachmentsZipRequest($invoiceId));
    }

    #[RequiresCapability('invoice.ksef.xml.stream', DownloadInvoiceKsefXmlRequest::class)]
    public function ksefXml(string $invoiceId): ReadArtifactStream
    {
        return $this->artifact(new DownloadInvoiceKsefXmlRequest($invoiceId));
    }

    #[RequiresCapability('invoice.ksef.upo.stream', DownloadInvoiceKsefUpoRequest::class)]
    public function ksefUpo(string $invoiceId): ReadArtifactStream
    {
        return $this->artifact(new DownloadInvoiceKsefUpoRequest($invoiceId));
    }

    /** @return Generator<int, InvoiceResponseData> */
    private function iterate(InvoiceListQuery $query, int $maximumPages): Generator
    {
        $seenPages = [];
        $seenRemoteIds = [];

        for ($offset = 0; $offset < $maximumPages; $offset++) {
            $page = $this->list($query->withPage($query->pagination->page + $offset));
            $items = $page->items();
            $signature = hash('sha256', implode("\0", array_map(
                static fn (InvoiceResponseData $invoice): string => $invoice->remoteId,
                $items,
            )));

            if (isset($seenPages[$signature])) {
                return;
            }

            $seenPages[$signature] = true;
            $newItems = 0;

            foreach ($items as $invoice) {
                if (isset($seenRemoteIds[$invoice->remoteId])) {
                    continue;
                }

                $seenRemoteIds[$invoice->remoteId] = true;
                $newItems++;

                yield $invoice;
            }

            if ($page->isTerminal() || $newItems === 0) {
                return;
            }
        }

        throw new PaginationLimitReached('invoice.read.list', $maximumPages);
    }

    private function assertExactOidScanBounds(ExactOidInvoiceQuery $query, int $maximumPages): void
    {
        Pagination::assertMaximumPages($maximumPages);

        if ($query->pagination->page !== 1) {
            throw new ProtocolViolation('invoice.read.list', 'complete exact OID pagination start');
        }
    }

    /** @return Generator<int, InvoiceResponseData> */
    private function iterateByExactOid(ExactOidInvoiceQuery $query, int $maximumPages): Generator
    {
        $seenPages = [];
        $seenRemoteIds = [];

        for ($pageNumber = 1; $pageNumber <= $maximumPages; $pageNumber++) {
            $page = $this->listByExactOid($query->withPage($pageNumber));
            $items = $page->items();
            $signature = hash('sha256', implode("\0", array_map(
                static fn (InvoiceResponseData $invoice): string => $invoice->remoteId,
                $items,
            )));

            if (isset($seenPages[$signature])) {
                throw new ProtocolViolation(
                    'invoice.read.list',
                    'complete exact OID pagination progress',
                    providerRequestId: $page->providerRequestId,
                );
            }

            $seenPages[$signature] = true;
            $newItems = 0;

            foreach ($items as $invoice) {
                if (isset($seenRemoteIds[$invoice->remoteId])) {
                    continue;
                }

                $seenRemoteIds[$invoice->remoteId] = true;
                $newItems++;

                yield $invoice;
            }

            if ($page->isTerminal()) {
                return;
            }

            if ($newItems === 0) {
                throw new ProtocolViolation(
                    'invoice.read.list',
                    'complete exact OID pagination progress',
                    providerRequestId: $page->providerRequestId,
                );
            }
        }

        throw new PaginationLimitReached('invoice.read.list', $maximumPages);
    }
}
