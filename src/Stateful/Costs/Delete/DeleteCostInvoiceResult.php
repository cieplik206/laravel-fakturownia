<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;

final readonly class DeleteCostInvoiceResult implements OperationResult
{
    use RejectsNativeSerialization;

    public string $remoteId;

    public function __construct(string $remoteId)
    {
        $this->remoteId = RemoteIdentifier::assert($remoteId);
    }

    public function resultType(): string
    {
        return DeleteCostInvoiceResultCodec::ResultType;
    }
}
