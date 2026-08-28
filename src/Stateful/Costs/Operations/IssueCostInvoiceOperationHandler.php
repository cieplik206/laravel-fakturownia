<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Operations;

use Cieplik206\Fakturownia\Stateful\Costs\Operations\Contracts\IssueCostInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceValidationProfile;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoicePayloadMapper;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use InvalidArgumentException;

final readonly class IssueCostInvoiceOperationHandler implements OperationHandler
{
    public function __construct(
        private IssueCostInvoiceTransport $transport,
        private IssueCostInvoicePayloadCodec $codec = new IssueCostInvoicePayloadCodec,
        private IssueInvoicePayloadMapper $mapper = new IssueInvoicePayloadMapper,
    ) {}

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== IssueCostInvoiceOperationFactory::OperationType) {
            throw new InvalidArgumentException('Issue cost invoice handler received an unsupported operation scope.');
        }

        $command = $this->codec->decode($operation->payload());

        if (! $command->identity->scope->connection->equals($operation->scope()->connection)) {
            throw new InvalidArgumentException('Issue cost invoice payload connection does not match the operation scope.');
        }

        $payload = $this->mapper->map(
            $command->draft,
            InvoiceValidationProfile::Standard,
            $command->identity,
        );
        $issued = $this->transport->issue(
            $operation->scope()->connection,
            $payload,
            $operation->effectBoundary(),
        );

        return new ExecutionOutcome(IssueInvoiceResult::fromIssuedInvoiceResult($issued));
    }
}
