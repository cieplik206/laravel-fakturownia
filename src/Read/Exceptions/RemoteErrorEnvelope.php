<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

final class RemoteErrorEnvelope extends FakturowniaReadException
{
    public function __construct(
        string $operation,
        public readonly ?string $remoteCode,
        ?string $providerRequestId,
    ) {
        parent::__construct(
            'Fakturownia returned an error envelope in a successful HTTP response.',
            $operation,
            200,
            $providerRequestId,
        );
    }
}
