<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

final class ProtocolViolation extends FakturowniaReadException
{
    public function __construct(
        string $operation,
        string $safeReason,
        ?int $statusCode = null,
        ?string $providerRequestId = null,
    ) {
        parent::__construct("The Fakturownia response violated the {$safeReason} contract.", $operation, $statusCode, $providerRequestId);
    }
}
