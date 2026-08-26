<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Facades;

use BadMethodCallException;
use Cieplik206\Fakturownia\Stateful\FakturowniaConnection;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Illuminate\Support\Facades\Facade;
use LogicException;

final class Fakturownia extends Facade
{
    public static function connection(ConnectionKey $connectionKey): FakturowniaConnection
    {
        $manager = self::getFacadeRoot();

        if (! $manager instanceof FakturowniaManager) {
            throw new LogicException('The Fakturownia facade root is not available.');
        }

        return $manager->connection($connectionKey);
    }

    /**
     * @param  array<mixed>  $arguments
     */
    final public static function __callStatic($method, $arguments): never
    {
        throw new BadMethodCallException('Dynamic Fakturownia facade calls are disabled.');
    }

    protected static function getFacadeAccessor(): string
    {
        return FakturowniaManager::class;
    }
}
