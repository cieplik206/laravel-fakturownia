<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Console;

use Cieplik206\Fakturownia\Laravel\Artifacts\PostgresArtifactMaintenanceManagerFactory;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceReport;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStoreFactory;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Throwable;

/** @internal */
final class MaintainFakturowniaArtifactsCommand extends Command
{
    protected $signature = 'fakturownia:artifacts:maintain
        {action : doctor, prune, or sweep}
        {--after= : Canonical artifact ULID for prune or SHA-256 content address for sweep}
        {--force : Authorize a destructive prune or orphan sweep batch}';

    protected $description = 'Audit or safely maintain durable Fakturownia artifacts';

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = $this->argument('action');

        if (! is_string($action) || ! in_array($action, ['doctor', 'prune', 'sweep'], true)) {
            $this->components->error('The artifact maintenance action must be doctor, prune, or sweep.');

            return self::INVALID;
        }

        if ($action !== 'doctor' && ! $this->option('force')) {
            $this->components->error('Destructive artifact maintenance requires the explicit --force option.');

            return self::INVALID;
        }

        if (! $this->container->bound(ArtifactMaintenanceStoreFactory::class)) {
            $this->components->error('Artifact maintenance is unavailable because no capability-aware store is bound.');

            return self::FAILURE;
        }

        try {
            $manager = $this->container->make(PostgresArtifactMaintenanceManagerFactory::class)->make();
            $after = $this->option('after');
            $after = is_string($after) && $after !== '' ? $after : null;
            $report = match ($action) {
                'doctor' => $manager->doctor(),
                'prune' => $manager->prune($after),
                'sweep' => $manager->sweep($after === null ? null : ContentAddress::fromSha256($after)),
            };
        } catch (Throwable) {
            $this->components->error('Artifact maintenance failed safely; sensitive failure details were withheld.');

            return self::FAILURE;
        }

        $this->renderReport($report);

        return $report->passes() ? self::SUCCESS : self::FAILURE;
    }

    private function renderReport(ArtifactMaintenanceReport $report): void
    {
        $this->table(['examined', 'deleted', 'tombstoned', 'quarantined', 'findings'], [[
            $report->examined,
            $report->objectsDeleted,
            $report->tombstoned,
            $report->quarantined,
            $report->totalFindings,
        ]]);

        $findingCounts = [];

        foreach ($report->findings as $finding) {
            $findingCounts[$finding->issue->value] = ($findingCounts[$finding->issue->value] ?? 0) + 1;
        }

        if ($findingCounts !== []) {
            ksort($findingCounts);
            $this->table(
                ['finding', 'sample_count'],
                array_map(
                    static fn (string $issue, int $count): array => [$issue, $count],
                    array_keys($findingCounts),
                    array_values($findingCounts),
                ),
            );
        }

        if ($report->findingsTruncated) {
            $this->components->warn('The finding sample is truncated; the total counter remains authoritative.');
        }

        if ($report->nextArtifactId !== null || $report->nextObjectAddress !== null) {
            $continuationCursor = $report->nextArtifactId ?? (string) $report->nextObjectAddress;

            $this->components->warn('The bounded batch is incomplete. Re-run with the continuation cursor.');
            $this->line('continuation_cursor: '.$continuationCursor);
        }
    }
}
