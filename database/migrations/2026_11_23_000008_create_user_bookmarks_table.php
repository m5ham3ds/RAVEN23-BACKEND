<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('RAVEN23 bookmarks support PostgreSQL and SQLite only.');
        }

        if (! Schema::hasTable('users')) {
            throw new RuntimeException('The users table must exist before bookmarks are created.');
        }

        if (! Schema::hasTable('user_bookmarks')) {
            Schema::create('user_bookmarks', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('media_type', 8);
                $table->unsignedBigInteger('media_id');
                $table->json('metadata');
                $table->timestamps();
                $table->unique(['user_id', 'media_type', 'media_id']);
                $table->index(['user_id', 'media_type', 'created_at']);
            });
        }

        $requiredColumns = [
            'id', 'user_id', 'media_type', 'media_id', 'metadata', 'created_at', 'updated_at',
        ];
        $missing = array_values(array_diff($requiredColumns, Schema::getColumnListing('user_bookmarks')));
        if ($missing !== []) {
            throw new RuntimeException(
                'Existing user_bookmarks table is missing required columns: '.implode(', ', $missing),
            );
        }

        $indexes = collect(Schema::getIndexes('user_bookmarks'));
        $hasUniqueKey = $indexes->contains(
            static fn (array $index): bool => ($index['unique'] || $index['primary'])
                && $index['columns'] === ['user_id', 'media_type', 'media_id'],
        );
        if (! $hasUniqueKey) {
            throw new RuntimeException('user_bookmarks must enforce unique (user_id, media_type, media_id).');
        }

        $foreignKeys = collect(Schema::getForeignKeys('user_bookmarks'));
        $hasOwnerForeignKey = $foreignKeys->contains(
            static fn (array $foreignKey): bool => $foreignKey['columns'] === ['user_id']
                && $foreignKey['foreign_table'] === 'users'
                && $foreignKey['foreign_columns'] === ['id']
                && strtolower((string) ($foreignKey['on_delete'] ?? '')) === 'cascade',
        );
        if (! $hasOwnerForeignKey) {
            throw new RuntimeException('user_bookmarks.user_id must cascade to users.id.');
        }
    }

    public function down(): void
    {
        // Forward-only by design: rollback must not erase saved user bookmarks.
    }
};
