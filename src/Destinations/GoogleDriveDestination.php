<?php

namespace Doss\DbBackup\Destinations;

use Doss\DbBackup\Contracts\Destination;
use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use RuntimeException;

class GoogleDriveDestination implements Destination
{
    public function __construct(private array $config)
    {
    }

    public function upload(string $path, string $name, ?callable $progress = null): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("File [{$path}] not found.");
        }

        $folderId = $this->config['folder_id'] ?? null;

        if (! $folderId) {
            throw new RuntimeException('Google Drive folder_id is not set (DB_BACKUP_GOOGLE_FOLDER_ID).');
        }

        $client = $this->client();
        $service = new Drive($client);

        $metadata = new DriveFile([
            'name' => $name,
            'parents' => [$folderId],
        ]);

        $chunkSize = (int) ($this->config['chunk_size'] ?? 8 * 1024 * 1024);
        $size = filesize($path);
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';

        // Resumable upload in chunks, so large dumps are not read into memory at once.
        $client->setDefer(true);
        $request = $service->files->create($metadata, [
            'fields' => 'id,name,webViewLink',
            'supportsAllDrives' => true,
        ]);

        $media = new MediaFileUpload($client, $request, $mimeType, null, true, $chunkSize);
        $media->setFileSize($size);

        $handle = fopen($path, 'rb');
        $status = false;
        $sent = 0;

        try {
            while (! $status && ! feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                $status = $media->nextChunk($chunk);
                $sent += strlen($chunk);

                if ($progress) {
                    $progress(min($sent, $size), $size);
                }
            }
        } finally {
            fclose($handle);
            $client->setDefer(false);
        }

        if (! $status instanceof DriveFile) {
            throw new RuntimeException('Upload to Google Drive did not complete.');
        }

        return $status->getWebViewLink() ?: 'Drive file ID '.$status->getId();
    }

    public function client(): Client
    {
        $client = $this->baseClient();

        match ($this->config['auth'] ?? 'service_account') {
            'service_account' => $client->setAuthConfig($this->requireFile('service_account_json', 'Service account')),
            'oauth' => $this->useOAuth($client),
            default => throw new RuntimeException('Google "auth" must be "service_account" or "oauth".'),
        };

        return $client;
    }

    /**
     * OAuth client without a token, used by the db-backup:google-auth command.
     */
    public function oauthClient(): Client
    {
        $client = $this->baseClient();
        $client->setAuthConfig($this->requireFile('oauth_client_json', 'OAuth client secret'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    public function saveToken(array $token): string
    {
        $path = $this->config['oauth_token_json'] ?? null;

        if (! $path) {
            throw new RuntimeException('Google "oauth_token_json" path is not set.');
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, json_encode($token, JSON_PRETTY_PRINT));
        chmod($path, 0600);

        return $path;
    }

    private function baseClient(): Client
    {
        $client = new Client();
        $client->setApplicationName($this->config['application_name'] ?? 'Laravel DB Backup');
        $client->setScopes([Drive::DRIVE_FILE]);

        return $client;
    }

    private function useOAuth(Client $client): void
    {
        $client->setAuthConfig($this->requireFile('oauth_client_json', 'OAuth client secret'));
        $client->setAccessType('offline');

        $tokenPath = $this->config['oauth_token_json'] ?? null;

        if (! $tokenPath || ! is_file($tokenPath)) {
            throw new RuntimeException('No Google OAuth token found. Run `php artisan db-backup:google-auth` first.');
        }

        $client->setAccessToken(json_decode(file_get_contents($tokenPath), true));

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();

            if (! $refreshToken) {
                throw new RuntimeException('Google OAuth token expired and has no refresh token. Run `php artisan db-backup:google-auth` again.');
            }

            $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);

            if (isset($token['error'])) {
                throw new RuntimeException('Unable to refresh Google OAuth token: '.($token['error_description'] ?? $token['error']));
            }

            // Google does not always send the refresh token back, so keep the old one.
            $token['refresh_token'] ??= $refreshToken;
            $this->saveToken($token);
        }
    }

    private function requireFile(string $key, string $label): string
    {
        $path = $this->config[$key] ?? null;

        if (! $path || ! is_file($path)) {
            throw new RuntimeException("{$label} file not found at [{$path}].");
        }

        return $path;
    }
}
