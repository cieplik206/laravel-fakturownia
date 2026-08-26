<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Reconciliation;

use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\Contracts\InvoiceReconciliationConfiguration;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationPolicy;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final readonly class ConfigInvoiceReconciliationConfiguration implements InvoiceReconciliationConfiguration
{
    public function __construct(private Repository $config) {}

    public function policy(): InvoiceReconciliationPolicy
    {
        return new InvoiceReconciliationPolicy(
            visibilityWindowSeconds: $this->integer('visibility_window_seconds', 300),
            requiredAbsentConfirmations: $this->integer('required_absent_confirmations', 2),
            maximumCandidatesPerScan: $this->integer('maximum_candidates_per_scan', 100),
            minimumAbsentConfirmationIntervalSeconds: $this->integer(
                'minimum_absent_confirmation_interval_seconds',
                120,
            ),
            maximumRemoteClockSkewSeconds: $this->integer('maximum_remote_clock_skew_seconds', 60),
        );
    }

    private function integer(string $key, int $default): int
    {
        $value = $this->config->get("fakturownia.reconciliation.{$key}", $default);

        if (! is_int($value)) {
            throw new InvalidArgumentException("Fakturownia reconciliation {$key} must be an integer.");
        }

        return $value;
    }
}
