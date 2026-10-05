<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | The connection (from config/database.php) to back up. Host, port,
    | database, username and password are read from that connection.
    | Supported drivers: pgsql, mysql, mariadb, sqlite.
    |
    */

    'connection' => env('DB_BACKUP_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /*
    |--------------------------------------------------------------------------
    | Local dump settings
    |--------------------------------------------------------------------------
    |
    | The database is dumped into a folder named "<prefix>_<date>", the
    | folder is zipped to "<prefix>_<date>.zip", and the zip is uploaded.
    |
    | path:            where backups are created before upload.
    | filename_format: PHP date() format used for the folder / zip name.
    | prefix:          added before the date (e.g. your app name).
    | delete_local:    delete the local zip after all uploads succeed
    |                  (same as passing --delete-local).
    | timeout:         seconds allowed for the dump command (null = no limit).
    |
    */

    'dump' => [
        'path' => env('DB_BACKUP_PATH', storage_path('app/db-backups')),
        'filename_format' => 'Y-m-d-H-i',
        'prefix' => env('DB_BACKUP_PREFIX', env('APP_NAME', 'laravel')),
        'delete_local' => env('DB_BACKUP_DELETE_LOCAL', false),
        'timeout' => 3600,

        // Full path to the binaries if they are not on the PATH.
        'binaries' => [
            'pg_dump' => env('DB_BACKUP_PG_DUMP', 'pg_dump'),
            'mysqldump' => env('DB_BACKUP_MYSQLDUMP', 'mysqldump'),
        ],

        // Extra arguments appended to the dump command.
        'extra_options' => [
            'pgsql' => ['-Fc'],
            'mysql' => ['--single-transaction', '--quick', '--routines'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Where to upload
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of destination names (keys of "destinations"
    | below). The dump is uploaded to every one of them, e.g.
    |
    |   DB_BACKUP_DESTINATIONS=google
    |   DB_BACKUP_DESTINATIONS=google,s3
    |
    */

    'upload_to' => env('DB_BACKUP_DESTINATIONS', 'google'),

    /*
    |--------------------------------------------------------------------------
    | Destinations
    |--------------------------------------------------------------------------
    |
    | Each destination has a "driver". Built-in drivers:
    |
    |  - google: Google Drive (service account or OAuth).
    |  - disk:   any Laravel filesystem disk from config/filesystems.php
    |            (local, s3, ftp, sftp, ...).
    |
    | Register your own driver in a service provider:
    |
    |   app(\Doss\DbBackup\DestinationManager::class)->extend('dropbox',
    |       fn (array $config) => new DropboxDestination($config));
    |
    | Your class must implement \Doss\DbBackup\Contracts\Destination.
    |
    */

    'destinations' => [

        'google' => [
            'driver' => 'google',

            // "service_account" or "oauth"
            //  - service_account: share the folder with the service account e-mail.
            //  - oauth: run `php artisan db-backup:google-auth` once to create the token.
            'auth' => env('DB_BACKUP_GOOGLE_AUTH', 'service_account'),

            // ID from https://drive.google.com/drive/folders/<FOLDER_ID>
            'folder_id' => env('DB_BACKUP_GOOGLE_FOLDER_ID'),

            'service_account_json' => env('DB_BACKUP_GOOGLE_SERVICE_ACCOUNT', storage_path('app/google/credentials.json')),
            'oauth_client_json' => env('DB_BACKUP_GOOGLE_OAUTH_CLIENT', storage_path('app/google/client_secret.json')),
            'oauth_token_json' => env('DB_BACKUP_GOOGLE_OAUTH_TOKEN', storage_path('app/google/token.json')),

            'application_name' => env('APP_NAME', 'Laravel DB Backup'),
            'chunk_size' => 8 * 1024 * 1024,
        ],

        's3' => [
            'driver' => 'disk',
            'disk' => env('DB_BACKUP_S3_DISK', 's3'),
            'path' => env('DB_BACKUP_S3_PATH', 'db-backups'),
        ],

        'local' => [
            'driver' => 'disk',
            'disk' => env('DB_BACKUP_LOCAL_DISK', 'local'),
            'path' => env('DB_BACKUP_LOCAL_PATH', 'db-backups-archive'),
        ],

    ],

];
