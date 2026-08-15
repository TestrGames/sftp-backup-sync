<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sftp_backup_targets', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->unique('server_id');

            $table->boolean('enabled')->default(false);
            $table->string('host');
            $table->unsignedInteger('port')->default(22);
            $table->string('username');
            $table->string('auth_method')->default('password');
            $table->text('password')->nullable();
            $table->text('private_key')->nullable();
            $table->text('passphrase')->nullable();
            $table->string('remote_path')->default('/');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sftp_backup_targets');
    }
};
