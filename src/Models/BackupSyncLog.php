<?php

namespace Lisak\SftpBackupSync\Models;

use App\Models\Backup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupSyncLog extends Model
{
    protected $table = 'backup_sftp_sync_logs';

    protected $fillable = [
        'backup_id',
        'sftp_backup_target_id',
        'status',
        'error',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SftpBackupTarget::class, 'sftp_backup_target_id');
    }
}
