<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    /**
     * Create the Sanctum token table only when it does not already exist.
     *
     * Earlier RAVEN23 deployments published Sanctum's migration with a
     * build-time timestamp. Each Docker build could therefore appear as a
     * brand-new migration and attempt to recreate the live Neon table.
     */
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            $requiredColumns = [
                'id',
                'tokenable_type',
                'tokenable_id',
                'name',
                'token',
                'abilities',
                'last_used_at',
                'expires_at',
                'created_at',
                'updated_at',
            ];

            $missingColumns = array_values(array_filter(
                $requiredColumns,
                fn (string $column): bool => !Schema::hasColumn('personal_access_tokens', $column),
            ));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'The existing personal_access_tokens table is missing required Sanctum columns: '
                    . implode(', ', $missingColumns)
                );
            }

            // Preserve the existing production table and every token row.
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Intentionally preserve this table on rollback.
     *
     * The table may have existed before this migration was introduced, and
     * dropping it could invalidate active RAVEN23 sessions.
     */
    public function down(): void
    {
        // No destructive rollback for authentication token storage.
    }
};
