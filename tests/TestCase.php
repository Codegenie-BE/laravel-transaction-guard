<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Tests;

use Codegenie\TransactionGuard\TransactionGuardServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TransactionGuardServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.sync', [
            'driver' => 'sync',
            'after_commit' => false,
        ]);
    }
}
