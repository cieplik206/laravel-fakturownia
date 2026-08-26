<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation;

use Cieplik206\Fakturownia\Read\Data\ExactOidInvoiceQuery;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\ExactOidLocator;
use Throwable;

final readonly class FakturowniaInvoiceReconciliationReadProbe
{
    use RejectsNativeReconciliationObjectTransfer;

    private const int MaximumBufferedCandidates = 1001;

    private InvoiceReconciliationCandidateMapper $mapper;

    public function __construct(private FakturowniaManager $manager)
    {
        $this->mapper = new InvoiceReconciliationCandidateMapper;
    }

    public function scan(
        ExactOidLocator $locator,
        InvoiceReconciliationExpectation $expectation,
    ): InvoiceReconciliationScan {
        $expectedLocator = $expectation->identity->exactLocator();

        if (! $expectedLocator instanceof ExactOidLocator
            || ! self::sameLocator($locator, $expectedLocator)) {
            return InvoiceReconciliationScan::incomplete();
        }

        try {
            $query = new ExactOidInvoiceQuery(
                $locator->oid,
                $locator->scope->documentKind,
                $expectation->draft->income,
                $expectation->draft->issueDate,
            );
            $invoices = $this->manager
                ->connection($locator->scope->connection)
                ->read()
                ->invoices()
                ->streamByExactOid($query);
            $candidates = [];

            foreach ($invoices as $invoice) {
                $candidate = $this->mapper->map($locator, $expectation->draft, $invoice);

                if (! $candidate instanceof InvoiceReconciliationCandidate) {
                    return InvoiceReconciliationScan::incomplete();
                }

                $candidates[] = $candidate;

                if (count($candidates) >= self::MaximumBufferedCandidates) {
                    break;
                }
            }

            return InvoiceReconciliationScan::complete($candidates);
        } catch (Throwable) {
            return InvoiceReconciliationScan::incomplete();
        }
    }

    private static function sameLocator(ExactOidLocator $left, ExactOidLocator $right): bool
    {
        return $left->scope->connection->equals($right->scope->connection)
            && hash_equals($left->scope->documentKind, $right->scope->documentKind)
            && hash_equals($left->scope->departmentId, $right->scope->departmentId)
            && hash_equals($left->oid, $right->oid);
    }
}
