<?php

namespace Doss\DbBackup\Commands;

use Doss\DbBackup\Archiver;
use Doss\DbBackup\DatabaseDumper;
use Doss\DbBackup\DestinationManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'db:backup
                            {--connection= : Database connection to back up (defaults to config)}
                            {--to= : Comma-separated destinations, e.g. google,s3 (defaults to DB_BACKUP_DESTINATIONS)}
                            {--delete-local : Delete the local zip after all uploads succeed}
                            {--no-upload : Only create the local zip}';

    protected $description = 'Dump the database and upload it to the configured destinations';

    public function handle(DatabaseDumper $dumper, Archiver $archiver, DestinationManager $destinations): int
    {
        $config = config('db-backup');
        $connectionName = $this->option('connection') ?: $config['connection'];
        $connection = config("database.connections.{$connectionName}");

        if (! $connection) {
            $this->components->error("Database connection [{$connectionName}] is not configured.");

            return self::FAILURE;
        }

        $targets = $this->option('to')
            ? DestinationManager::parseList($this->option('to'))
            : $destinations->defaults();

        if (! $this->option('no-upload') && $targets === []) {
            $this->components->error('No destinations configured. Set DB_BACKUP_DESTINATIONS or use --to.');

            return self::FAILURE;
        }

        foreach ($this->option('no-upload') ? [] : $targets as $name) {
            if (! is_array(config("db-backup.destinations.{$name}"))) {
                $this->components->error("Backup destination [{$name}] is not defined in config/db-backup.php.");

                return self::FAILURE;
            }
        }

        $dir =rtrim($config['dump']['path'], '/');

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->components->error("Unable to create directory [{$dir}].");

            return self::FAILURE;
        }

        $extension = match ($connection['driver']) {
            'pgsql' => in_array('-Fc', $config['dump']['extra_options']['pgsql'] ?? []) ? 'dump' : 'sql',
            'sqlite' => 'sqlite',
            default => 'sql',
        };

        $prefix = $config['dump']['prefix'] ? str($config['dump']['prefix'])->slug().'_' : '';
        $name = $prefix.date($config['dump']['filename_format']);
        $folder = $dir.'/'.$name;
        $file = null;

        try {
            // 1. Dump the database into its own folder.
            if (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder)) {
                throw new RuntimeException("Unable to create directory [{$folder}].");
            }

            $this->components->task("Dumping [{$connectionName}]", function () use ($dumper, $connection, $folder, $name, $extension) {
                $dumper->dump($connection, "{$folder}/{$name}.{$extension}");
            });

            // 2. Zip the folder.
            $this->components->task('Zipping', function () use ($archiver, $folder, &$file) {
                $file = $archiver->zipFolder($folder);
            });
        } catch (Throwable $e) {
            $archiver->deleteFolder($folder);

            return $this->reportError($e);
        }

        $this->components->info(sprintf('Backup created: %s (%s)', $file, $this->humanSize(filesize($file))));

        if ($this->option('no-upload')) {
            return self::SUCCESS;
        }

        // 3. Upload the zip to every destination.
        $failed = 0;

        foreach ($targets as $target) {
            try {
                $location = $this->uploadTo($destinations, $target, $file);
                $this->components->twoColumnDetail("<fg=green>✔</> {$target}", $location);
            } catch (Throwable $e) {
                $failed++;
                $this->newLine();
                $this->reportError($e, $target);
            }
        }

        // 4. Delete the local zip only when asked and every upload succeeded,
        //    so the backup is never lost.
        $delete = $this->option('delete-local') || $config['dump']['delete_local'];

        if ($delete && $failed === 0) {
            @unlink($file);
            $this->components->info('Local backup deleted.');
        } elseif ($delete) {
            $this->components->warn("Some uploads failed, local backup kept at {$file}");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function uploadTo(DestinationManager $destinations, string $name, string $file): string
    {
        $destination = $destinations->destination($name);

        $bar = $this->output->createProgressBar(filesize($file));
        $bar->setFormat(" Uploading to {$name} [%bar%] %percent:3s%%");

        $location = $destination->upload($file, basename($file), fn ($sent) => $bar->setProgress($sent));

        $bar->finish();
        $bar->clear();

        return $location;
    }

    private function reportError(Throwable $e, ?string $destination = null): int
    {
        $prefix = $destination ? "[{$destination}] " : '';

        Log::error('[db:backup] '.$prefix.$e->getMessage(), ['exception' => $e]);
        $this->components->error($prefix.$e->getMessage());

        return self::FAILURE;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
