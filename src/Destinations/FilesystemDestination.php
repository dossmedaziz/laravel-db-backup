<?php

namespace Doss\DbBackup\Destinations;

use Doss\DbBackup\Contracts\Destination;
use Illuminate\Contracts\Filesystem\Factory;
use RuntimeException;

/**
 * Uploads to any Laravel filesystem disk (local, s3, ftp, sftp, ...).
 */
class FilesystemDestination implements Destination
{
    public function __construct(private Factory $filesystem, private array $config)
    {
    }

    public function upload(string $path, string $name, ?callable $progress = null): string
    {
        $diskName = $this->config['disk'] ?? null;

        if (! $diskName) {
            throw new RuntimeException('The "disk" option is required for the disk driver.');
        }

        $target = ltrim(trim($this->config['path'] ?? '', '/').'/'.$name, '/');
        $stream = fopen($path, 'rb');

        try {
            if (! $this->filesystem->disk($diskName)->writeStream($target, $stream)) {
                throw new RuntimeException("Unable to write [{$target}] to disk [{$diskName}].");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($progress) {
            $size = filesize($path);
            $progress($size, $size);
        }

        return "{$diskName}:{$target}";
    }
}
