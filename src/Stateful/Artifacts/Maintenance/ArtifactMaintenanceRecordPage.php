<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class ArtifactMaintenanceRecordPage
{
    use RejectsNativeSerialization;

    /** @var list<ArtifactMaintenanceRecord> */
    public array $records;

    /** @param array<mixed> $records */
    public function __construct(array $records, public ?string $nextArtifactId)
    {
        foreach ($records as $record) {
            if (! $record instanceof ArtifactMaintenanceRecord) {
                throw new InvalidArgumentException('The artifact maintenance record page contains an invalid record.');
            }
        }

        if ($nextArtifactId !== null && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $nextArtifactId) !== 1) {
            throw new InvalidArgumentException('The next artifact cursor must be a canonical ULID.');
        }

        $this->records = array_values($records);
    }
}
