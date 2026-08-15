<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->text('oauth_access_token')->nullable();
            $table->text('oauth_refresh_token')->nullable();
            $table->timestamp('oauth_expires_at')->nullable();
            $table->string('oauth_account_label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->dropColumn(['oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at', 'oauth_account_label']);
        });
    }
};
