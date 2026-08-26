<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Data\ClientListQuery;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class ListClientsRequest extends JsonReadRequest
{
    public function __construct(ClientListQuery $query)
    {
        parent::__construct(
            ReadCapability::ClientList->value,
            ReadCapability::ClientList,
            '/clients.json',
            new QueryParameters($query->toQuery()),
        );
    }
}
