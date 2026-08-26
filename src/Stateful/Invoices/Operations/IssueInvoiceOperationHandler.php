<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceValidationProfile;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoicePayloadMapper;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\Contracts\IssueInvoiceTransport;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use InvalidArgumentException;

final readonly class IssueInvoiceOperationHandler implements OperationHandler
{
    public function __construct(
        private IssueInvoiceTransport $transport,
        private IssueInvoicePayloadCodec $codec = new IssueInvoicePayloadCodec,
        private IssueInvoicePayloadMapper $mapper = new IssueInvoicePayloadMapper,
    ) {}

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== IssueInvoiceOperationDefinitionProvider::OperationType) {
            throw new InvalidArgumentException('Issue invoice handler received an unsupported operation scope.');
        }

        $command = $this->codec->decode($operation->payload());

        if (! $command->identity->scope->connection->equals($operation->scope()->connection)) {
            throw new InvalidArgumentException('Issue invoice payload connection does not match the operation scope.');
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
