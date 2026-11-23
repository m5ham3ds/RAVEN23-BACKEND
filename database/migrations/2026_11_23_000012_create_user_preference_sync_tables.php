<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('RAVEN23 preference sync supports PostgreSQL and SQLite only.');
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('user_preferences')) {
            throw new RuntimeException('The account preferences foundation must exist before preference sync.');
        }

        if (! Schema::hasColumn('user_preferences', 'revision')) {
            Schema::table('user_preferences', static function (Blueprint $table): void {
                $table->unsignedBigInteger('revision')->default(0);
            });
        }

        if (! Schema::hasTable('user_preference_operations')) {
            Schema::create('user_preference_operations', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->uuid('operation_id');
                $table->string('payload_hash', 64);
                $table->json('result');
                $table->unsignedBigInteger('revision');
                $table->timestamp('created_at');
                $table->unique(['user_id', 'operation_id']);
                $table->index(['user_id', 'id']);
            });
        }

        $this->assertColumns('user_preferences', [
            'user_id', 'locale', 'timezone', 'revision', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('user_preference_operations', [
            'id', 'user_id', 'operation_id', 'payload_hash', 'result', 'revision', 'created_at',
        ]);
        $this->assertUnique('user_preference_operations', ['user_id', 'operation_id']);
        $this->assertOwnerForeignKey('user_preference_operations');
    }

    private function assertColumns(string $table, array $required): void
    {
        $missing = array_values(array_diff($required, Schema::getColumnListing($table)));
        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Existing %s table is missing columns: %s',
                $table,
                implode(', ', $missing),
            ));
        }
    }

    private function assertUnique(string $table, array $columns): void
    {
        $found = collect(Schema::getIndexes($table))->contains(
            static fn (array $index): bool => ($index['unique'] || $index['primary'])
                && $index['columns'] === $columns,
        );
        if (! $found) {
            throw new RuntimeException(sprintf(
                'Existing %s must enforce unique (%s).',
                $table,
                implode(', ', $columns),
            ));
        }
    }

    private function assertOwnerForeignKey(string $table): void
    {
        $found = collect(Schema::getForeignKeys($table))->contains(
            static fn (array $foreignKey): bool => $foreignKey['columns'] === ['user_id']
                && $foreignKey['foreign_table'] === 'users'
                && $foreignKey['foreign_columns'] === ['id']
                && strtolower((string) ($foreignKey['on_delete'] ?? '')) === 'cascade',
        );
        if (! $found) {
            throw new RuntimeException(sprintf('%s.user_id must cascade to users.id.', $table));
        }
    }

    public function down(): void
    {
        // Forward-only: rollback must not remove account preference revisions or idempotency receipts.
    }
};
