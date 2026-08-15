<?php

namespace Lisak\SftpBackupSync\Providers;

use App\Events\Server\BackupCompleted;
use App\Models\Server;
use App\Models\Subuser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Lisak\SftpBackupSync\Listeners\QueueSftpBackupForward;
use Lisak\SftpBackupSync\Models\SftpBackupTarget;

class SftpBackupSyncPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // Pure array bookkeeping (no trans()/component construction), safe to run
        // during the register() phase. Grants the server owner access by default
        // (ServerPolicy::before() lets owners bypass permission checks); a subuser
        // needs this explicitly assigned to reach the Backup Sync page.
        Subuser::registerCustomPermissions('backup_sync', ['update'], 'tabler-cloud-upload', false);
    }

    public function boot(): void
    {
        Server::resolveRelationUsing('backupSyncTarget', fn (Server $server) => $server->hasOne(SftpBackupTarget::class));

        Event::listen(BackupCompleted::class, QueueSftpBackupForward::class);
    }
}
