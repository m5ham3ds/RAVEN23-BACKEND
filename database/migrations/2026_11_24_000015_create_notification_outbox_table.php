<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_outbox')) {
            return;
        }

        Schema::create('notification_outbox', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('idempotency_key', 64)->unique();
            $table->string('notification_type', 40);
            $table->longText('encrypted_payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error_type', 190)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['status', 'last_dispatched_at']);
        });
    }

    public function down(): void
    {
        // Forward-only: do not discard pending or delivery-audit records on rollback.
    }
};
