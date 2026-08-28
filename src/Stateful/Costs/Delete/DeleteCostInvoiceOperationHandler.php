<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\Fakturownia\Stateful\Costs\Delete\Contracts\DeleteCostInvoiceTransport;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use InvalidArgumentException;

final readonly class DeleteCostInvoiceOperationHandler implements OperationHandler
{
    public function __construct(
        private DeleteCostInvoiceTransport $transport,
        private DeleteCostInvoicePayloadCodec $codec = new DeleteCostInvoicePayloadCodec,
    ) {}

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== DeleteCostInvoiceOperationFactory::OperationType) {
            throw new InvalidArgumentException('Cost invoice delete handler received an unsupported operation.');
        }

        $command = $this->codec->decode($operation->payload());

        if (! $command->connectionKey->equals($operation->scope()->connection)) {
            throw new InvalidArgumentException('Cost invoice delete payload connection does not match the operation scope.');
        }

        $result = $this->transport->delete(
            $command->connectionKey,
            $command->remoteId,
            $operation->effectBoundary(),
        );

        if (! hash_equals($command->remoteId, $result->remoteId)) {
            throw DeleteCostInvoiceOperationFailure::manualReviewRequired();
        }

        return new ExecutionOutcome($result);
    }
}
