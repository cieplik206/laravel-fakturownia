<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Resources;

use Cieplik206\Fakturownia\Client\Attributes\RequiresCapability;
use Cieplik206\Fakturownia\Read\Data\ClientListQuery;
use Cieplik206\Fakturownia\Read\Data\ClientResponseData;
use Cieplik206\Fakturownia\Read\Data\ReadPage;
use Cieplik206\Fakturownia\Read\Exceptions\PaginationLimitReached;
use Cieplik206\Fakturownia\Read\Requests\GetClientRequest;
use Cieplik206\Fakturownia\Read\Requests\ListClientsRequest;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use Generator;

final readonly class ClientsResource extends ReadResource
{
    #[RequiresCapability('client.read.get', GetClientRequest::class)]
    public function get(string $clientId): ClientResponseData
    {
        $request = new GetClientRequest($clientId);
        $response = $this->execute($request);

        return ClientResponseData::fromPayload(
            $this->decoder->object($request, $response),
            $request->operation(),
        );
    }

    /** @return ReadPage<ClientResponseData> */
    #[RequiresCapability('client.read.list', ListClientsRequest::class)]
    public function list(ClientListQuery $query = new ClientListQuery): ReadPage
    {
        $request = new ListClientsRequest($query);
        $response = $this->execute($request);
        $payloads = $this->decoder->list($request, $response);
        $this->assertRemotePageSize($request, $response, count($payloads), $query->pagination->perPage);
        $items = array_map(
            static fn (array $payload): ClientResponseData => ClientResponseData::fromPayload($payload, $request->operation()),
            $payloads,
        );

        return new ReadPage(
            $query->pagination->page,
            $query->pagination->perPage,
            $items,
            $response->headers->providerRequestId(),
        );
    }

    /** @return iterable<ClientResponseData> */
    #[RequiresCapability('client.read.list', ListClientsRequest::class)]
    public function stream(ClientListQuery $query = new ClientListQuery, int $maximumPages = Pagination::MaximumStreamPages): iterable
    {
        Pagination::assertMaximumPages($maximumPages);
        $this->capabilities->assertSupported((new ListClientsRequest($query))->capability());

        return $this->iterate($query, $maximumPages);
    }

    /** @return Generator<int, ClientResponseData> */
    private function iterate(ClientListQuery $query, int $maximumPages): Generator
    {
        $seenPages = [];
        $seenRemoteIds = [];

        for ($offset = 0; $offset < $maximumPages; $offset++) {
            $page = $this->list($query->withPage($query->pagination->page + $offset));
            $items = $page->items();
            $signature = hash('sha256', implode("\0", array_map(
                static fn (ClientResponseData $client): string => $client->remoteId,
                $items,
            )));

            if (isset($seenPages[$signature])) {
                return;
            }

            $seenPages[$signature] = true;
            $newItems = 0;

            foreach ($items as $client) {
                if (isset($seenRemoteIds[$client->remoteId])) {
                    continue;
                }

                $seenRemoteIds[$client->remoteId] = true;
                $newItems++;

                yield $client;
            }

            if ($page->isTerminal() || $newItems === 0) {
                return;
            }
        }

        throw new PaginationLimitReached('client.read.list', $maximumPages);
    }
}
