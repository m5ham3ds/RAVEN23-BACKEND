<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'is_guest')) {
            Schema::table('users', static function (Blueprint $table): void {
                $table->boolean('is_guest')->default(false);
            });
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $column = DB::selectOne("
                SELECT data_type
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'users'
                  AND column_name = 'is_guest'
            ");

            if ($column && $column->data_type !== 'boolean') {
                DB::statement("
                    ALTER TABLE users
                    ALTER COLUMN is_guest TYPE BOOLEAN
                    USING CASE
                        WHEN is_guest IS NULL THEN FALSE
                        WHEN LOWER(TRIM(is_guest::text)) IN ('1', 'true', 't', 'yes', 'y') THEN TRUE
                        ELSE FALSE
                    END
                ");
            }

            // Preserve actual guests (TRUE) and classify legacy NULL accounts
            // as normal accounts. This is the same PostgreSQL production rule.
            DB::table('users')->whereNull('is_guest')->update(['is_guest' => false]);
            DB::statement('ALTER TABLE users ALTER COLUMN is_guest SET DEFAULT FALSE');
            DB::statement('ALTER TABLE users ALTER COLUMN is_guest SET NOT NULL');

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite is used by disposable CI tests only. Retain the boolean
            // default and normalize NULL values without PostgreSQL-only SQL.
            DB::table('users')->whereNull('is_guest')->update(['is_guest' => false]);

            return;
        }

        throw new RuntimeException('RAVEN23 guest-account reconciliation supports PostgreSQL and SQLite.');
    }

    public function down(): void
    {
        // Never weaken the account classification invariant on rollback.
    }
};
