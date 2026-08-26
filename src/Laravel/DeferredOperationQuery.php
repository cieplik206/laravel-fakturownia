<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel;

use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\Contracts\ScopedOperationQuery;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

/** @internal */
final readonly class DeferredOperationQuery implements OperationQuery
{
    public function __construct(private Application $app) {}

    public function within(IntegrationScope|IntegrationScopeSet $scopes): ScopedOperationQuery
    {
        $operations = $this->app->make(OperationQuery::class);

        if ($operations === $this) {
            throw new LogicException('Deferred Operation Query resolved itself.');
        }

        return $operations->within($scopes);
    }
}
