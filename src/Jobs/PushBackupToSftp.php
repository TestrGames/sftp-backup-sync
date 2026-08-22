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
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use Lisak\SftpBackupSync\Models\BackupSyncLog;
use Lisak\SftpBackupSync\Models\SftpBackupTarget;
use Lisak\SftpBackupSync\Support\OAuth\CloudOAuthProvider;
use Lisak\SftpBackupSync\Support\OAuth\CloudOAuthProviderFactory;
use RuntimeException;
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
            } elseif ($target->protocol === 'webdav') {
                $this->pushViaWebDav($target, $tmpPath);
            } else {
                $this->pushViaSftp($target, $tmpPath);
            }

            $log->update(['status' => 'success', 'error' => null, 'synced_at' => now()]);
            $target->update(['last_synced_at' => now(), 'last_error' => null]);

            if ($target->notify_on_success) {
                $this->sendDiscordNotification($target, success: true);
            }
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
     * Laravel calls this automatically exactly once, after the job has
     * exhausted all of its retries ($tries) -- not on every individual
     * attempt. That's deliberate: the catch block in handle() runs (and
     * updates last_error) on every attempt since it's cheap and purely
     * informational, but a Discord ping should only happen once the sync
     * has genuinely, finally failed, not once per retry.
     */
    public function failed(Throwable $exception): void
    {
        $target = SftpBackupTarget::query()
            ->where('server_id', $this->backup->server_id)
            ->where('enabled', true)
            ->first();

        if (!$target || !$target->notify_on_failure) {
            return;
        }

        $this->sendDiscordNotification($target, success: false, message: $this->describeException($exception));
    }

    private function sendDiscordNotification(SftpBackupTarget $target, bool $success, ?string $message = null): void
    {
        if (!$target->discord_webhook_url) {
            return;
        }

        try {
            $payload = [
                'embeds' => [array_filter([
                    'title' => $success ? '✅ Backup synced' : '❌ Backup sync failed',
                    'color' => $success ? 0x57F287 : 0xED4245,
                    'fields' => array_values(array_filter([
                        [
                            'name' => 'Server',
                            'value' => $this->backup->server?->name ?? "#{$this->backup->server_id}",
                            'inline' => true,
                        ],
                        [
                            'name' => 'Destination',
                            'value' => ucfirst(str_replace('_', ' ', $target->protocol)),
                            'inline' => true,
                        ],
                        [
                            'name' => 'Backup',
                            'value' => $this->backup->name ?: $this->backup->uuid,
                            'inline' => true,
                        ],
                        $message ? ['name' => 'Error', 'value' => Str::limit($message, 1000)] : null,
                    ])),
                    'timestamp' => now()->toIso8601String(),
                    'footer' => ['text' => 'SFTP Backup Sync'],
                ])],
            ];

            if (!$success && filled($target->discord_ping_role_id)) {
                $payload['content'] = $this->formatDiscordMention($target->discord_ping_role_id);
                // Discord does not reliably parse mentions found in `content`
                // into actual pings unless the payload explicitly allows it --
                // without this, the role name/embed can show up with no
                // notification actually firing for anyone.
                $payload['allowed_mentions'] = $this->allowedMentionsFor($target->discord_ping_role_id);
            }

            Http::timeout(10)->post($target->discord_webhook_url, $payload);
        } catch (Throwable $exception) {
            // Best-effort only -- a broken/invalid webhook must never fail or
            // retry the actual sync job.
            Log::warning("sftp-backup-sync: failed to send Discord notification for backup {$this->backup->uuid}: {$exception->getMessage()}");
        }
    }

    /**
     * @return array{parse?: string[], roles?: string[]}
     */
    private function allowedMentionsFor(string $rawValue): array
    {
        $rawValue = trim($rawValue);

        if (strcasecmp($rawValue, 'everyone') === 0 || strcasecmp($rawValue, 'here') === 0) {
            return ['parse' => ['everyone']];
        }

        if (ctype_digit($rawValue)) {
            return ['roles' => [$rawValue]];
        }

        // Whatever was typed doesn't match either known shorthand, so we
        // don't know what kind of mention is embedded in it -- allow every
        // mention type rather than silently pinging no one.
        return ['parse' => ['everyone', 'roles', 'users']];
    }

    /**
     * Accepts a raw Discord role ID (the common case, copied from Discord's
     * "Copy Role ID" context menu), or the literal words "everyone"/"here"
     * for those broader pings, or -- if it already looks like a full
     * mention/anything else -- passes it through unchanged.
     */
    private function formatDiscordMention(string $value): string
    {
        $value = trim($value);

        return match (true) {
            strcasecmp($value, 'everyone') === 0 => '@everyone',
            strcasecmp($value, 'here') === 0 => '@here',
            ctype_digit($value) => "<@&{$value}>",
            default => $value,
        };
    }

    /**
     * Flysystem adapters tend to throw a generic wrapper whose own message
     * says nothing useful -- the actual cause often lives one or more
     * levels down in getPrevious(). Walk the whole chain so the stored
     * error is actually actionable.
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

    /**
     * Backups used to land as "<server-uuid>/<backup-uuid>.tar.gz", which
     * tells you nothing when you are staring at a folder listing on the
     * destination. Both the folder and the file are named after the server
     * instead. A server name is free-form (spaces, diacritics, slashes), so
     * it gets slugged before it can become a path segment, and we fall back
     * to the UUID if slugging leaves nothing usable -- e.g. a name written
     * entirely in a script Str::slug() strips.
     */
    private function remoteDirectory(): string
    {
        return $this->serverSlug();
    }

    /**
     * The timestamp is the backup's own creation time (not "now"), so a
     * re-synced or retried backup keeps the same name instead of landing
     * twice under two different ones. It carries the time as well as the
     * date because several backups a day is normal, and same-day backups
     * sharing a name would silently overwrite each other.
     */
    private function remoteFileName(): string
    {
        $timestamp = ($this->backup->created_at ?? now())->format('Y-m-d_His');

        return $this->serverSlug() . '-' . $timestamp . '.tar.gz';
    }

    private function serverSlug(): string
    {
        $slug = Str::slug((string) $this->backup->server?->name);

        return $slug !== ''
            ? $slug
            : trim((string) $this->backup->server?->uuid, '/');
    }

    private function pushViaSftp(SftpBackupTarget $target, string $tmpPath): void
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

        $filesystem = new Filesystem(new SftpAdapter($provider, $target->remote_path ?: '/'));

        $stream = fopen($tmpPath, 'r');
        throw_unless($stream, new RuntimeException('Could not open downloaded backup for reading.'));

        try {
            $remotePath = $this->remoteDirectory() . '/' . $this->remoteFileName();
            $filesystem->writeStream($remotePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Deliberately raw HTTP (MKCOL + PUT), not league/flysystem-webdav. That
     * library's WebDAVAdapter::createDirectory() reliably failed against a
     * real Nextcloud instance during testing (mis-detecting/mis-creating an
     * already-existing directory, with no useful underlying cause exposed) --
     * plain curl against the exact same URLs worked fine. This does the same
     * two requests curl was verified to succeed with: create each path
     * segment via MKCOL (tolerating "already exists"), then PUT the file.
     */
    private function pushViaWebDav(SftpBackupTarget $target, string $tmpPath): void
    {
        $baseUri = rtrim((string) $target->base_url, '/');

        $segments = array_values(array_filter(
            explode('/', trim($target->remote_path ?: '', '/') . '/' . $this->remoteDirectory()),
            fn (string $segment) => $segment !== '',
        ));

        $path = '';
        foreach ($segments as $segment) {
            $path .= '/' . rawurlencode($segment);
            $this->webDavRequest($target, 'MKCOL', $baseUri . $path . '/', [200, 201, 405]);
        }

        $fileUrl = $baseUri . $path . '/' . rawurlencode($this->remoteFileName());

        $stream = fopen($tmpPath, 'r');
        throw_unless($stream, new RuntimeException('Could not open downloaded backup for reading.'));

        try {
            $response = Http::withBasicAuth((string) $target->username, (string) $target->password)
                ->withBody($stream, 'application/octet-stream')
                ->timeout(0)
                ->send('PUT', $fileUrl);

            throw_unless(
                $response->successful(),
                new RuntimeException("Could not upload backup via WebDAV: HTTP {$response->status()} " . $response->body()),
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @param int[] $acceptableStatuses */
    private function webDavRequest(SftpBackupTarget $target, string $method, string $url, array $acceptableStatuses): void
    {
        $response = Http::withBasicAuth((string) $target->username, (string) $target->password)
            ->timeout(30)
            ->send($method, $url);

        throw_unless(
            in_array($response->status(), $acceptableStatuses, true),
            new RuntimeException("WebDAV {$method} failed for {$url}: HTTP {$response->status()} " . $response->body()),
        );
    }

    private function pushViaOAuth(SftpBackupTarget $target, string $tmpPath): void
    {
        $provider = CloudOAuthProviderFactory::make($target->protocol);
        $accessToken = $this->ensureFreshAccessToken($target, $provider);

        $remotePath = trim($target->remote_path ?: '', '/') . '/'
            . $this->remoteDirectory() . '/'
            . $this->remoteFileName();

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
}
