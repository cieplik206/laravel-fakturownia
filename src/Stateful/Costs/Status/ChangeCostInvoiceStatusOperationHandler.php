<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status;

use Cieplik206\Fakturownia\Stateful\Costs\Status\Contracts\ChangeCostInvoiceStatusTransport;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationFailure;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use InvalidArgumentException;

final readonly class ChangeCostInvoiceStatusOperationHandler implements OperationHandler
{
    public function __construct(
        private ChangeCostInvoiceStatusTransport $transport,
        private ChangeCostInvoiceStatusPayloadCodec $codec = new ChangeCostInvoiceStatusPayloadCodec,
    ) {}

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== ChangeCostInvoiceStatusOperationFactory::OperationType) {
            throw new InvalidArgumentException('Cost invoice status handler received an unsupported operation.');
        }

        $command = $this->codec->decode($operation->payload());

        if (! $command->connectionKey->equals($operation->scope()->connection)) {
            throw new InvalidArgumentException('Cost invoice status payload connection does not match the operation scope.');
        }

        $result = $this->transport->change(
            $command->connectionKey,
            $command->remoteId,
            $command->targetStatus,
            $operation->effectBoundary(),
        );

        if (! hash_equals($command->remoteId, $result->remoteId)
            || ! hash_equals($command->targetStatus->raw, $result->status->raw)) {
            throw IssueInvoiceOperationFailure::manualReviewRequired();
        }

        return new ExecutionOutcome($result);
    }
}
