<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Proformas\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\Contracts\IssueProformaTransport;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaRequestPayloadMapper;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use InvalidArgumentException;

final readonly class IssueProformaOperationHandler implements OperationHandler
{
    public function __construct(
        private IssueProformaTransport $transport,
        private IssueProformaPayloadCodec $codec = new IssueProformaPayloadCodec,
        private ProformaRequestPayloadMapper $mapper = new ProformaRequestPayloadMapper,
    ) {}

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== IssueProformaOperationFactory::OperationType) {
            throw new InvalidArgumentException('Issue proforma handler received an unsupported operation scope.');
        }

        $command = $this->codec->decode($operation->payload());

        if (! $command->identity->scope->connection->equals($operation->scope()->connection)) {
            throw new InvalidArgumentException('Issue proforma payload connection does not match the operation scope.');
        }

        $issued = $this->transport->issue(
            $operation->scope()->connection,
            $this->mapper->map($command->draft, $command->identity),
            $operation->effectBoundary(),
        );

        return new ExecutionOutcome(IssueInvoiceResult::fromIssuedInvoiceResult($issued));
    }
}
