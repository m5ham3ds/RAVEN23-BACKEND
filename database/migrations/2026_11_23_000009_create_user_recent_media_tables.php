<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('RAVEN23 recent media supports PostgreSQL and SQLite only.');
        }

        if (! Schema::hasTable('users')) {
            throw new RuntimeException('The users table must exist before recent media is created.');
        }

        if (! Schema::hasTable('user_sync_revisions')) {
            Schema::create('user_sync_revisions', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('domain', 32);
                $table->unsignedBigInteger('revision')->default(0);
                $table->timestamps();
                $table->unique(['user_id', 'domain']);
            });
        }

        if (! Schema::hasTable('user_recent_media')) {
            Schema::create('user_recent_media', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('media_type', 16);
                $table->unsignedBigInteger('media_id');
                $table->json('metadata');
                $table->unsignedBigInteger('elapsed')->default(0);
                $table->unsignedBigInteger('remaining')->default(0);
                $table->unsignedBigInteger('updated_at_utc');
                $table->unsignedBigInteger('deleted_at_utc')->nullable();
                $table->unsignedBigInteger('revision');
                $table->timestamps();
                $table->unique(['user_id', 'media_type', 'media_id']);
                $table->index(['user_id', 'media_type', 'revision']);
            });
        }

        $this->assertColumns('user_sync_revisions', [
            'id', 'user_id', 'domain', 'revision', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('user_recent_media', [
            'id', 'user_id', 'media_type', 'media_id', 'metadata', 'elapsed',
            'remaining', 'updated_at_utc', 'deleted_at_utc', 'revision',
            'created_at', 'updated_at',
        ]);
        $this->assertOwnerConstraints('user_sync_revisions', ['user_id', 'domain']);
        $this->assertOwnerConstraints('user_recent_media', ['user_id', 'media_type', 'media_id']);
    }

    private function assertColumns(string $table, array $required): void
    {
        $missing = array_values(array_diff($required, Schema::getColumnListing($table)));
        if ($missing !== []) {
            throw new RuntimeException(
                sprintf('Existing %s table is missing required columns: %s', $table, implode(', ', $missing))
            );
        }
    }

    private function assertOwnerConstraints(string $table, array $uniqueColumns): void
    {
        $hasUniqueKey = collect(Schema::getIndexes($table))->contains(
            static fn (array $index): bool => ($index['unique'] || $index['primary'])
                && $index['columns'] === $uniqueColumns,
        );
        if (! $hasUniqueKey) {
            throw new RuntimeException(
                sprintf('Existing %s table must enforce unique (%s).', $table, implode(', ', $uniqueColumns))
            );
        }

        $hasOwnerForeignKey = collect(Schema::getForeignKeys($table))->contains(
            static fn (array $foreignKey): bool => $foreignKey['columns'] === ['user_id']
                && $foreignKey['foreign_table'] === 'users'
                && $foreignKey['foreign_columns'] === ['id']
                && strtolower((string) ($foreignKey['on_delete'] ?? '')) === 'cascade',
        );
        if (! $hasOwnerForeignKey) {
            throw new RuntimeException(sprintf(
                'Existing %s.user_id must cascade to users.id.',
                $table,
            ));
        }
    }

    public function down(): void
    {
        // Forward-only by design; rollback must not erase watch history or sync checkpoints.
    }
};
