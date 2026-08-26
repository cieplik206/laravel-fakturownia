<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Testing\Read;

use Cieplik206\Fakturownia\Read\Exceptions\TransportFailed;
use Cieplik206\Fakturownia\Read\Requests\StreamReadRequest;
use Cieplik206\Fakturownia\Read\Responses\StreamReadResponse;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;
use InvalidArgumentException;
use LogicException;

final readonly class LiteralStreamExchange
{
    private function __construct(
        private string $expectedFingerprint,
        private ?int $statusCode,
        private ReadHeaders $headers,
        private string $body,
        private int $chunkBytes,
        private int $redirectCount,
        private bool $crossHostRedirected,
        private bool $credentialsStrippedOnRedirect,
        private bool $transportFailure,
    ) {
        if (strlen($body) > 52_428_800) {
            throw new InvalidArgumentException('The literal stream body exceeds the hard test limit.');
        }
    }

    /** @param array<string, string|list<string>> $headers */
    public static function response(
        StreamReadRequest $expectedRequest,
        int $statusCode,
        array $headers,
        string $body,
        int $chunkBytes = 8192,
        int $redirectCount = 0,
        bool $crossHostRedirected = false,
        bool $credentialsStrippedOnRedirect = true,
    ): self {
        return new self(
            $expectedRequest->fingerprint(),
            $statusCode,
            new ReadHeaders($headers),
            $body,
            $chunkBytes,
            $redirectCount,
            $crossHostRedirected,
            $credentialsStrippedOnRedirect,
            false,
        );
    }

    public static function transportFailure(StreamReadRequest $expectedRequest): self
    {
        return new self(
            $expectedRequest->fingerprint(),
            null,
            new ReadHeaders,
            '',
            8192,
            0,
            false,
            true,
            true,
        );
    }

    public function dispatch(StreamReadRequest $request): StreamReadResponse
    {
        if ($request->fingerprint() !== $this->expectedFingerprint) {
            throw new LogicException('The literal stream exchange did not match the request descriptor.');
        }

        if ($this->transportFailure) {
            throw new TransportFailed($request->operation());
        }

        if ($this->statusCode === null) {
            throw new LogicException('The literal stream exchange has no response status.');
        }

        return new StreamReadResponse(
            $this->statusCode,
            $this->headers->all(),
            new LiteralReadBodyStream($this->body, $this->chunkBytes),
            $this->redirectCount,
            $this->crossHostRedirected,
            $this->credentialsStrippedOnRedirect,
        );
    }
}
