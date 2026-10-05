<?php

namespace Doss\DbBackup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class Archiver
{
    /**
     * Zip $folder into "$folder.zip" (the folder itself is the root entry
     * inside the archive), delete the folder, and return the zip path.
     */
    public function zipFolder(string $folder): string
    {
        $folder = rtrim($folder, '/');
        $target = $folder.'.zip';
        $root = basename($folder);

        $zip = new ZipArchive();

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create zip [{$target}].");
        }

        $zip->addEmptyDir($root);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $relative = $root.'/'.substr($file->getPathname(), strlen($folder) + 1);

            $file->isDir()
                ? $zip->addEmptyDir($relative)
                : $zip->addFile($file->getPathname(), $relative);
        }

        if (! $zip->close()) {
            throw new RuntimeException("Unable to write zip [{$target}].");
        }

        $this->deleteFolder($folder);

        return $target;
    }

    public function deleteFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($folder);
    }
}
