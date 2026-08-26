<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class GetClientRequest extends JsonReadRequest
{
    public function __construct(string $clientId)
    {
        $clientId = RemoteIdentifier::assert($clientId);

        parent::__construct(
            ReadCapability::ClientGet->value,
            ReadCapability::ClientGet,
            "/clients/{$clientId}.json",
            new QueryParameters,
        );
    }
}
