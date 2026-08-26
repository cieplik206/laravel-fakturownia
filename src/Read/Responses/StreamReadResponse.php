<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Responses;

use Cieplik206\Fakturownia\Read\Contracts\ReadBodyStream;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;
use InvalidArgumentException;

final readonly class StreamReadResponse
{
    public ReadHeaders $headers;

    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public int $statusCode,
        array $headers,
        public ReadBodyStream $body,
        public int $redirectCount = 0,
        public bool $crossHostRedirected = false,
        public bool $credentialsStrippedOnRedirect = true,
    ) {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('The response status code is invalid.');
        }

        if ($redirectCount < 0 || $redirectCount > 10) {
            throw new InvalidArgumentException('The response redirect count is invalid.');
        }

        $this->headers = new ReadHeaders($headers);
    }
}
