<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_entitlements', function (Blueprint $table): void {
            $table->timestamp('last_event_occurred_at')->nullable();
        });

        Schema::table('subscription_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('aggregate_revision')->nullable();
            $table->string('projection_status', 16)->default('recorded');
            $table->unique(
                ['user_id', 'entitlement_code', 'aggregate_revision'],
                'subscription_events_aggregate_revision_unique'
            );
        });

        // Existing entitlement rows predate the event ledger.
        DB::table('user_entitlements')
            ->whereNull('last_event_occurred_at')
            ->update(['last_event_occurred_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('subscription_events', function (Blueprint $table): void {
            $table->dropUnique('subscription_events_aggregate_revision_unique');
            $table->dropColumn(['aggregate_revision', 'projection_status']);
        });

        Schema::table('user_entitlements', function (Blueprint $table): void {
            $table->dropColumn('last_event_occurred_at');
        });
    }
};
