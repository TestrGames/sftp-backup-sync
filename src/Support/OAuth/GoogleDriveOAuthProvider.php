<?php

namespace Lisak\SftpBackupSync\Support\OAuth;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleDriveOAuthProvider implements CloudOAuthProvider
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const DRIVE_BASE = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3/files';

    // drive.file (not the broad "drive" scope): the app can only see/manage
    // files it created itself, not the user's whole Drive.
    private const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public function __construct(private readonly string $clientId, private readonly string $clientSecret) {}

    public function id(): string
    {
        return 'google_drive';
    }

    public function label(): string
    {
        return 'Google Drive';
    }

    public function getAuthorizeUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            // Google only returns a refresh_token on the first consent unless
            // prompt=consent forces it every time -- we need one every connect.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        return $this->parseTokenResponse(Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]));
    }

    public function refresh(string $refreshToken): array
    {
        return $this->parseTokenResponse(Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]));
    }

    public function fetchAccountLabel(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo');

        if (!$response->successful()) {
            return null;
        }

        return $response->json('email');
    }

    public function upload(string $accessToken, string $remotePath, string $filePath): void
    {
        $folderId = $this->resolveFolderId($accessToken, trim($remotePath, '/'));

        $metadata = ['name' => basename($filePath)];
        if ($folderId) {
            $metadata['parents'] = [$folderId];
        }

        $sessionResponse = Http::withToken($accessToken)
            ->withHeaders(['X-Upload-Content-Type' => 'application/octet-stream'])
            ->post(self::UPLOAD_BASE . '?uploadType=resumable', $metadata);

        throw_unless($sessionResponse->successful(), new RuntimeException('Could not create Google Drive upload session: ' . $sessionResponse->body()));

        $uploadUrl = $sessionResponse->header('Location');
        throw_unless($uploadUrl, new RuntimeException('Google Drive did not return an upload session URL.'));

        ChunkedUploader::send($uploadUrl, $filePath);
    }

    /**
     * Drive has no real path concept -- folders are just files with a
     * folder mimetype, addressed by id via `parents`. Walk (and create, if
     * missing) one path segment at a time to resolve/create the final
     * folder id that new backups should be uploaded into.
     */
    private function resolveFolderId(string $accessToken, string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $parentId = null;

        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $query = sprintf(
                "name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false and '%s' in parents",
                addslashes($segment),
                $parentId ?: 'root',
            );

            $listResponse = Http::withToken($accessToken)->get(self::DRIVE_BASE . '/files', [
                'q' => $query,
                'fields' => 'files(id, name)',
                'spaces' => 'drive',
            ]);

            throw_unless($listResponse->successful(), new RuntimeException('Could not look up Google Drive folder: ' . $listResponse->body()));

            $existingId = $listResponse->json('files.0.id');

            if ($existingId) {
                $parentId = $existingId;

                continue;
            }

            $createResponse = Http::withToken($accessToken)->post(self::DRIVE_BASE . '/files', [
                'name' => $segment,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => $parentId ? [$parentId] : [],
            ]);

            throw_unless($createResponse->successful(), new RuntimeException('Could not create Google Drive folder: ' . $createResponse->body()));

            $parentId = $createResponse->json('id');
        }

        return $parentId;
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int} */
    private function parseTokenResponse(Response $response): array
    {
        throw_unless($response->successful(), new RuntimeException('Google token request failed: ' . $response->body()));

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => (int) ($response->json('expires_in') ?? 3600),
        ];
    }
}
