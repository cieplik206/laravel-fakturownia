<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources\Contracts;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;

interface InvoiceResourceSnapshot extends OperationResult
{
    public function remoteId(): string;

    public function remoteNumber(): string;
}
