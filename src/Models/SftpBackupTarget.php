<?php

namespace Lisak\SftpBackupSync\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SftpBackupTarget extends Model
{
    protected $table = 'sftp_backup_targets';

    protected $fillable = [
        'server_id',
        'enabled',
        'protocol',
        'host',
        'port',
        'base_url',
        'username',
        'auth_method',
        'password',
        'private_key',
        'passphrase',
        'remote_path',
        'oauth_access_token',
        'oauth_refresh_token',
        'oauth_expires_at',
        'oauth_account_label',
        'discord_webhook_url',
        'notify_on_success',
        'notify_on_failure',
        'last_synced_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'password' => 'encrypted',
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'oauth_expires_at' => 'datetime',
            'discord_webhook_url' => 'encrypted',
            'notify_on_success' => 'boolean',
            'notify_on_failure' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(BackupSyncLog::class, 'sftp_backup_target_id');
    }
}
