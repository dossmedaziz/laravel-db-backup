<?php

namespace Doss\DbBackup\Contracts;

interface Destination
{
    /**
     * Upload the local file at $path, stored under $name.
     *
     * $progress, when given, is called as $progress(int $sentBytes, int $totalBytes).
     *
     * Returns a short human-readable location (URL, path, file ID...).
     * Throw an exception on failure.
     */
    public function upload(string $path, string $name, ?callable $progress = null): string;
}
