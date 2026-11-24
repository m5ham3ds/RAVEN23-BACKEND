<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_events')) {
            return;
        }

        Schema::create('subscription_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_type', 24);
            $table->string('entitlement_code', 80);
            $table->string('source', 24);
            $table->string('source_reference', 128)->nullable();
            $table->string('idempotency_key', 128);
            $table->string('reason_code', 80)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->char('event_hash', 64);
            $table->timestamp('recorded_at');

            $table->unique(['source', 'idempotency_key'], 'subscription_events_source_key_unique');
            $table->index(['user_id', 'entitlement_code', 'recorded_at'], 'subscription_events_owner_history_index');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION raven23_reject_subscription_event_mutation()
                RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE'
                       AND NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.user_id) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'subscription_events are append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER subscription_events_append_only
                BEFORE UPDATE OR DELETE ON subscription_events
                FOR EACH ROW EXECUTE FUNCTION raven23_reject_subscription_event_mutation();
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER subscription_events_no_update
                BEFORE UPDATE ON subscription_events
                BEGIN
                    SELECT RAISE(ABORT, 'subscription_events are append-only');
                END;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER subscription_events_no_direct_delete
                BEFORE DELETE ON subscription_events
                WHEN EXISTS (SELECT 1 FROM users WHERE id = OLD.user_id)
                BEGIN
                    SELECT RAISE(ABORT, 'subscription_events are append-only');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS subscription_events_append_only ON subscription_events');
            DB::unprepared('DROP FUNCTION IF EXISTS raven23_reject_subscription_event_mutation()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS subscription_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS subscription_events_no_direct_delete');
        }

        Schema::dropIfExists('subscription_events');
    }
};
