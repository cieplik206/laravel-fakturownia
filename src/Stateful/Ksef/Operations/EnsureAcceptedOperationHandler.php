<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Stateful\Ksef\KsefOwnership;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefSendTransport;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use InvalidArgumentException;

final readonly class EnsureAcceptedOperationHandler implements OperationHandler
{
    public function __construct(
        private KsefSendTransport $transport,
    ) {}

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== EnsureAcceptedOperationDefinitionProvider::OperationType) {
            throw new InvalidArgumentException('The KSeF handler received an unsupported operation.');
        }

        $command = (new EnsureAcceptedPayloadCodec)->decode($operation->payload());

        if (! $command->connectionKey->equals($operation->scope()->connection)
            || $command->profile->ownership !== KsefOwnership::ExplicitSdk) {
            throw new InvalidArgumentException('Only an explicit KSeF profile may enter the send handler.');
        }

        $this->transport->transmitOnce(
            $command->connectionKey,
            $command->remoteId,
            $operation->effectBoundary(),
        );

        return ExecutionOutcome::awaitPolling(new EnsureAcceptedAwaitingObservation);
    }
}
