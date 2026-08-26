<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Resources;

use Cieplik206\Fakturownia\Client\Attributes\RequiresCapability;
use Cieplik206\Fakturownia\Read\Data\ProductListQuery;
use Cieplik206\Fakturownia\Read\Data\ProductResponseData;
use Cieplik206\Fakturownia\Read\Data\ReadPage;
use Cieplik206\Fakturownia\Read\Exceptions\PaginationLimitReached;
use Cieplik206\Fakturownia\Read\Requests\GetProductRequest;
use Cieplik206\Fakturownia\Read\Requests\ListProductsRequest;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use Generator;

final readonly class ProductsResource extends ReadResource
{
    #[RequiresCapability('product.read.get', GetProductRequest::class)]
    public function get(string $productId): ProductResponseData
    {
        $request = new GetProductRequest($productId);
        $response = $this->execute($request);

        return ProductResponseData::fromPayload(
            $this->decoder->object($request, $response),
            $request->operation(),
        );
    }

    /** @return ReadPage<ProductResponseData> */
    #[RequiresCapability('product.read.list', ListProductsRequest::class)]
    public function list(ProductListQuery $query = new ProductListQuery): ReadPage
    {
        $request = new ListProductsRequest($query);
        $response = $this->execute($request);
        $payloads = $this->decoder->list($request, $response);
        $this->assertRemotePageSize($request, $response, count($payloads), $query->pagination->perPage);
        $items = array_map(
            static fn (array $payload): ProductResponseData => ProductResponseData::fromPayload($payload, $request->operation()),
            $payloads,
        );

        return new ReadPage(
            $query->pagination->page,
            $query->pagination->perPage,
            $items,
            $response->headers->providerRequestId(),
        );
    }

    /** @return iterable<ProductResponseData> */
    #[RequiresCapability('product.read.list', ListProductsRequest::class)]
    public function stream(ProductListQuery $query = new ProductListQuery, int $maximumPages = Pagination::MaximumStreamPages): iterable
    {
        Pagination::assertMaximumPages($maximumPages);
        $this->capabilities->assertSupported((new ListProductsRequest($query))->capability());

        return $this->iterate($query, $maximumPages);
    }

    /** @return Generator<int, ProductResponseData> */
    private function iterate(ProductListQuery $query, int $maximumPages): Generator
    {
        $seenPages = [];
        $seenRemoteIds = [];

        for ($offset = 0; $offset < $maximumPages; $offset++) {
            $page = $this->list($query->withPage($query->pagination->page + $offset));
            $items = $page->items();
            $signature = hash('sha256', implode("\0", array_map(
                static fn (ProductResponseData $product): string => $product->remoteId,
                $items,
            )));

            if (isset($seenPages[$signature])) {
                return;
            }

            $seenPages[$signature] = true;
            $newItems = 0;

            foreach ($items as $product) {
                if (isset($seenRemoteIds[$product->remoteId])) {
                    continue;
                }

                $seenRemoteIds[$product->remoteId] = true;
                $newItems++;

                yield $product;
            }

            if ($page->isTerminal() || $newItems === 0) {
                return;
            }
        }

        throw new PaginationLimitReached('product.read.list', $maximumPages);
    }
}
