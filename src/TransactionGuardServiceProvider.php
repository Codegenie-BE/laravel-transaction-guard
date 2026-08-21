<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard;

use Codegenie\TransactionGuard\Console\TransactionGuardCommand;
use Illuminate\Support\ServiceProvider;

final class TransactionGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/transaction-guard.php', 'transaction-guard');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([TransactionGuardCommand::class]);

        $this->publishes([
            __DIR__.'/../config/transaction-guard.php' => config_path('transaction-guard.php'),
        ], 'transaction-guard-config');
    }
}
