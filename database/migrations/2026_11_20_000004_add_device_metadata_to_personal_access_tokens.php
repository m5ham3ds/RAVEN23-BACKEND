<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_access_tokens')) {
            throw new RuntimeException('Sanctum personal_access_tokens table must exist before device metadata is added.');
        }

        $existing = Schema::getColumnListing('personal_access_tokens');
        Schema::table('personal_access_tokens', static function (Blueprint $table) use ($existing): void {
            if (!in_array('device_id', $existing, true)) {
                $table->string('device_id', 64)->nullable();
            }
            if (!in_array('device_name', $existing, true)) {
                $table->string('device_name', 100)->nullable();
            }
            if (!in_array('platform', $existing, true)) {
                $table->string('platform', 16)->nullable();
            }
        });

        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('RAVEN23 Sanctum device metadata supports PostgreSQL and SQLite test databases.');
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS personal_access_tokens_device_lookup '
            . 'ON personal_access_tokens (tokenable_type, tokenable_id, device_id)'
        );
    }

    public function down(): void
    {
        // Preserve session metadata and token rows on rollback.
    }
};
