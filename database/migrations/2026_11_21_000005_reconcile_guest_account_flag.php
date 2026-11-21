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

        // NULL was never a guest state in RAVEN23. Preserve actual guests (TRUE)
        // and normalize legacy regular accounts to an explicit FALSE value.
        DB::statement('UPDATE users SET is_guest = FALSE WHERE is_guest IS NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN is_guest SET DEFAULT FALSE');
        DB::statement('ALTER TABLE users ALTER COLUMN is_guest SET NOT NULL');
    }

    public function down(): void
    {
        // Never weaken the account classification invariant on rollback.
    }
};
