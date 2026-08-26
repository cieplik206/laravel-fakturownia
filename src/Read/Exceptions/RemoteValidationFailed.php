<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

final class RemoteValidationFailed extends FakturowniaReadException
{
    public function __construct(string $operation, int $statusCode, ?string $providerRequestId)
    {
        parent::__construct('Fakturownia rejected the read parameters.', $operation, $statusCode, $providerRequestId);
    }
}
