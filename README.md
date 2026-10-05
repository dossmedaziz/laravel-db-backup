# Laravel DB Backup

Back up your Laravel database to Google Drive:

```bash
php artisan db:backup
```

What it does:

1. Dumps the database into a folder: `db-backups/classquiz_2026-10-05-02-00/classquiz_2026-10-05-02-00.dump`
2. Zips that folder: `db-backups/classquiz_2026-10-05-02-00.zip`
3. Uploads the zip to Google Drive
4. With `--delete-local`, deletes the local zip once the upload has succeeded

Supported databases: PostgreSQL (`pg_dump`), MySQL / MariaDB (`mysqldump`), SQLite.
Connection details come from your existing `config/database.php`.

## Installation

```bash
composer require doss/laravel-db-backup
```

The service provider is auto-discovered. Publish the config file:

```bash
php artisan vendor:publish --tag=db-backup-config
```

## Configuration

### `.env`

```dotenv
DB_BACKUP_GOOGLE_FOLDER_ID=your-folder-id   # from https://drive.google.com/drive/folders/<FOLDER_ID>
DB_BACKUP_GOOGLE_AUTH=service_account       # or oauth

DB_BACKUP_GOOGLE_SERVICE_ACCOUNT=/path/to/credentials.json
```

### `config/db-backup.php`

Everything else is set directly in the config file:

```php
'dump' => [
    'path' => storage_path('app/db-backups'), // where the folder and zip are created
    'filename_format' => 'Y-m-d-H-i',         // date format used in the name
    'prefix' => env('APP_NAME', 'laravel'),   // name prefix, e.g. "classquiz"
    'delete_local' => false,                  // true = always delete the local zip after upload
    'timeout' => 3600,                        // max seconds for the dump command

    'binaries' => [
        'pg_dump' => 'pg_dump',               // full path if not on PATH, e.g. /usr/bin/pg_dump
        'mysqldump' => 'mysqldump',
    ],
    // ...
],
```

## Google Drive setup

### Service account (default)

1. In Google Cloud Console, enable the **Google Drive API**.
2. Create a service account and download its JSON key.
3. Set `DB_BACKUP_GOOGLE_SERVICE_ACCOUNT` to the path of that file.
4. Share the Drive folder with the service account e-mail (`...@...iam.gserviceaccount.com`) as **Editor**.

> On personal Gmail accounts, service accounts have no storage quota of their own. If uploads fail with
> `storageQuotaExceeded`, use a Shared Drive or switch to OAuth.

### OAuth (your own Google account)

1. Create an OAuth client of type **Desktop app** and download `client_secret.json`.
2. Put it at `storage/app/google/client_secret.json` (or change `oauth_client_json` in the config).
3. Set `DB_BACKUP_GOOGLE_AUTH=oauth`.
4. Run once:

   ```bash
   php artisan db-backup:google-auth
   ```

   Open the link, allow access, and paste the code (or the full redirect URL). The token is saved to
   `storage/app/google/token.json` and refreshed automatically after that.

## Usage

```bash
php artisan db:backup                     # dump, zip, upload (local zip is kept)
php artisan db:backup --delete-local      # same, then delete the local zip after upload
php artisan db:backup --no-upload         # only create the local zip
php artisan db:backup --connection=mysql  # back up another connection
```

If the upload fails, the error is logged, the command exits with a non-zero code, and the local zip is kept,
even with `--delete-local`.

## Scheduling

In `routes/console.php` (Laravel 11+):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('db:backup --delete-local')->dailyAt('02:00')->withoutOverlapping();
```

or in `app/Console/Kernel.php` (Laravel 10):

```php
$schedule->command('db:backup --delete-local')->dailyAt('02:00')->withoutOverlapping();
```

Make sure the Laravel scheduler cron is running:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## Requirements

- PHP 8.1+ with the `zip` extension, Laravel 10–13
- `pg_dump` or `mysqldump` installed on the server (matching your DB server version)

## License

MIT
