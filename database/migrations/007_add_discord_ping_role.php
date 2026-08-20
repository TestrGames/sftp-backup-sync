<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->string('discord_ping_role_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->dropColumn('discord_ping_role_id');
        });
    }
};
