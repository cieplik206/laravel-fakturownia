<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

final class RemoteReadFailed extends FakturowniaReadException
{
    public function __construct(string $operation, int $statusCode, ?string $providerRequestId)
    {
        parent::__construct('Fakturownia returned an unsuccessful read response.', $operation, $statusCode, $providerRequestId);
    }
}
