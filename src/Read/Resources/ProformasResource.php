<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Resources;

use Cieplik206\Fakturownia\Client\Attributes\RequiresCapability;
use Cieplik206\Fakturownia\Read\Data\ProformaListQuery;
use Cieplik206\Fakturownia\Read\Data\ProformaResponseData;
use Cieplik206\Fakturownia\Read\Data\ReadPage;
use Cieplik206\Fakturownia\Read\Exceptions\PaginationLimitReached;
use Cieplik206\Fakturownia\Read\Requests\GetProformaRequest;
use Cieplik206\Fakturownia\Read\Requests\ListProformasRequest;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use Generator;

final readonly class ProformasResource extends ReadResource
{
    #[RequiresCapability('invoice.read.get', GetProformaRequest::class)]
    public function get(string $proformaId): ProformaResponseData
    {
        $request = new GetProformaRequest($proformaId);
        $response = $this->execute($request);

        return ProformaResponseData::fromPayload(
            $this->decoder->object($request, $response),
            $request->operation(),
        );
    }

    /** @return ReadPage<ProformaResponseData> */
    #[RequiresCapability('invoice.read.list', ListProformasRequest::class)]
    public function list(ProformaListQuery $query = new ProformaListQuery): ReadPage
    {
        $request = new ListProformasRequest($query);
        $response = $this->execute($request);
        $payloads = $this->decoder->list($request, $response);
        $this->assertRemotePageSize($request, $response, count($payloads), $query->pagination->perPage);
        $items = array_map(
            static fn (array $payload): ProformaResponseData => ProformaResponseData::fromPayload(
                $payload,
                $request->operation(),
            ),
            $payloads,
        );

        return new ReadPage(
            $query->pagination->page,
            $query->pagination->perPage,
            $items,
            $response->headers->providerRequestId(),
        );
    }

    /** @return iterable<ProformaResponseData> */
    #[RequiresCapability('invoice.read.list', ListProformasRequest::class)]
    public function stream(
        ProformaListQuery $query = new ProformaListQuery,
        int $maximumPages = Pagination::MaximumStreamPages,
    ): iterable {
        Pagination::assertMaximumPages($maximumPages);
        $this->capabilities->assertSupported((new ListProformasRequest($query))->capability());

        return $this->iterate($query, $maximumPages);
    }

    /** @return Generator<int, ProformaResponseData> */
    private function iterate(ProformaListQuery $query, int $maximumPages): Generator
    {
        $seenPages = [];
        $seenRemoteIds = [];

        for ($offset = 0; $offset < $maximumPages; $offset++) {
            $page = $this->list($query->withPage($query->pagination->page + $offset));
            $items = $page->items();
            $signature = hash('sha256', implode("\0", array_map(
                static fn (ProformaResponseData $proforma): string => $proforma->snapshot->remoteId,
                $items,
            )));

            if (isset($seenPages[$signature])) {
                return;
            }

            $seenPages[$signature] = true;
            $newItems = 0;

            foreach ($items as $proforma) {
                $remoteId = $proforma->snapshot->remoteId;

                if (isset($seenRemoteIds[$remoteId])) {
                    continue;
                }

                $seenRemoteIds[$remoteId] = true;
                $newItems++;

                yield $proforma;
            }

            if ($page->isTerminal() || $newItems === 0) {
                return;
            }
        }

        throw new PaginationLimitReached('invoice.read.list', $maximumPages);
    }
}
