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
            throw new RuntimeException('RAVEN23 playlists support PostgreSQL and SQLite only.');
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('user_sync_revisions')) {
            throw new RuntimeException('The account and sync-revision foundation must exist before playlists.');
        }

        if (! Schema::hasTable('user_playlists')) {
            Schema::create('user_playlists', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('description', 500)->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('user_playlist_items')) {
            Schema::create('user_playlist_items', static function (Blueprint $table): void {
                $table->foreignUuid('playlist_id')->constrained('user_playlists')->cascadeOnDelete();
                $table->string('media_type', 8);
                $table->unsignedBigInteger('media_id');
                $table->unsignedInteger('position');
                $table->string('title', 500);
                $table->string('poster_path', 2048)->nullable();
                $table->string('backdrop_path', 2048)->nullable();
                $table->double('vote_average')->nullable();
                $table->string('release_date', 64)->nullable();
                $table->json('genre_ids')->nullable();
                $table->string('added_at', 64)->nullable();
                $table->timestamps();
                $table->primary(['playlist_id', 'media_type', 'media_id']);
                $table->index(['playlist_id', 'position']);
            });
        }

        if (! Schema::hasTable('user_playlist_operations')) {
            Schema::create('user_playlist_operations', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->uuid('operation_id');
                $table->uuid('playlist_id');
                $table->string('action', 32);
                $table->string('payload_hash', 64);
                $table->json('result');
                $table->timestamps();
                $table->unique(['user_id', 'operation_id']);
                $table->index(['user_id', 'id']);
            });
        }

        if (! Schema::hasTable('user_playlist_changes')) {
            Schema::create('user_playlist_changes', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('revision');
                $table->string('entity_type', 16);
                $table->string('entity_key', 220);
                $table->json('payload');
                $table->boolean('deleted')->default(false);
                $table->timestamps();
                $table->index(['user_id', 'revision', 'id']);
                $table->index(['user_id', 'entity_type', 'entity_key', 'revision']);
            });
        }

        $this->assertColumns('user_playlists', [
            'id', 'user_id', 'name', 'description', 'version', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('user_playlist_items', [
            'playlist_id', 'media_type', 'media_id', 'position', 'title', 'poster_path',
            'backdrop_path', 'vote_average', 'release_date', 'genre_ids', 'added_at',
            'created_at', 'updated_at',
        ]);
        $this->assertColumns('user_playlist_operations', [
            'id', 'user_id', 'operation_id', 'playlist_id', 'action', 'payload_hash',
            'result', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('user_playlist_changes', [
            'id', 'user_id', 'revision', 'entity_type', 'entity_key', 'payload',
            'deleted', 'created_at', 'updated_at',
        ]);

        $this->assertUnique('user_playlists', ['id']);
        $this->assertUnique('user_playlist_items', ['playlist_id', 'media_type', 'media_id']);
        $this->assertUnique('user_playlist_operations', ['user_id', 'operation_id']);
        foreach (['user_playlists', 'user_playlist_operations', 'user_playlist_changes'] as $table) {
            $this->assertOwnerForeignKey($table);
        }
        $this->assertForeignKey(
            'user_playlist_items',
            ['playlist_id'],
            'user_playlists',
            ['id'],
            'cascade',
        );
    }

    private function assertColumns(string $table, array $required): void
    {
        $missing = array_values(array_diff($required, Schema::getColumnListing($table)));
        if ($missing !== []) {
            throw new RuntimeException(
                sprintf('Existing %s table is missing columns: %s', $table, implode(', ', $missing))
            );
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
        $this->assertForeignKey($table, ['user_id'], 'users', ['id'], 'cascade');
    }

    private function assertForeignKey(
        string $table,
        array $columns,
        string $foreignTable,
        array $foreignColumns,
        string $onDelete,
    ): void {
        $found = collect(Schema::getForeignKeys($table))->contains(
            static fn (array $foreignKey): bool => $foreignKey['columns'] === $columns
                && $foreignKey['foreign_table'] === $foreignTable
                && $foreignKey['foreign_columns'] === $foreignColumns
                && strtolower((string) ($foreignKey['on_delete'] ?? '')) === $onDelete,
        );
        if (! $found) {
            throw new RuntimeException(sprintf(
                'Existing %s.%s must reference %s.%s with ON DELETE %s.',
                $table,
                implode(',', $columns),
                $foreignTable,
                implode(',', $foreignColumns),
                strtoupper($onDelete),
            ));
        }
    }

    public function down(): void
    {
        // Forward-only: rollback must never erase a user's saved playlists or operation history.
    }
};
