<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->string('protocol')->default('sftp')->after('server_id');
            $table->string('base_url')->nullable()->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->dropColumn(['protocol', 'base_url']);
        });
    }
};
