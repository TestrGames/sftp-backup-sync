<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->text('discord_webhook_url')->nullable();
            $table->boolean('notify_on_success')->default(false);
            $table->boolean('notify_on_failure')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->dropColumn(['discord_webhook_url', 'notify_on_success', 'notify_on_failure']);
        });
    }
};
