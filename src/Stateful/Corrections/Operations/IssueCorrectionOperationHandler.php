<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\Fakturownia\Stateful\Corrections\IssueCorrectionPayloadMapper;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\Contracts\IssueCorrectionTransport;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use InvalidArgumentException;

final readonly class IssueCorrectionOperationHandler implements OperationHandler
{
    public function __construct(
        private IssueCorrectionTransport $transport,
        private IssueCorrectionPayloadCodec $codec = new IssueCorrectionPayloadCodec,
        private IssueCorrectionPayloadMapper $mapper = new IssueCorrectionPayloadMapper,
    ) {}

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== IssueCorrectionOperationDefinitionProvider::OperationType) {
            throw new InvalidArgumentException('Issue correction handler received an unsupported operation scope.');
        }

        $command = $this->codec->decode($operation->payload());

        if (! $command->identity->scope->connection->equals($operation->scope()->connection)) {
            throw new InvalidArgumentException('Issue correction payload connection does not match the operation scope.');
        }

        $issued = $this->transport->issue(
            $operation->scope()->connection,
            $this->mapper->map($command->draft, $command->identity),
            $operation->effectBoundary(),
        );

        return new ExecutionOutcome(IssueCorrectionResult::fromIssuedCorrectionResult($issued));
    }
}
