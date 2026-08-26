<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

final class RemoteServerFailed extends FakturowniaReadException
{
    public function __construct(string $operation, int $statusCode, ?string $providerRequestId)
    {
        parent::__construct('Fakturownia failed while serving the read request.', $operation, $statusCode, $providerRequestId);
    }
}
