<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Illuminate\Cache\DatabaseLock;

final class WritePdoDatabaseLock extends DatabaseLock
{
    protected function getCurrentOwner(): ?string
    {
        $owner = $this->connection->table($this->table)
            ->useWritePdo()
            ->where('key', $this->name)
            ->where('expiration', '>', $this->currentTime())
            ->value('owner');

        return is_string($owner) ? $owner : null;
    }
}
