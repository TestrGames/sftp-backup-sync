<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            // How many of the newest backups stay on the panel itself. NULL (or 0)
            // means "keep everything", which is what existing installs keep doing.
            $table->unsignedInteger('keep_local_backups')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->dropColumn('keep_local_backups');
        });
    }
};
