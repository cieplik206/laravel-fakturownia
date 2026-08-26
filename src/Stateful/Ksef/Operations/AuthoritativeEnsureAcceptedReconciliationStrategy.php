<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Read\Exceptions\AuthenticationFailed;
use Cieplik206\Fakturownia\Read\Exceptions\FakturowniaReadException;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Exceptions\ResourceNotFound;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefInvoiceObservationReader;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;

final readonly class AuthoritativeEnsureAcceptedReconciliationStrategy implements AuthoritativeReconciliationStrategy
{
    public function __construct(
        private KsefInvoiceObservationReader $reader,
    ) {}

    public function reconcile(
        AuthoritativeReconciliationContext $context,
    ): AuthoritativeReconciliationOutcome {
        $command = (new EnsureAcceptedPayloadCodec)->decode($context->payload());

        if ($context->scope()->provider->value !== 'fakturownia'
            || $context->operationType()->value !== EnsureAcceptedOperationDefinitionProvider::OperationType
            || ! $command->connectionKey->equals($context->scope()->connection)) {
            return AuthoritativeReconciliationOutcome::inconclusive(
                'fakturownia.ksef.operation_scope_mismatch',
            );
        }

        try {
            $observation = $this->reader->observe($command->connectionKey, $command->remoteId);
        } catch (AuthenticationFailed|ResourceNotFound|ProtocolViolation) {
            return AuthoritativeReconciliationOutcome::ambiguousMatches(
                $this->manualFailure(),
                'fakturownia.ksef.reconciliation_blocked',
            );
        } catch (FakturowniaReadException) {
            return AuthoritativeReconciliationOutcome::inconclusive(
                'fakturownia.ksef.read_temporarily_unavailable',
            );
        }

        if (! hash_equals($observation->remoteId, $command->remoteId)) {
            return AuthoritativeReconciliationOutcome::ambiguousMatches(
                $this->manualFailure(),
                'fakturownia.ksef.remote_identity_mismatch',
                $observation->canonical(),
            );
        }

        if ($observation->isAccepted()) {
            return AuthoritativeReconciliationOutcome::foundExact(
                $observation->acceptedResult(),
                'fakturownia.ksef.accepted_after_reconciliation',
                $observation->canonical(),
            );
        }

        if ($observation->isRejected()) {
            return AuthoritativeReconciliationOutcome::providerRejected(
                $observation->rejectedResult(),
                new SafeOperationFailure(
                    'fakturownia_ksef_provider_rejected',
                    'Fakturownia reported a terminal KSeF rejection or not-applicable result.',
                ),
                'fakturownia.ksef.rejected_after_reconciliation',
                $observation->canonical(),
            );
        }

        if ($observation->requiresConfigurationBlock()) {
            return AuthoritativeReconciliationOutcome::ambiguousMatches(
                $this->manualFailure(),
                'fakturownia.ksef.connection_blocked',
                $observation->canonical(),
            );
        }

        if ($observation->provesSendStarted()) {
            return AuthoritativeReconciliationOutcome::appliedInProgress(
                'fakturownia.ksef.send_started_nonterminal',
                $observation->canonical(),
            );
        }

        return AuthoritativeReconciliationOutcome::inconclusive(
            'fakturownia.ksef.send_not_confirmed',
            $observation->canonical(),
        );
    }

    private function manualFailure(): SafeOperationFailure
    {
        return new SafeOperationFailure(
            'fakturownia_ksef_manual_review',
            'The KSeF operation requires operator review and will not send again.',
        );
    }
}
