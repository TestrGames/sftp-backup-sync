<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            // Both columns were required back when SFTP was the only protocol.
            // WebDAV doesn't use `host` (it has `base_url` instead), and
            // OneDrive/Google Drive use neither -- they authenticate via OAuth.
            $table->string('host')->nullable()->change();
            $table->string('username')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->string('host')->nullable(false)->change();
            $table->string('username')->nullable(false)->change();
        });
    }
};
