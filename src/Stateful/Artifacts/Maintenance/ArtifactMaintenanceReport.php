<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class ArtifactMaintenanceReport
{
    use RejectsNativeSerialization;

    public const int MAXIMUM_FINDING_SAMPLE = 100;

    /** @var list<ArtifactMaintenanceFinding> */
    public array $findings;

    public int $totalFindings;

    public bool $findingsTruncated;

    /** @param array<mixed> $findings */
    public function __construct(
        public int $examined,
        public int $objectsDeleted,
        public int $tombstoned,
        public int $quarantined,
        array $findings,
        public ?string $nextArtifactId = null,
        public ?ContentAddress $nextObjectAddress = null,
        ?int $totalFindings = null,
    ) {
        if ($examined < 0 || $objectsDeleted < 0 || $tombstoned < 0 || $quarantined < 0) {
            throw new InvalidArgumentException('Artifact maintenance counters cannot be negative.');
        }

        foreach ($findings as $finding) {
            if (! $finding instanceof ArtifactMaintenanceFinding) {
                throw new InvalidArgumentException('The artifact maintenance report contains an invalid finding.');
            }
        }

        if (count($findings) > self::MAXIMUM_FINDING_SAMPLE) {
            throw new InvalidArgumentException('The artifact maintenance finding sample exceeds its fixed bound.');
        }

        $resolvedTotalFindings = $totalFindings ?? count($findings);

        if ($resolvedTotalFindings < count($findings)) {
            throw new InvalidArgumentException('The artifact maintenance finding total cannot be smaller than its sample.');
        }

        if ($nextArtifactId !== null && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $nextArtifactId) !== 1) {
            throw new InvalidArgumentException('The artifact maintenance cursor must be a canonical ULID.');
        }

        $this->findings = array_values($findings);
        $this->totalFindings = $resolvedTotalFindings;
        $this->findingsTruncated = $resolvedTotalFindings > count($findings);
    }

    public function passes(): bool
    {
        if ($this->nextArtifactId !== null || $this->nextObjectAddress !== null) {
            return false;
        }

        return $this->totalFindings === 0;
    }
}
