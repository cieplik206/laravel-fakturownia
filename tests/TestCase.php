<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests;

use Cieplik206\Fakturownia\Laravel\FakturowniaServiceProvider;
use Cieplik206\IntegrationOperations\IntegrationOperationsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            IntegrationOperationsServiceProvider::class,
            FakturowniaServiceProvider::class,
        ];
    }
}
