<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Testing\Read;

use Cieplik206\Fakturownia\Read\Exceptions\TransportFailed;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Responses\JsonReadResponse;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;
use LogicException;

final readonly class LiteralJsonExchange
{
    private function __construct(
        private string $expectedFingerprint,
        private ?int $statusCode,
        private ReadHeaders $headers,
        private string $body,
        private bool $transportFailure,
    ) {}

    /** @param array<string, string|list<string>> $headers */
    public static function response(
        JsonReadRequest $expectedRequest,
        int $statusCode,
        array $headers,
        string $body,
    ): self {
        return new self(
            $expectedRequest->fingerprint(),
            $statusCode,
            new ReadHeaders($headers),
            $body,
            false,
        );
    }

    public static function transportFailure(JsonReadRequest $expectedRequest): self
    {
        return new self(
            $expectedRequest->fingerprint(),
            null,
            new ReadHeaders,
            '',
            true,
        );
    }

    public function dispatch(JsonReadRequest $request): JsonReadResponse
    {
        if ($request->fingerprint() !== $this->expectedFingerprint) {
            throw new LogicException('The literal JSON exchange did not match the request descriptor.');
        }

        if ($this->transportFailure) {
            throw new TransportFailed($request->operation());
        }

        if ($this->statusCode === null) {
            throw new LogicException('The literal JSON exchange has no response status.');
        }

        return new JsonReadResponse($this->statusCode, $this->headers->all(), $this->body);
    }
}
