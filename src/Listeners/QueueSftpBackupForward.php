<?php

namespace Lisak\SftpBackupSync\Listeners;

use App\Events\Server\BackupCompleted;
use Lisak\SftpBackupSync\Jobs\PushBackupToSftp;
use Lisak\SftpBackupSync\Models\SftpBackupTarget;

class QueueSftpBackupForward
{
    public function handle(BackupCompleted $event): void
    {
        if (!$event->backup->is_successful) {
            return;
        }

        $hasEnabledTarget = SftpBackupTarget::query()
            ->where('server_id', $event->backup->server_id)
            ->where('enabled', true)
            ->exists();

        if (!$hasEnabledTarget) {
            return;
        }

        PushBackupToSftp::dispatch($event->backup);
    }
}
