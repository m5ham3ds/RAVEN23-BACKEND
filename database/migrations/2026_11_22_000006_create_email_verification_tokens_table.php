<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_verification_tokens')) {
            return;
        }

        Schema::create('email_verification_tokens', static function (Blueprint $table): void {
            // Deliberately no FK: the source users table can predate this migration.
            $table->unsignedBigInteger('user_id')->primary();
            $table->char('token_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        // Preserve verification-token evidence on rollback.
    }
};
