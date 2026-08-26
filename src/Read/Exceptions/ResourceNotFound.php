<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

final class ResourceNotFound extends FakturowniaReadException
{
    public function __construct(string $operation, int $statusCode, ?string $providerRequestId)
    {
        parent::__construct('The requested Fakturownia resource was not found.', $operation, $statusCode, $providerRequestId);
    }
}
