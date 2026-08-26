<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

final class RateLimited extends FakturowniaReadException
{
    public function __construct(
        string $operation,
        int $statusCode,
        ?string $providerRequestId,
        public readonly ?int $retryAfterMilliseconds,
    ) {
        parent::__construct('Fakturownia rate-limited the read request.', $operation, $statusCode, $providerRequestId);
    }
}
