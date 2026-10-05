<?php

namespace Doss\DbBackup;

use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseDumper
{
    public function __construct(private array $config)
    {
    }

    /**
     * Dump the given connection to $path and return the path of the written file.
     */
    public function dump(array $connection, string $path): string
    {
        $driver = $connection['driver'] ?? null;

        match ($driver) {
            'pgsql' => $this->dumpPostgres($connection, $path),
            'mysql', 'mariadb' => $this->dumpMysql($connection, $path),
            'sqlite' => $this->dumpSqlite($connection, $path),
            default => throw new RuntimeException("Unsupported database driver [{$driver}]."),
        };

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException("The dump file [{$path}] is empty.");
        }

        return $path;
    }

    private function dumpPostgres(array $c, string $path): void
    {
        $command = array_merge([
            $this->config['binaries']['pg_dump'] ?? 'pg_dump',
            '--host='.($c['host'] ?? '127.0.0.1'),
            '--port='.($c['port'] ?? 5432),
            '--username='.$c['username'],
            '--dbname='.$c['database'],
            '--file='.$path,
            '--no-password',
        ], $this->config['extra_options']['pgsql'] ?? []);

        $this->run($command, ['PGPASSWORD' => (string) ($c['password'] ?? '')]);
    }

    private function dumpMysql(array $c, string $path): void
    {
        // Pass credentials through a temporary option file so the password
        // never shows up in the process list.
        $credentials = tempnam(sys_get_temp_dir(), 'drive-backup-');
        chmod($credentials, 0600);
        file_put_contents($credentials, implode(PHP_EOL, [
            '[client]',
            'user="'.addslashes($c['username']).'"',
            'password="'.addslashes((string) ($c['password'] ?? '')).'"',
            'host="'.addslashes($c['host'] ?? '127.0.0.1').'"',
            'port='.($c['port'] ?? 3306),
        ]).PHP_EOL);

        if (! empty($c['unix_socket'])) {
            file_put_contents($credentials, 'socket="'.addslashes($c['unix_socket']).'"'.PHP_EOL, FILE_APPEND);
        }

        try {
            $command = array_merge([
                $this->config['binaries']['mysqldump'] ?? 'mysqldump',
                '--defaults-extra-file='.$credentials,
                '--result-file='.$path,
            ], $this->config['extra_options']['mysql'] ?? [], [$c['database']]);

            $this->run($command);
        } finally {
            @unlink($credentials);
        }
    }

    private function dumpSqlite(array $c, string $path): void
    {
        $database = $c['database'];

        if (! is_file($database)) {
            throw new RuntimeException("SQLite database [{$database}] not found.");
        }

        if (! copy($database, $path)) {
            throw new RuntimeException("Unable to copy SQLite database to [{$path}].");
        }
    }

    private function run(array $command, array $env = []): void
    {
        $process = new Process($command, null, $env, null, $this->config['timeout'] ?? 3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "Dump command failed (exit code %s):\n%s",
                $process->getExitCode(),
                trim($process->getErrorOutput()) ?: trim($process->getOutput())
            ));
        }
    }
}
