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
            throw new RuntimeException('RAVEN23 bookmark sync supports PostgreSQL and SQLite only.');
        }

        if (! Schema::hasTable('user_sync_revisions')) {
            throw new RuntimeException('The user sync revision foundation must exist before bookmark sync.');
        }

        if (! Schema::hasTable('user_bookmark_operations')) {
            Schema::create('user_bookmark_operations', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->uuid('operation_id');
                $table->string('media_type', 8);
                $table->unsignedBigInteger('media_id');
                $table->string('action', 24);
                $table->string('payload_hash', 64);
                $table->json('result');
                $table->unsignedBigInteger('revision');
                $table->timestamp('created_at');
                $table->unique(['user_id', 'operation_id']);
                $table->index(['user_id', 'id']);
            });
        }

        if (! Schema::hasTable('user_bookmark_changes')) {
            Schema::create('user_bookmark_changes', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('revision');
                $table->string('media_type', 8);
                $table->unsignedBigInteger('media_id');
                $table->string('action', 24);
                $table->json('payload');
                $table->boolean('deleted')->default(false);
                $table->timestamp('created_at');
                $table->index(['user_id', 'revision', 'id']);
                $table->index(['user_id', 'media_type', 'media_id', 'revision']);
            });
        }

        $this->assertColumns('user_bookmark_operations', [
            'id', 'user_id', 'operation_id', 'media_type', 'media_id',
            'action', 'payload_hash', 'result', 'revision', 'created_at',
        ]);
        $this->assertColumns('user_bookmark_changes', [
            'id', 'user_id', 'revision', 'media_type', 'media_id',
            'action', 'payload', 'deleted', 'created_at',
        ]);
        $this->assertUnique('user_bookmark_operations', ['user_id', 'operation_id']);
        $this->assertForeignKey('user_bookmark_operations');
        $this->assertForeignKey('user_bookmark_changes');
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

    private function assertForeignKey(string $table): void
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
        // Forward-only: rollback must never erase operation receipts or bookmark tombstones.
    }
};
