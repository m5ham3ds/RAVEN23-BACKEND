<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserDataFoundationMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::connection('sqlite')->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->unsignedSmallInteger('profile_id')->default(0);
            $table->text('photo_url')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('personal_access_tokens', static function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('device_id', 64)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('platform', 16)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
        });
    }

    public function test_migration_backfills_existing_profiles_preferences_and_installations_idempotently(): void
    {
        $firstUserId = DB::table('users')->insertGetId([
            'name' => 'Legacy user one',
            'email' => 'legacy-one@example.test',
            'profile_id' => 27,
            'photo_url' => 'https://cdn.example.test/legacy-one.webp',
            'created_at' => '2026-11-20 08:00:00',
            'updated_at' => '2026-11-21 08:00:00',
        ]);
        $secondUserId = DB::table('users')->insertGetId([
            'name' => 'Legacy user two',
            'email' => 'legacy-two@example.test',
            'profile_id' => 8,
            'photo_url' => null,
            'created_at' => '2026-11-20 09:00:00',
            'updated_at' => '2026-11-21 09:00:00',
        ]);

        DB::table('personal_access_tokens')->insert([
            [
                'tokenable_type' => 'App\Models\User',
                'tokenable_id' => $firstUserId,
                'device_id' => '55555555-5555-4555-8555-555555555555',
                'device_name' => 'Old name',
                'platform' => 'android',
                'created_at' => '2026-11-20 10:00:00',
                'last_used_at' => '2026-11-20 11:00:00',
            ],
            [
                'tokenable_type' => 'App\Models\User',
                'tokenable_id' => $firstUserId,
                'device_id' => '55555555-5555-4555-8555-555555555555',
                'device_name' => 'Latest name',
                'platform' => 'tv',
                'created_at' => '2026-11-21 10:00:00',
                'last_used_at' => '2026-11-21 12:00:00',
            ],
            [
                'tokenable_type' => 'App\Models\User',
                'tokenable_id' => $secondUserId,
                'device_id' => '55555555-5555-4555-8555-555555555555',
                'device_name' => 'Second account',
                'platform' => 'android',
                'created_at' => '2026-11-21 08:00:00',
                'last_used_at' => null,
            ],
        ]);

        $migration = require database_path('migrations/2026_11_22_000007_create_user_data_foundation_tables.php');
        $migration->up();

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $firstUserId,
            'profile_id' => 27,
            'photo_url' => 'https://cdn.example.test/legacy-one.webp',
        ]);
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $firstUserId,
            'locale' => null,
            'timezone' => 'UTC',
        ]);
        $this->assertDatabaseCount('user_profiles', 2);
        $this->assertDatabaseCount('user_preferences', 2);
        $this->assertDatabaseCount('user_devices', 2);

        $firstDevice = DB::table('user_devices')
            ->where('user_id', $firstUserId)
            ->where('device_id', '55555555-5555-4555-8555-555555555555')
            ->first();

        $this->assertSame('Latest name', $firstDevice->device_name);
        $this->assertSame('tv', $firstDevice->platform);
        $this->assertSame('2026-11-20 10:00:00', $firstDevice->first_seen_at);
        $this->assertSame('2026-11-21 12:00:00', $firstDevice->last_seen_at);

        $migration->up();
        $this->assertDatabaseCount('user_profiles', 2);
        $this->assertDatabaseCount('user_preferences', 2);
        $this->assertDatabaseCount('user_devices', 2);

        $migration->down();
        $this->assertDatabaseHas('user_profiles', ['user_id' => $firstUserId, 'profile_id' => 27]);
        $this->assertDatabaseHas('users', ['id' => $secondUserId, 'email' => 'legacy-two@example.test']);
        $this->assertDatabaseHas('user_devices', ['user_id' => $firstUserId]);
    }

    public function test_existing_partial_profile_table_without_owner_constraints_is_rejected(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Legacy partial account',
            'email' => 'legacy-partial@example.test',
            'profile_id' => 0,
            'photo_url' => null,
        ]);

        Schema::connection('sqlite')->create('user_profiles', static function (Blueprint $table): void {
            // Simulate a table that has the required fields but no FK to users.
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedSmallInteger('profile_id')->default(0);
            $table->text('photo_url')->nullable();
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_11_22_000007_create_user_data_foundation_tables.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must cascade its user_id foreign key to users.id');
        $migration->up();

        $this->assertDatabaseHas('users', ['id' => $userId]);
    }
}
