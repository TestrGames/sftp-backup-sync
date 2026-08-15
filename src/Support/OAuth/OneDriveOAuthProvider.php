<?php

namespace Lisak\SftpBackupSync\Support\OAuth;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OneDriveOAuthProvider implements CloudOAuthProvider
{
    private const AUTHORIZE_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';

    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    // offline_access is required to receive a refresh_token at all.
    private const SCOPE = 'offline_access Files.ReadWrite';

    public function __construct(private readonly string $clientId, private readonly string $clientSecret) {}

    public function id(): string
    {
        return 'onedrive';
    }

    public function label(): string
    {
        return 'OneDrive';
    }

    public function getAuthorizeUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => self::SCOPE,
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
            'scope' => self::SCOPE,
        ]));
    }

    public function refresh(string $refreshToken): array
    {
        return $this->parseTokenResponse(Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => self::SCOPE,
        ]));
    }

    public function fetchAccountLabel(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)->get(self::GRAPH_BASE . '/me');

        if (!$response->successful()) {
            return null;
        }

        return $response->json('mail') ?? $response->json('userPrincipalName');
    }

    public function upload(string $accessToken, string $remotePath, string $filePath): void
    {
        $path = trim($remotePath, '/');
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

        // Path-based item addressing auto-creates any missing intermediate folders.
        $sessionResponse = Http::withToken($accessToken)
            ->post(self::GRAPH_BASE . "/me/drive/root:/{$encodedPath}:/createUploadSession", [
                'item' => ['@microsoft.graph.conflictBehavior' => 'replace'],
            ]);

        throw_unless($sessionResponse->successful(), new RuntimeException('Could not create OneDrive upload session: ' . $sessionResponse->body()));

        $uploadUrl = $sessionResponse->json('uploadUrl');
        throw_unless($uploadUrl, new RuntimeException('OneDrive did not return an upload session URL.'));

        ChunkedUploader::send($uploadUrl, $filePath);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int} */
    private function parseTokenResponse(Response $response): array
    {
        throw_unless($response->successful(), new RuntimeException('OneDrive token request failed: ' . $response->body()));

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => (int) ($response->json('expires_in') ?? 3600),
        ];
    }
}
