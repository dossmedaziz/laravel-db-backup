# Laravel DB Backup

Dump your Laravel database and upload it to one or more destinations:

```bash
php artisan db:backup
```

What it does:

1. Dumps the database into a folder: `db-backups/classquiz_2026-10-05-02-00/classquiz_2026-10-05-02-00.dump`
2. Zips that folder: `db-backups/classquiz_2026-10-05-02-00.zip`
3. Uploads the zip to every configured destination
4. With `--delete-local`, deletes the local zip once every upload has succeeded

- **Databases:** PostgreSQL (`pg_dump`), MySQL / MariaDB (`mysqldump`), SQLite.
  Connection details come from your existing `config/database.php`.
- **Destinations:** Google Drive, any Laravel filesystem disk (S3, FTP, SFTP, local...), or your own driver.

## Installation

```bash
composer require doss/laravel-db-backup
```

The service provider is auto-discovered. Publish the config to add or change destinations:

```bash
php artisan vendor:publish --tag=db-backup-config
```

### Installing from a local folder or GitHub (before publishing to Packagist)

Add a repository to your app's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../laravel-db-backup" }
]
```

or

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/dossmedaziz/laravel-db-backup" }
]
```

then run `composer require doss/laravel-db-backup:@dev`.

## Configuration

### Choosing destinations

`DB_BACKUP_DESTINATIONS` is a comma-separated list of destination names from `config/db-backup.php`.
The dump is uploaded to each of them:

```dotenv
DB_BACKUP_DESTINATIONS=google
# or several at once
DB_BACKUP_DESTINATIONS=google,s3
```

Destinations are defined in the config file. Each one has a `driver`:

```php
'destinations' => [
    'google' => ['driver' => 'google', /* ... */],
    's3'     => ['driver' => 'disk', 'disk' => 's3', 'path' => 'db-backups'],
    'local'  => ['driver' => 'disk', 'disk' => 'local', 'path' => 'db-backups-archive'],

    // You can add as many as you like, even several of the same driver:
    'google_client_x' => ['driver' => 'google', 'folder_id' => '...', /* ... */],
],
```

### General options

```dotenv
DB_BACKUP_CONNECTION=pgsql            # defaults to DB_CONNECTION
DB_BACKUP_PATH=/var/www/dbarchives    # where the dump is written first (default storage/app/db-backups)
DB_BACKUP_PREFIX=classquiz            # file name prefix (default APP_NAME)
DB_BACKUP_DELETE_LOCAL=false          # always delete the local zip after successful uploads (same as --delete-local)
DB_BACKUP_PG_DUMP=/usr/bin/pg_dump    # if not on PATH
DB_BACKUP_MYSQLDUMP=/usr/bin/mysqldump
```

## Drivers

### `google`: Google Drive

```dotenv
DB_BACKUP_GOOGLE_FOLDER_ID=your-folder-id   # from https://drive.google.com/drive/folders/<FOLDER_ID>
DB_BACKUP_GOOGLE_AUTH=service_account       # or oauth

DB_BACKUP_GOOGLE_SERVICE_ACCOUNT=/path/to/credentials.json

DB_BACKUP_GOOGLE_OAUTH_CLIENT=/path/to/client_secret.json
DB_BACKUP_GOOGLE_OAUTH_TOKEN=/path/to/token.json
```

**Service account:** enable the Google Drive API, create a service account and a JSON key, then share the
Drive folder with the service account e-mail as **Editor**.

> On personal Gmail accounts, service accounts have no storage quota of their own. If uploads fail with
> `storageQuotaExceeded`, use a Shared Drive or switch to OAuth.

**OAuth (your own Google account):** create an OAuth client of type **Desktop app**, download
`client_secret.json`, set `DB_BACKUP_GOOGLE_AUTH=oauth`, then run once:

```bash
php artisan db-backup:google-auth
```

Open the link, allow access, and paste the code (or the full redirect URL). The token is refreshed automatically
after that. For a second Google destination, pass its name: `php artisan db-backup:google-auth google_client_x`.

### `disk`: any Laravel filesystem disk

Uses a disk from `config/filesystems.php`, so S3, FTP, SFTP, etc. work the way they already do in Laravel
(install the Flysystem adapter for that disk, e.g. `league/flysystem-aws-s3-v3` for S3).

```dotenv
DB_BACKUP_S3_DISK=s3
DB_BACKUP_S3_PATH=db-backups
```

### Custom drivers

Implement `Doss\DbBackup\Contracts\Destination`:

```php
use Doss\DbBackup\Contracts\Destination;

class DropboxDestination implements Destination
{
    public function __construct(private array $config) {}

    public function upload(string $path, string $name, ?callable $progress = null): string
    {
        // upload $path as $name using $this->config ...
        // throw an exception on failure
        return 'dropbox:/backups/'.$name; // shown in the console output
    }
}
```

Register it in a service provider's `boot()` method:

```php
use Doss\DbBackup\DestinationManager;

$this->app->make(DestinationManager::class)->extend('dropbox', fn (array $config) => new DropboxDestination($config));
```

Then add it to `config/db-backup.php` and `.env`:

```php
'dropbox' => ['driver' => 'dropbox', 'token' => env('DB_BACKUP_DROPBOX_TOKEN')],
```

```dotenv
DB_BACKUP_DESTINATIONS=google,dropbox
```

## Usage

```bash
php artisan db:backup                     # dump, zip, upload to DB_BACKUP_DESTINATIONS (local zip is kept)
php artisan db:backup --delete-local      # same, then delete the local zip after upload
php artisan db:backup --to=s3             # override the destinations
php artisan db:backup --to=google,local
php artisan db:backup --no-upload         # only create the local zip
php artisan db:backup --connection=mysql  # back up another connection
```

If any upload fails, the other destinations are still tried, the error is logged, and the command exits with a
non-zero code. The local zip is never deleted in that case, even with `--delete-local`.

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

- PHP 8.1+, Laravel 10–13
- `pg_dump` or `mysqldump` installed on the server (matching your DB server version)

## License

MIT
