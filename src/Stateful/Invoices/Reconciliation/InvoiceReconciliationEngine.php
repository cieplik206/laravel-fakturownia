<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation;

use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\ExactOidLocator;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\InvoiceFingerprint;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\Contracts\InvoiceReconciliationConfiguration;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaCommand;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaPayloadCodec;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationContext;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationObservation;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Production reconciliation boundary.
 *
 * @internal
 */
final readonly class InvoiceReconciliationEngine
{
    use RejectsNativeReconciliationObjectTransfer;

    private FakturowniaInvoiceReconciliationReadProbe $probe;

    public function __construct(
        FakturowniaManager $manager,
        private HmacSha256 $hmac,
        InvoiceReconciliationConfiguration $configuration,
    ) {
        $this->probe = new FakturowniaInvoiceReconciliationReadProbe($manager);
        $this->policy = $configuration->policy();
    }

    private InvoiceReconciliationPolicy $policy;

    public function reconcile(ReconciliationContext $context): ReconciliationOutcome
    {
        return $this->hardDeny($this->probe);
    }

    public function reconcileAuthoritative(
        AuthoritativeReconciliationContext $context,
    ): AuthoritativeReconciliationOutcome {
        ['draft' => $draft, 'identity' => $identity] = $this->reconciliationPayload($context);

        if (! $identity->scope->connection->equals($context->scope()->connection)) {
            return AuthoritativeReconciliationOutcome::inconclusive(
                'fakturownia.invoice.operation_scope_mismatch',
            );
        }

        $locator = $identity->exactLocator();

        if (! $locator instanceof ExactOidLocator) {
            return AuthoritativeReconciliationOutcome::ambiguousMatches(
                $this->ambiguousFailure(),
                'fakturownia.invoice.exact_identity_unavailable',
            );
        }

        $expectation = new InvoiceReconciliationExpectation(
            draft: $draft,
            identity: $identity,
            origin: $this->origin($context->reconciliationTrigger()),
            effectPossiblyStartedAt: $context->effectPossiblyStartedAt(),
            observationNumber: $context->observationNumber(),
            previousAbsenceObservations: $this->previousAbsenceObservations(
                $context->priorObservations(),
                $locator,
                $draft,
            ),
        );
        $observedAt = $context->observationStartedAt();
        $scan = $this->probe->scan($locator, $expectation);
        $outcome = self::decideSealedObservation(
            $expectation,
            $observedAt,
            $locator,
            $scan,
            new InvoiceFingerprint($this->hmac),
            $this->policy,
        );

        return $this->authoritativeOutcome($outcome);
    }

    /** @return array{draft: InvoiceDraft, identity: RemoteInvoiceIdentity} */
    private function reconciliationPayload(
        AuthoritativeReconciliationContext $context,
    ): array {
        $payload = $context->payload();
        $command = match ($payload->values['write_activation_slot'] ?? null) {
            IssueInvoicePayloadCodec::WriteActivationSlot => (new IssueInvoicePayloadCodec)->decode($payload),
            IssueProformaPayloadCodec::WriteActivationSlot => (new IssueProformaPayloadCodec)->decode($payload),
            default => throw new InvalidArgumentException('Invoice reconciliation payload activation slot is unsupported.'),
        };

        return match (true) {
            $command instanceof IssueInvoiceCommand => [
                'draft' => $command->draft,
                'identity' => $command->identity,
            ],
            $command instanceof IssueProformaCommand => [
                'draft' => $command->draft->toInvoiceDraft(),
                'identity' => $command->identity,
            ],
        };
    }

    private function hardDeny(
        FakturowniaInvoiceReconciliationReadProbe $managerOwnedProbe,
    ): ReconciliationOutcome {
        return ReconciliationOutcome::inconclusive(
            'fakturownia.invoice.production_wiring_not_frozen',
        );
    }

    /**
     * @param  list<ReconciliationObservation>  $observations
     * @return list<array{observation_number: int, observed_at: DateTimeImmutable, locator: ExactOidLocator, expected_fingerprint: VersionedHmacDigest}>
     */
    private function previousAbsenceObservations(
        array $observations,
        ExactOidLocator $locator,
        InvoiceDraft $draft,
    ): array {
        $fingerprint = (new InvoiceFingerprint($this->hmac))->fromDraft($draft);
        $absenceEvidence = [
            'fakturownia.invoice.absence_confirmation_pending',
            'fakturownia.invoice.absent_after_visibility',
        ];
        $history = [];

        foreach ($observations as $observation) {
            if ($observation->result !== ReconciliationResult::Inconclusive
                || ! in_array($observation->evidenceCode, $absenceEvidence, true)) {
                continue;
            }

            $history[] = [
                'observation_number' => $observation->observationNumber,
                'observed_at' => $observation->observedAt,
                'locator' => $locator,
                'expected_fingerprint' => $fingerprint,
            ];
        }

        return array_slice($history, -10);
    }

    private function origin(ReconciliationTrigger $trigger): InvoiceReconciliationOrigin
    {
        return match ($trigger) {
            ReconciliationTrigger::LostResponse => InvoiceReconciliationOrigin::LostResponse,
            ReconciliationTrigger::DuplicateEnvelope => InvoiceReconciliationOrigin::DuplicateEnvelope,
            ReconciliationTrigger::OidConflict => InvoiceReconciliationOrigin::OidConflict,
            ReconciliationTrigger::Unknown => InvoiceReconciliationOrigin::Unclassified,
        };
    }

    private function authoritativeOutcome(ReconciliationOutcome $outcome): AuthoritativeReconciliationOutcome
    {
        return match ($outcome->result) {
            ReconciliationResult::FoundExact => AuthoritativeReconciliationOutcome::foundExact(
                $outcome->operationResult ?? throw new InvalidArgumentException('Exact reconciliation result is missing.'),
                $outcome->evidenceCode,
            ),
            ReconciliationResult::AbsentConclusive => AuthoritativeReconciliationOutcome::absentConclusive(
                $outcome->safeFailure ?? throw new InvalidArgumentException('Absence failure is missing.'),
                $outcome->evidenceCode,
            ),
            ReconciliationResult::Inconclusive => AuthoritativeReconciliationOutcome::inconclusive(
                $outcome->evidenceCode,
            ),
            ReconciliationResult::AmbiguousMatches => AuthoritativeReconciliationOutcome::ambiguousMatches(
                $outcome->safeFailure ?? throw new InvalidArgumentException('Ambiguous failure is missing.'),
                $outcome->evidenceCode,
            ),
        };
    }

    private function ambiguousFailure(): SafeOperationFailure
    {
        return new SafeOperationFailure(
            'fakturownia_invoice_ambiguous',
            'Invoice reconciliation requires manual review.',
        );
    }

    /**
     * Full offline decision atom for a scan produced by the sealed exact-read path.
     *
     * This method stays private so diagnostic candidates, clocks and policies can
     * never be supplied through a shipped production entrypoint. The production
     * path may call it only after authoritative-context preflight and manager-owned
     * probe execution are vendor-pinned.
     */
    private static function decideSealedObservation(
        InvoiceReconciliationExpectation $expectation,
        DateTimeImmutable $observedAt,
        ExactOidLocator $locator,
        InvoiceReconciliationScan $scan,
        InvoiceFingerprint $fingerprint,
        InvoiceReconciliationPolicy $policy,
    ): ReconciliationOutcome {
        $preflight = self::productionPreflight($expectation, $observedAt, true);

        if ($preflight instanceof ReconciliationOutcome) {
            return $preflight;
        }

        if (! self::locatorMatchesExpectation($locator, $expectation)) {
            return self::ambiguous('fakturownia.invoice.exact_identity_mismatch');
        }

        if (! $scan->complete) {
            return ReconciliationOutcome::inconclusive(
                'fakturownia.invoice.scan_incomplete',
            );
        }

        if (count($scan->candidates) > $policy->maximumCandidatesPerScan) {
            return self::ambiguous('fakturownia.invoice.candidate_limit_exceeded');
        }

        if ($scan->candidates !== []) {
            return self::matchCandidates(
                $expectation,
                $observedAt,
                $locator,
                $scan->candidates,
                $fingerprint,
                $policy,
            );
        }

        return self::decideEmptyScan(
            $expectation,
            $observedAt,
            $locator,
            $fingerprint,
            $policy,
        );
    }

    private static function productionPreflight(
        InvoiceReconciliationExpectation $expectation,
        DateTimeImmutable $observedAt,
        bool $sealedExactRead = false,
    ): ?ReconciliationOutcome {
        $preflight = self::preflight($expectation, $observedAt);

        if ($preflight instanceof ReconciliationOutcome) {
            return $preflight;
        }

        if ($sealedExactRead) {
            return null;
        }

        if ($expectation->identity->oid() !== null && ! $expectation->identity->usesOidUnique()) {
            return ReconciliationOutcome::inconclusive(
                'fakturownia.invoice.oid_uniqueness_not_verified',
            );
        }

        if ($expectation->identity->exactLocator() === null) {
            return self::ambiguous('fakturownia.invoice.exact_identity_unavailable');
        }

        return null;
    }

    private static function preflight(
        InvoiceReconciliationExpectation $expectation,
        DateTimeImmutable $observedAt,
    ): ?ReconciliationOutcome {
        if ($observedAt->getOffset() !== 0) {
            return ReconciliationOutcome::inconclusive(
                'fakturownia.invoice.observation_not_utc',
            );
        }

        if ($observedAt < $expectation->effectPossiblyStartedAt) {
            return ReconciliationOutcome::inconclusive(
                'fakturownia.invoice.observation_before_effect_boundary',
            );
        }

        return null;
    }

    /** @param list<InvoiceReconciliationCandidate> $candidates */
    private static function matchCandidates(
        InvoiceReconciliationExpectation $expectation,
        DateTimeImmutable $observedAt,
        ExactOidLocator $locator,
        array $candidates,
        InvoiceFingerprint $fingerprint,
        InvoiceReconciliationPolicy $policy,
    ): ReconciliationOutcome {
        if (count($candidates) !== 1) {
            return self::ambiguous('fakturownia.invoice.multiple_candidates');
        }

        $candidate = $candidates[0];

        if (! self::candidateMatches(
            $expectation,
            $observedAt,
            $locator,
            $candidate,
            $fingerprint,
            $policy,
        )) {
            return self::ambiguous('fakturownia.invoice.identity_or_fingerprint_mismatch');
        }

        return ReconciliationOutcome::foundExact(
            IssueInvoiceResult::fromIssuedInvoiceResult($candidate->invoice),
            'fakturownia.invoice.exact_match',
        );
    }

    private static function candidateMatches(
        InvoiceReconciliationExpectation $expectation,
        DateTimeImmutable $observedAt,
        ExactOidLocator $locator,
        InvoiceReconciliationCandidate $candidate,
        InvoiceFingerprint $fingerprint,
        InvoiceReconciliationPolicy $policy,
    ): bool {
        if (! $candidate->scope->connection->equals($locator->scope->connection)) {
            return false;
        }

        if ($candidate->scope->documentKind !== $locator->scope->documentKind
            || $candidate->scope->departmentId !== $locator->scope->departmentId
            || $candidate->income !== $expectation->draft->income) {
            return false;
        }

        if ($candidate->invoice->oid !== $locator->oid
            || $candidate->invoice->kind !== $expectation->draft->kind) {
            return false;
        }

        $clockSkew = new DateInterval("PT{$policy->maximumRemoteClockSkewSeconds}S");

        if ($candidate->remoteCreatedAt < $expectation->effectPossiblyStartedAt->sub($clockSkew)
            || $candidate->remoteCreatedAt > $observedAt->add($clockSkew)) {
            return false;
        }

        return $fingerprint->fromDraft($expectation->draft)->equals(
            $fingerprint->fromResult($candidate->invoice),
        );
    }

    private static function decideEmptyScan(
        InvoiceReconciliationExpectation $expectation,
        DateTimeImmutable $observedAt,
        ExactOidLocator $locator,
        InvoiceFingerprint $fingerprint,
        InvoiceReconciliationPolicy $policy,
    ): ReconciliationOutcome {
        $visibleAfter = $expectation->visibleAfter($policy);

        if ($observedAt < $visibleAfter) {
            return ReconciliationOutcome::inconclusive(
                'fakturownia.invoice.visibility_window_open',
            );
        }

        if (! $expectation->origin->allowsConclusiveAbsence()) {
            return self::ambiguous('fakturownia.invoice.origin_requires_manual_review');
        }

        $requiredPreviousObservations = $policy->requiredAbsentConfirmations - 1;

        if ($expectation->observationNumber < $policy->requiredAbsentConfirmations
            || count($expectation->previousAbsenceObservations) < $requiredPreviousObservations) {
            return ReconciliationOutcome::inconclusive(
                'fakturownia.invoice.absence_confirmation_pending',
            );
        }

        $observations = array_slice(
            $expectation->previousAbsenceObservations,
            -$requiredPreviousObservations,
        );
        $expectedNumber = $expectation->observationNumber - $requiredPreviousObservations;
        $previousObservedAt = null;

        foreach ($observations as $observation) {
            if ($observation['observation_number'] !== $expectedNumber) {
                return ReconciliationOutcome::inconclusive(
                    'fakturownia.invoice.absence_history_not_consecutive',
                );
            }

            if (! self::observationMatches($observation, $expectation, $locator, $fingerprint)) {
                return ReconciliationOutcome::inconclusive(
                    'fakturownia.invoice.absence_history_target_mismatch',
                );
            }

            if ($observation['observed_at'] < $visibleAfter) {
                return ReconciliationOutcome::inconclusive(
                    'fakturownia.invoice.absence_history_before_visibility',
                );
            }

            if ($previousObservedAt instanceof DateTimeImmutable
                && ! self::isSufficientlyLater($previousObservedAt, $observation['observed_at'], $policy)) {
                return ReconciliationOutcome::inconclusive(
                    'fakturownia.invoice.absence_history_not_spaced',
                );
            }

            $previousObservedAt = $observation['observed_at'];
            $expectedNumber++;
        }

        if (! $previousObservedAt instanceof DateTimeImmutable
            || ! self::isSufficientlyLater($previousObservedAt, $observedAt, $policy)) {
            return ReconciliationOutcome::inconclusive(
                'fakturownia.invoice.absence_history_not_spaced',
            );
        }

        return ReconciliationOutcome::absentConclusive(
            new SafeOperationFailure(
                'fakturownia_invoice_absent',
                'The invoice was absent after complete visibility-window observations.',
            ),
            'fakturownia.invoice.absent_after_visibility',
        );
    }

    /**
     * @param  array{observation_number: int, observed_at: DateTimeImmutable, locator: ExactOidLocator, expected_fingerprint: VersionedHmacDigest}  $observation
     */
    private static function observationMatches(
        array $observation,
        InvoiceReconciliationExpectation $expectation,
        ExactOidLocator $expectedLocator,
        InvoiceFingerprint $fingerprint,
    ): bool {
        $locator = $observation['locator'];

        return $locator->scope->connection->equals($expectedLocator->scope->connection)
            && $locator->scope->documentKind === $expectedLocator->scope->documentKind
            && $locator->scope->departmentId === $expectedLocator->scope->departmentId
            && hash_equals($locator->oid, $expectedLocator->oid)
            && $observation['expected_fingerprint']->equals($fingerprint->fromDraft($expectation->draft));
    }

    private static function locatorMatchesExpectation(
        ExactOidLocator $locator,
        InvoiceReconciliationExpectation $expectation,
    ): bool {
        $oid = $expectation->identity->oid();

        return $oid !== null
            && $locator->scope->connection->equals($expectation->identity->scope->connection)
            && hash_equals($locator->scope->documentKind, $expectation->identity->scope->documentKind)
            && hash_equals($locator->scope->departmentId, $expectation->identity->scope->departmentId)
            && hash_equals($locator->oid, $oid);
    }

    private static function isSufficientlyLater(
        DateTimeImmutable $previous,
        DateTimeImmutable $current,
        InvoiceReconciliationPolicy $policy,
    ): bool {
        return $current >= $previous->add(
            new DateInterval("PT{$policy->minimumAbsentConfirmationIntervalSeconds}S"),
        );
    }

    private static function ambiguous(string $evidenceCode): ReconciliationOutcome
    {
        return ReconciliationOutcome::ambiguousMatches(
            new SafeOperationFailure(
                'fakturownia_invoice_ambiguous',
                'Invoice reconciliation requires manual review.',
            ),
            $evidenceCode,
        );
    }
}
