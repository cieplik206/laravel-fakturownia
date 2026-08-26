<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Responses;

use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;
use InvalidArgumentException;

final readonly class JsonReadResponse
{
    private const HardMaximumBodyBytes = 16_777_216;

    public ReadHeaders $headers;

    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public int $statusCode,
        array $headers,
        private string $body,
    ) {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('The response status code is invalid.');
        }

        if (strlen($body) > self::HardMaximumBodyBytes) {
            throw new InvalidArgumentException('The response exceeds the hard JSON body limit.');
        }

        $this->headers = new ReadHeaders($headers);
    }

    public function body(JsonReadRequest $request): string
    {
        if (strlen($this->body) > $request->maximumResponseBytes()) {
            throw new ProtocolViolation(
                $request->operation(),
                'maximum JSON body size',
                $this->statusCode,
                $this->headers->providerRequestId(),
            );
        }

        return $this->body;
    }
}
