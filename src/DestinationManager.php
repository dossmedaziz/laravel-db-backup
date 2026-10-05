<?php

namespace Doss\DbBackup;

use Closure;
use Doss\DbBackup\Contracts\Destination;
use Doss\DbBackup\Destinations\FilesystemDestination;
use Doss\DbBackup\Destinations\GoogleDriveDestination;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

class DestinationManager
{
    /** @var array<string, Closure> */
    private array $customDrivers = [];

    public function __construct(private Application $app)
    {
    }

    /**
     * Register a custom driver. The callback receives the destination config
     * array and the application, and must return a Destination.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customDrivers[$driver] = $callback;

        return $this;
    }

    /**
     * Resolve a destination by its name in config('db-backup.destinations').
     */
    public function destination(string $name): Destination
    {
        $config = $this->app['config']->get("db-backup.destinations.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Backup destination [{$name}] is not defined in config/db-backup.php.");
        }

        $driver = $config['driver'] ?? null;

        if (isset($this->customDrivers[$driver])) {
            $destination = ($this->customDrivers[$driver])($config, $this->app);
        } else {
            $destination = match ($driver) {
                'google' => new GoogleDriveDestination($config),
                'disk' => new FilesystemDestination($this->app['filesystem'], $config),
                default => throw new InvalidArgumentException("Backup driver [{$driver}] for destination [{$name}] is not supported."),
            };
        }

        if (! $destination instanceof Destination) {
            throw new InvalidArgumentException("Driver [{$driver}] must return an instance of ".Destination::class.'.');
        }

        return $destination;
    }

    /**
     * Destination names to upload to by default (config "upload_to").
     *
     * @return string[]
     */
    public function defaults(): array
    {
        return self::parseList($this->app['config']->get('db-backup.upload_to'));
    }

    public static function parseList(string|array|null $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map('trim', (array) $value)));
    }
}
