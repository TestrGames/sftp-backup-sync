<?php

namespace Lisak\SftpBackupSync\Support\OAuth;

interface CloudOAuthProvider
{
    public function id(): string;

    public function label(): string;

    public function getAuthorizeUrl(string $redirectUri, string $state): string;

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int} */
    public function exchangeCode(string $code, string $redirectUri): array;

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int} */
    public function refresh(string $refreshToken): array;

    public function fetchAccountLabel(string $accessToken): ?string;

    public function upload(string $accessToken, string $remotePath, string $filePath): void;
}
