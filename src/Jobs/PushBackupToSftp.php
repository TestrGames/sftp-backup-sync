<?php

namespace Lisak\SftpBackupSync\Jobs;

use App\Extensions\BackupAdapter\BackupAdapterService;
use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\WebDAV\WebDAVAdapter;
use Lisak\SftpBackupSync\Models\BackupSyncLog;
use Lisak\SftpBackupSync\Models\SftpBackupTarget;
use Lisak\SftpBackupSync\Support\OAuth\CloudOAuthProvider;
use Lisak\SftpBackupSync\Support\OAuth\CloudOAuthProviderFactory;
use RuntimeException;
use Sabre\DAV\Client as WebDAVClient;
use Throwable;

class PushBackupToSftp implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(public Backup $backup) {}

    public function handle(BackupAdapterService $adapters): void
    {
        if (!$this->backup->is_successful) {
            return;
        }

        /** @var SftpBackupTarget|null $target */
        $target = SftpBackupTarget::query()
            ->where('server_id', $this->backup->server_id)
            ->where('enabled', true)
            ->first();

        if (!$target) {
            return;
        }

        $log = BackupSyncLog::query()->updateOrCreate(
            ['backup_id' => $this->backup->id, 'sftp_backup_target_id' => $target->id],
            ['status' => 'pending', 'error' => null],
        );

        $tmpPath = tempnam(sys_get_temp_dir(), 'sftpbak_');

        try {
            $schema = $adapters->get($this->backup->backupHost->schema);
            throw_unless($schema, new RuntimeException("Unknown backup adapter '{$this->backup->backupHost->schema}'."));

            $url = $schema->getDownloadLink($this->backup, $this->backup->server->user);

            $response = Http::withOptions(['sink' => $tmpPath])->timeout(0)->get($url);
            throw_unless($response->successful(), new RuntimeException("Could not download backup from source: HTTP {$response->status()}."));

            if (in_array($target->protocol, CloudOAuthProviderFactory::oauthProtocols(), true)) {
                $this->pushViaOAuth($target, $tmpPath);
            } else {
                $this->pushViaFilesystem($target, $tmpPath);
            }

            $log->update(['status' => 'success', 'error' => null, 'synced_at' => now()]);
            $target->update(['last_synced_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            $fullMessage = $this->describeException($exception);

            Log::warning("sftp-backup-sync: failed to sync backup {$this->backup->uuid}: {$fullMessage}");

            $log->update(['status' => 'failed', 'error' => $fullMessage]);
            $target->update(['last_error' => $fullMessage]);

            throw $exception;
        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    /**
     * Flysystem adapters (WebDAV in particular) tend to throw a generic
     * wrapper ("Unable to check existence for: ...") whose own message
     * says nothing useful -- the actual cause (auth failure, timeout,
     * TLS error, wrong path) lives one or more levels down in
     * getPrevious(). Walk the whole chain so the stored error is
     * actually actionable.
     */
    private function describeException(Throwable $exception): string
    {
        $parts = [$exception->getMessage() ?: get_class($exception)];

        $cause = $exception->getPrevious();
        while ($cause) {
            $parts[] = $cause->getMessage() ?: get_class($cause);
            $cause = $cause->getPrevious();
        }

        return implode(' | caused by: ', $parts);
    }

    private function pushViaFilesystem(SftpBackupTarget $target, string $tmpPath): void
    {
        $filesystem = $this->buildFilesystem($target);

        $stream = fopen($tmpPath, 'r');
        throw_unless($stream, new RuntimeException('Could not open downloaded backup for reading.'));

        try {
            $remotePath = trim($this->backup->server->uuid, '/') . '/' . $this->backup->uuid . '.tar.gz';
            $filesystem->writeStream($remotePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function pushViaOAuth(SftpBackupTarget $target, string $tmpPath): void
    {
        $provider = CloudOAuthProviderFactory::make($target->protocol);
        $accessToken = $this->ensureFreshAccessToken($target, $provider);

        $remotePath = trim($target->remote_path ?: '', '/') . '/'
            . trim($this->backup->server->uuid, '/') . '/'
            . $this->backup->uuid . '.tar.gz';

        $provider->upload($accessToken, ltrim($remotePath, '/'), $tmpPath);
    }

    private function ensureFreshAccessToken(SftpBackupTarget $target, CloudOAuthProvider $provider): string
    {
        $stillValid = $target->oauth_access_token
            && $target->oauth_expires_at
            && $target->oauth_expires_at->subMinute()->isFuture();

        if ($stillValid) {
            return $target->oauth_access_token;
        }

        throw_unless($target->oauth_refresh_token, new RuntimeException(
            'This destination is not connected yet — open Backup Sync and connect the account.',
        ));

        $tokens = $provider->refresh($target->oauth_refresh_token);

        $target->update([
            'oauth_access_token' => $tokens['access_token'],
            'oauth_refresh_token' => $tokens['refresh_token'] ?? $target->oauth_refresh_token,
            'oauth_expires_at' => now()->addSeconds($tokens['expires_in']),
        ]);

        return $tokens['access_token'];
    }

    private function buildFilesystem(SftpBackupTarget $target): Filesystem
    {
        return match ($target->protocol) {
            'webdav' => $this->buildWebDavFilesystem($target),
            default => $this->buildSftpFilesystem($target),
        };
    }

    private function buildSftpFilesystem(SftpBackupTarget $target): Filesystem
    {
        $provider = new SftpConnectionProvider(
            host: $target->host,
            username: $target->username,
            password: $target->auth_method === 'password' ? $target->password : null,
            privateKey: $target->auth_method === 'private_key' ? $target->private_key : null,
            passphrase: $target->passphrase ?: null,
            port: $target->port,
            timeout: 30,
        );

        return new Filesystem(new SftpAdapter($provider, $target->remote_path ?: '/'));
    }

    private function buildWebDavFilesystem(SftpBackupTarget $target): Filesystem
    {
        $client = new WebDAVClient([
            'baseUri' => $target->base_url,
            'userName' => $target->username,
            'password' => $target->password,
        ]);

        return new Filesystem(new WebDAVAdapter($client, $target->remote_path ?: ''));
    }
}
