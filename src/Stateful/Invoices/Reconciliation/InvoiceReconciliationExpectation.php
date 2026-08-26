<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\ExactOidLocator;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InvoiceReconciliationExpectation
{
    use RejectsNativeReconciliationObjectTransfer;

    /**
     * @var list<array{observation_number: int, observed_at: DateTimeImmutable, locator: ExactOidLocator, expected_fingerprint: VersionedHmacDigest}>
     */
    public array $previousAbsenceObservations;

    /**
     * @param  array<mixed>  $previousAbsenceObservations
     */
    public function __construct(
        public InvoiceDraft $draft,
        public RemoteInvoiceIdentity $identity,
        public InvoiceReconciliationOrigin $origin,
        public DateTimeImmutable $effectPossiblyStartedAt,
        public int $observationNumber,
        array $previousAbsenceObservations = [],
    ) {
        if ($identity->scope->documentKind !== $draft->kind
            || $identity->scope->departmentId !== $draft->departmentId) {
            throw new InvalidArgumentException('Invoice reconciliation identity must match the draft scope.');
        }

        if ($observationNumber < 1) {
            throw new InvalidArgumentException('Invoice reconciliation observation number must be positive.');
        }

        if ($effectPossiblyStartedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Invoice reconciliation effect boundaries must use UTC.');
        }

        if (count($previousAbsenceObservations) > 10) {
            throw new InvalidArgumentException('Invoice reconciliation absence history is too large.');
        }

        foreach ($previousAbsenceObservations as $observation) {
            if (! is_array($observation)
                || array_keys($observation) !== [
                    'observation_number',
                    'observed_at',
                    'locator',
                    'expected_fingerprint',
                ]
                || ! is_int($observation['observation_number'])
                || $observation['observation_number'] < 1
                || ! $observation['observed_at'] instanceof DateTimeImmutable
                || $observation['observed_at']->getOffset() !== 0
                || ! $observation['locator'] instanceof ExactOidLocator
                || ! $observation['expected_fingerprint'] instanceof VersionedHmacDigest) {
                throw new InvalidArgumentException('Invoice reconciliation absence history is invalid.');
            }
        }

        /** @var list<array{observation_number: int, observed_at: DateTimeImmutable, locator: ExactOidLocator, expected_fingerprint: VersionedHmacDigest}> $previousAbsenceObservations */
        $this->previousAbsenceObservations = $previousAbsenceObservations;
    }

    public function visibleAfter(InvoiceReconciliationPolicy $policy): DateTimeImmutable
    {
        return $this->effectPossiblyStartedAt->add(
            new DateInterval("PT{$policy->visibilityWindowSeconds}S"),
        );
    }
}
