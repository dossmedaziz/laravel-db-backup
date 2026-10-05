<?php

namespace Doss\DbBackup\Commands;

use Doss\DbBackup\DestinationManager;
use Doss\DbBackup\Destinations\GoogleDriveDestination;
use Illuminate\Console\Command;
use Throwable;

class GoogleAuthCommand extends Command
{
    protected $signature = 'db-backup:google-auth
                            {destination=google : Name of the Google destination in config/db-backup.php}';

    protected $description = 'Authorize Google Drive with OAuth and store the token (only needed for auth=oauth)';

    public function handle(DestinationManager $manager): int
    {
        try {
            $destination = $manager->destination($this->argument('destination'));

            if (! $destination instanceof GoogleDriveDestination) {
                $this->components->error("Destination [{$this->argument('destination')}] does not use the google driver.");

                return self::FAILURE;
            }

            $client = $destination->oauthClient();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Open this link in your browser and allow access:');
        $this->newLine();
        $this->line($client->createAuthUrl());
        $this->newLine();

        $code = trim((string) $this->ask('Paste the verification code (or the full redirect URL)'));

        // Accept the whole redirect URL, e.g. http://localhost/?code=...&scope=...
        if (str_contains($code, 'code=')) {
            parse_str((string) parse_url($code, PHP_URL_QUERY), $query);
            $code = $query['code'] ?? $code;
        }

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            $this->components->error('Authorization failed: '.($token['error_description'] ?? $token['error']));

            return self::FAILURE;
        }

        $this->components->info('Token saved to '.$destination->saveToken($token));

        return self::SUCCESS;
    }
}
