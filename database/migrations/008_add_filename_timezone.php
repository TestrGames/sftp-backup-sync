<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            // Either the literal 'profile' (resolve the server owner's profile
            // timezone at upload time, so it tracks later profile changes) or a
            // real PHP timezone identifier such as 'Europe/Prague'. Defaulting
            // to 'profile' is not a behaviour change for existing installs:
            // Pelican's own default for User::timezone is 'UTC', which is what
            // these filenames were already being formatted in.
            $table->string('filename_timezone')->default('profile');
        });
    }

    public function down(): void
    {
        Schema::table('sftp_backup_targets', function (Blueprint $table) {
            $table->dropColumn('filename_timezone');
        });
    }
};
