<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

final class ArtifactValidationFailed extends FakturowniaReadException
{
    public function __construct(string $operation, string $safeReason, ?string $providerRequestId = null)
    {
        parent::__construct("The Fakturownia artifact failed {$safeReason} validation.", $operation, providerRequestId: $providerRequestId);
    }
}
