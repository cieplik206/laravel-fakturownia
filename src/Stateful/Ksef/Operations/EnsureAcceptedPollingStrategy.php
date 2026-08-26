<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Read\Exceptions\AuthenticationFailed;
use Cieplik206\Fakturownia\Read\Exceptions\FakturowniaReadException;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Exceptions\ResourceNotFound;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefInvoiceObservation;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefOwnership;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefStatusCategory;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefInvoiceObservationReader;
use Cieplik206\IntegrationOperations\Contracts\PollingContext;
use Cieplik206\IntegrationOperations\Contracts\PollingStrategy;
use Cieplik206\IntegrationOperations\Enums\PollPurpose;
use Cieplik206\IntegrationOperations\ValueObjects\PollOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use InvalidArgumentException;

final readonly class EnsureAcceptedPollingStrategy implements PollingStrategy
{
    public function __construct(
        private KsefInvoiceObservationReader $reader,
    ) {}

    public function poll(PollingContext $context): PollOutcome
    {
        $command = (new EnsureAcceptedPayloadCodec)->decode($context->payload());

        if ($context->scope()->provider->value !== 'fakturownia'
            || $context->operationType()->value !== EnsureAcceptedOperationDefinitionProvider::OperationType
            || ! $command->connectionKey->equals($context->scope()->connection)) {
            throw new InvalidArgumentException('The KSeF polling context does not match its frozen command.');
        }

        try {
            $observation = $this->reader->observe($command->connectionKey, $command->remoteId);
        } catch (AuthenticationFailed|ResourceNotFound|ProtocolViolation) {
            return PollOutcome::manualReview(
                $this->manualFailure('fakturownia_ksef_observation_blocked'),
                'fakturownia.ksef.observation_blocked',
            );
        } catch (FakturowniaReadException) {
            return PollOutcome::wait(
                'fakturownia.ksef.read_temporarily_unavailable',
                new RetryAfterSeconds(120),
            );
        }

        if (! hash_equals($observation->remoteId, $command->remoteId)) {
            return PollOutcome::manualReview(
                $this->manualFailure('fakturownia_ksef_remote_identity_mismatch'),
                'fakturownia.ksef.remote_identity_mismatch',
                $observation->canonical(),
            );
        }

        if ($observation->isAccepted()) {
            return PollOutcome::completed(
                $observation->acceptedResult(),
                'fakturownia.ksef.accepted',
                $observation->canonical(),
            );
        }

        if ($observation->isRejected()) {
            return PollOutcome::providerRejected(
                $observation->rejectedResult(),
                new SafeOperationFailure(
                    'fakturownia_ksef_provider_rejected',
                    'Fakturownia reported a terminal KSeF rejection or not-applicable result.',
                ),
                'fakturownia.ksef.provider_rejected',
                $observation->canonical(),
            );
        }

        if ($observation->requiresConfigurationBlock()) {
            return PollOutcome::manualReview(
                $this->manualFailure('fakturownia_ksef_connection_blocked'),
                'fakturownia.ksef.connection_blocked',
                $observation->canonical(),
            );
        }

        if ($this->isOverdueOnNextWait($context, $observation)) {
            return PollOutcome::manualReview(
                $this->manualFailure('fakturownia_ksef_poll_deadline'),
                'fakturownia.ksef.poll_deadline',
                $observation->canonical(overdue: true),
            );
        }

        if ($context->pollPurpose() === PollPurpose::Preflight
            && $command->profile->ownership === KsefOwnership::ExplicitSdk
            && $observation->status->category() === KsefStatusCategory::NotSent) {
            return PollOutcome::sendRequired(
                'fakturownia.ksef.explicit_send_required',
                $observation->canonical(),
            );
        }

        return PollOutcome::wait(
            $this->waitEvidence($observation),
            new RetryAfterSeconds($this->intervalSeconds($observation)),
            $observation->canonical(),
        );
    }

    private function isOverdueOnNextWait(PollingContext $context, KsefInvoiceObservation $observation): bool
    {
        $secondsRemaining = $context->pollDeadlineAt()->getTimestamp() - $context->pollStartedAt()->getTimestamp();

        return $secondsRemaining <= $this->intervalSeconds($observation);
    }

    private function intervalSeconds(KsefInvoiceObservation $observation): int
    {
        return match ($observation->status->category()) {
            KsefStatusCategory::Processing => 30,
            KsefStatusCategory::StatusCheckError => 120,
            KsefStatusCategory::TechnicalError,
            KsefStatusCategory::Duplicate,
            KsefStatusCategory::Unknown => 300,
            KsefStatusCategory::Offline => 900,
            KsefStatusCategory::NotSent,
            KsefStatusCategory::Succeeded => 60,
            KsefStatusCategory::ConfigurationBlocked,
            KsefStatusCategory::NotApplicable,
            KsefStatusCategory::Rejected => 300,
        };
    }

    private function waitEvidence(KsefInvoiceObservation $observation): string
    {
        return match ($observation->status->category()) {
            KsefStatusCategory::NotSent => 'fakturownia.ksef.awaiting_provider_send',
            KsefStatusCategory::Processing => 'fakturownia.ksef.processing',
            KsefStatusCategory::StatusCheckError => 'fakturownia.ksef.status_check_error',
            KsefStatusCategory::TechnicalError => 'fakturownia.ksef.technical_error',
            KsefStatusCategory::Offline => 'fakturownia.ksef.offline',
            KsefStatusCategory::Duplicate => 'fakturownia.ksef.duplicate_reconciling',
            KsefStatusCategory::Unknown => 'fakturownia.ksef.unknown_status',
            KsefStatusCategory::Succeeded => 'fakturownia.ksef.awaiting_government_id',
            KsefStatusCategory::ConfigurationBlocked => 'fakturownia.ksef.connection_blocked',
            KsefStatusCategory::NotApplicable,
            KsefStatusCategory::Rejected => 'fakturownia.ksef.provider_rejected',
        };
    }

    private function manualFailure(string $code): SafeOperationFailure
    {
        return new SafeOperationFailure(
            $code,
            'The KSeF operation requires operator review and will not send again.',
        );
    }
}
