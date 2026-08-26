<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class SharedDatabaseArtifactLockConfiguration
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $connectionName,
        public string $databaseName,
        public string $host,
        public int $port,
        public string $schema,
        public string $lockSchema,
        public string $artifactTable,
        public string $lockTable,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $connectionName) !== 1) {
            throw new InvalidArgumentException('The shared artifact lock connection name is invalid.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $databaseName) !== 1) {
            throw new InvalidArgumentException('The shared artifact lock database name is invalid.');
        }

        if ($host === '' || strlen($host) > 255 || preg_match('/[\x00-\x20\x7f]/D', $host) === 1) {
            throw new InvalidArgumentException('The shared artifact lock host is invalid.');
        }

        if ($port < 1 || $port > 65_535) {
            throw new InvalidArgumentException('The shared artifact lock port is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The shared artifact database schema is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $lockSchema) !== 1) {
            throw new InvalidArgumentException('The shared artifact coordination schema is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $artifactTable) !== 1) {
            throw new InvalidArgumentException('The shared artifact descriptor table is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $lockTable) !== 1) {
            throw new InvalidArgumentException('The shared artifact lock table is invalid.');
        }
    }

    public function qualifiedArtifactTable(): string
    {
        return $this->schema.'.'.$this->artifactTable;
    }

    public function qualifiedLockTable(): string
    {
        return $this->lockSchema.'.'.$this->lockTable;
    }
}
