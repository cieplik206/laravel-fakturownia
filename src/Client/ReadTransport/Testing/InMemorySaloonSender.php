<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\ReadTransport\Testing;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response as PsrResponse;
use LogicException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Saloon\Contracts\Sender;
use Saloon\Data\FactoryCollection;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Http\Senders\Factories\GuzzleMultipartBodyFactory;

/**
 * @internal A literal queue with no callable, passthrough, fixture loader, socket
 * or HTTP implementation. It cannot reach a remote origin.
 */
final class InMemorySaloonSender implements Sender
{
    /** @var list<InMemorySaloonExchange> */
    private array $exchanges;

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @param list<InMemorySaloonExchange> $exchanges */
    public function __construct(array $exchanges)
    {
        $this->exchanges = $exchanges;
    }

    public function getFactoryCollection(): FactoryCollection
    {
        $factory = new HttpFactory;

        return new FactoryCollection(
            requestFactory: $factory,
            uriFactory: $factory,
            streamFactory: $factory,
            responseFactory: $factory,
            multipartBodyFactory: new GuzzleMultipartBodyFactory,
        );
    }

    public function send(PendingRequest $pendingRequest): Response
    {
        $request = $pendingRequest->createPsrRequest();
        $this->requests[] = $request;
        $exchange = array_shift($this->exchanges);

        if (! $exchange instanceof InMemorySaloonExchange) {
            throw new LogicException('The in-memory Saloon exchange queue is exhausted.');
        }

        if ($exchange->failureMessage !== null) {
            throw new RuntimeException($exchange->failureMessage);
        }

        if ($exchange->statusCode === null) {
            throw new LogicException('The in-memory Saloon exchange has no response status.');
        }

        $psrResponse = new PsrResponse($exchange->statusCode, $exchange->headers, $exchange->body);
        $responseClass = $pendingRequest->getResponseClass();

        return $responseClass::fromPsrResponse($psrResponse, $pendingRequest, $request);
    }

    public function sendAsync(PendingRequest $pendingRequest): PromiseInterface
    {
        return new FulfilledPromise($this->send($pendingRequest));
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        return $this->requests;
    }
}
