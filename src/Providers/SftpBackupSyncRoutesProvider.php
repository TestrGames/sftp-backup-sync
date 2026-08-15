<?php

namespace Lisak\SftpBackupSync\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;
use Lisak\SftpBackupSync\Http\Controllers\OAuthConnectController;

class SftpBackupSyncRoutesProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            // Bare routes registered here get zero middleware by default --
            // 'web' for the session/CSRF, 'auth' so `$request->user()` is the
            // logged-in panel user and guests get bounced to the real login.
            Route::middleware(['web', 'auth'])
                ->prefix('plugin/sftp-backup-sync')
                ->name('sftp-backup-sync.')
                ->group(function () {
                    Route::get('/{protocol}/connect/{server}', [OAuthConnectController::class, 'connect'])
                        ->name('connect');

                    Route::get('/{protocol}/callback', [OAuthConnectController::class, 'callback'])
                        ->name('callback');
                });
        });
    }
}
