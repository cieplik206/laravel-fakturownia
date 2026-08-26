<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Testing\Read;

use Cieplik206\Fakturownia\Read\Contracts\ReadRequestDescriptor;
use Cieplik206\Fakturownia\Read\Contracts\ReadRequestExecutor;
use Cieplik206\Fakturownia\Read\Exceptions\TransportFailed;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Requests\StreamReadRequest;
use Cieplik206\Fakturownia\Read\Responses\JsonReadResponse;
use Cieplik206\Fakturownia\Read\Responses\StreamReadResponse;
use InvalidArgumentException;
use LogicException;
use ReflectionReference;

final class LiteralReadRequestExecutor implements ReadRequestExecutor
{
    /** @var list<LiteralJsonExchange|LiteralStreamExchange> */
    private array $exchanges;

    /** @var list<ReadRequestDescriptor> */
    private array $requests = [];

    /** @param array<array-key, mixed> $exchanges */
    public function __construct(array $exchanges)
    {
        $normalized = [];

        foreach ($exchanges as $key => $exchange) {
            if (ReflectionReference::fromArrayElement($exchanges, $key) !== null) {
                throw new InvalidArgumentException('The literal exchange queue must not contain references.');
            }

            if (! $exchange instanceof LiteralJsonExchange && ! $exchange instanceof LiteralStreamExchange) {
                throw new InvalidArgumentException('The literal exchange queue contains an invalid value.');
            }

            $normalized[] = $exchange;
        }

        $this->exchanges = $normalized;
    }

    public function execute(JsonReadRequest $request): JsonReadResponse
    {
        $exchange = $this->exchanges[0] ?? null;

        if (! $exchange instanceof LiteralJsonExchange) {
            throw new LogicException('The next literal exchange is not a JSON response.');
        }

        try {
            $response = $exchange->dispatch($request);
        } catch (TransportFailed $exception) {
            array_shift($this->exchanges);
            $this->requests[] = $request;

            throw $exception;
        }

        array_shift($this->exchanges);
        $this->requests[] = $request;

        return $response;
    }

    public function stream(StreamReadRequest $request): StreamReadResponse
    {
        $exchange = $this->exchanges[0] ?? null;

        if (! $exchange instanceof LiteralStreamExchange) {
            throw new LogicException('The next literal exchange is not a stream response.');
        }

        try {
            $response = $exchange->dispatch($request);
        } catch (TransportFailed $exception) {
            array_shift($this->exchanges);
            $this->requests[] = $request;

            throw $exception;
        }

        array_shift($this->exchanges);
        $this->requests[] = $request;

        return $response;
    }

    /** @return list<ReadRequestDescriptor> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function remainingExchanges(): int
    {
        return count($this->exchanges);
    }

    public function assertExhausted(): void
    {
        if ($this->exchanges !== []) {
            throw new LogicException('The literal read executor still has queued exchanges.');
        }
    }
}
