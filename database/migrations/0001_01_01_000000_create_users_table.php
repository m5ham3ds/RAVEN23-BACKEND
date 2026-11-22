<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create Laravel's baseline tables only when they do not already exist.
     *
     * RAVEN23 may already have users/session tables from an earlier generated
     * Laravel skeleton. This migration must be safe to introduce into that
     * deployment: it may add missing framework tables, but it must never
     * recreate, truncate, rename or otherwise replace an existing table.
     */
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table): void {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    /**
     * Never drop baseline tables during rollback.
     *
     * Some deployments may have owned these tables before this stable
     * migration ID was introduced. A destructive rollback could delete user
     * accounts or sessions that predate this migration.
     */
    public function down(): void
    {
        // Intentionally non-destructive.
    }
};
