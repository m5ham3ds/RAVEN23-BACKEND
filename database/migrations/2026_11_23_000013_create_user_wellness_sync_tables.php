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
            throw new RuntimeException('RAVEN23 wellness sync supports PostgreSQL and SQLite only.');
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('user_sync_revisions')) {
            throw new RuntimeException('The account and sync revision foundations must exist before wellness sync.');
        }

        if (! Schema::hasTable('user_wellness_sessions')) {
            Schema::create('user_wellness_sessions', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->uuid('session_id');
                $table->unsignedBigInteger('updated_at_utc');
                $table->unsignedBigInteger('deleted_at_utc')->nullable();
                $table->unsignedBigInteger('revision');
                $table->json('payload');
                $table->timestamps();
                $table->unique(['user_id', 'session_id']);
                $table->index(['user_id', 'revision']);
            });
        }

        if (! Schema::hasTable('user_wellness_daily')) {
            Schema::create('user_wellness_daily', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('local_day', 10);
                $table->unsignedBigInteger('updated_at_utc');
                $table->unsignedBigInteger('revision');
                $table->json('payload');
                $table->timestamps();
                $table->unique(['user_id', 'local_day']);
                $table->index(['user_id', 'revision']);
            });
        }

        if (! Schema::hasTable('user_wellness_changes')) {
            Schema::create('user_wellness_changes', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('revision');
                $table->string('entity_type', 16);
                $table->string('entity_key', 255);
                $table->json('payload');
                $table->boolean('deleted')->default(false);
                $table->timestamp('created_at');
                $table->unique(['user_id', 'revision']);
                $table->index(['user_id', 'id']);
                $table->index(['user_id', 'revision', 'id']);
                $table->index(['user_id', 'entity_type', 'entity_key', 'revision']);
            });
        }

        if (! Schema::hasTable('user_wellness_upload_receipts')) {
            Schema::create('user_wellness_upload_receipts', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('entity_type', 16);
                $table->string('entity_key', 255);
                $table->unsignedBigInteger('upload_stamp');
                $table->string('payload_hash', 64);
                $table->timestamp('created_at');
                $table->unique(
                    ['user_id', 'entity_type', 'entity_key', 'upload_stamp', 'payload_hash'],
                    'user_wellness_receipts_identity_unique',
                );
                $table->index(['user_id', 'created_at']);
            });
        }

        $this->assertColumns('user_wellness_sessions', [
            'id', 'user_id', 'session_id', 'updated_at_utc', 'deleted_at_utc',
            'revision', 'payload', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('user_wellness_daily', [
            'id', 'user_id', 'local_day', 'updated_at_utc', 'revision',
            'payload', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('user_wellness_changes', [
            'id', 'user_id', 'revision', 'entity_type', 'entity_key',
            'payload', 'deleted', 'created_at',
        ]);
        $this->assertColumns('user_wellness_upload_receipts', [
            'id', 'user_id', 'entity_type', 'entity_key', 'upload_stamp',
            'payload_hash', 'created_at',
        ]);

        $this->assertUnique('user_wellness_sessions', ['user_id', 'session_id']);
        $this->assertUnique('user_wellness_daily', ['user_id', 'local_day']);
        $this->assertUnique('user_wellness_changes', ['user_id', 'revision']);
        $this->assertOwnerForeignKey('user_wellness_sessions');
        $this->assertOwnerForeignKey('user_wellness_daily');
        $this->assertOwnerForeignKey('user_wellness_changes');
        $this->assertOwnerForeignKey('user_wellness_upload_receipts');
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
        // Forward-only: a rollback must not erase viewing history or synchronization receipts.
    }
};
