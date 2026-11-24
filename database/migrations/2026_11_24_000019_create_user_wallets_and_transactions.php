<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_wallets')) {
            Schema::create('user_wallets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->char('currency', 3)->default('USD');
                $table->unsignedBigInteger('balance_cents')->default(0);
                $table->unsignedBigInteger('spendable_cents')->default(0);
                $table->string('status', 16)->default('active');
                $table->boolean('frozen')->default(false);
                $table->unsignedInteger('revision')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('wallet_id')->constrained('user_wallets')->cascadeOnDelete();
                $table->string('type', 32);
                $table->string('source', 24);
                $table->bigInteger('amount_cents');
                $table->unsignedBigInteger('balance_after_cents');
                $table->string('reference', 128)->nullable();
                $table->string('idempotency_key', 128);
                $table->char('payload_hash', 64);
                $table->timestamp('period_starts_at')->nullable();
                $table->timestamp('period_ends_at')->nullable();
                $table->timestamp('recorded_at');
                $table->unique(['wallet_id', 'idempotency_key'], 'wallet_transactions_wallet_key_unique');
                $table->index(['wallet_id', 'recorded_at', 'id'], 'wallet_transactions_history_index');
            });
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION raven23_reject_wallet_transaction_mutation()
                RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE'
                       AND NOT EXISTS (SELECT 1 FROM user_wallets WHERE id = OLD.wallet_id) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'wallet_transactions are append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER wallet_transactions_append_only
                BEFORE UPDATE OR DELETE ON wallet_transactions
                FOR EACH ROW EXECUTE FUNCTION raven23_reject_wallet_transaction_mutation();
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER wallet_transactions_no_update
                BEFORE UPDATE ON wallet_transactions
                BEGIN
                    SELECT RAISE(ABORT, 'wallet_transactions are append-only');
                END;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER wallet_transactions_no_direct_delete
                BEFORE DELETE ON wallet_transactions
                WHEN EXISTS (SELECT 1 FROM user_wallets WHERE id = OLD.wallet_id)
                BEGIN
                    SELECT RAISE(ABORT, 'wallet_transactions are append-only');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_append_only ON wallet_transactions');
            DB::unprepared('DROP FUNCTION IF EXISTS raven23_reject_wallet_transaction_mutation()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_no_direct_delete');
        }

        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('user_wallets');
    }
};
