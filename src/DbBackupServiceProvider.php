<?php

namespace Doss\DbBackup;

use Doss\DbBackup\Commands\BackupCommand;
use Doss\DbBackup\Commands\GoogleAuthCommand;
use Illuminate\Support\ServiceProvider;

class DbBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/db-backup.php', 'db-backup');

        $this->app->singleton(DestinationManager::class, fn ($app) => new DestinationManager($app));

        $this->app->singleton(DatabaseDumper::class, function ($app) {
            return new DatabaseDumper($app['config']->get('db-backup.dump'));
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/db-backup.php' => config_path('db-backup.php'),
        ], 'db-backup-config');

        $this->commands([
            BackupCommand::class,
            GoogleAuthCommand::class,
        ]);
    }
}
