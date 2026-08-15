<?php

namespace Lisak\SftpBackupSync\Support\OAuth;

use RuntimeException;

class CloudOAuthProviderFactory
{
    public static function make(string $protocol): CloudOAuthProvider
    {
        return match ($protocol) {
            'onedrive' => new OneDriveOAuthProvider(
                (string) config('sftp-backup-sync.onedrive_client_id'),
                (string) config('sftp-backup-sync.onedrive_client_secret'),
            ),
            'google_drive' => new GoogleDriveOAuthProvider(
                (string) config('sftp-backup-sync.google_client_id'),
                (string) config('sftp-backup-sync.google_client_secret'),
            ),
            default => throw new RuntimeException("Unknown OAuth protocol '{$protocol}'."),
        };
    }

    /** @return string[] */
    public static function oauthProtocols(): array
    {
        return ['onedrive', 'google_drive'];
    }
}
