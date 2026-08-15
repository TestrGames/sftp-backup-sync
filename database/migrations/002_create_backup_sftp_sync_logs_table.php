<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_sftp_sync_logs', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedBigInteger('backup_id');
            $table->foreign('backup_id')->references('id')->on('backups')->cascadeOnDelete();

            $table->unsignedInteger('sftp_backup_target_id');
            $table->foreign('sftp_backup_target_id')->references('id')->on('sftp_backup_targets')->cascadeOnDelete();

            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['backup_id', 'sftp_backup_target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_sftp_sync_logs');
    }
};
