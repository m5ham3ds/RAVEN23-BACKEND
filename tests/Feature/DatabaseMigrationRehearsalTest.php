<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DatabaseMigrationRehearsalTest extends TestCase
{
    private string $databasePath;

    private string $previousDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDefaultConnection = DB::getDefaultConnection();
        $databasePath = tempnam(sys_get_temp_dir(), 'raven23-migration-');

        if ($databasePath === false) {
            throw new RuntimeException('Unable to allocate an isolated migration rehearsal database.');
        }

        $this->databasePath = $databasePath;

        config([
            'database.connections.migration_rehearsal' => [
                'driver' => 'sqlite',
                'database' => $this->databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('migration_rehearsal');
        DB::setDefaultConnection('migration_rehearsal');
    }

    protected function tearDown(): void
    {
        DB::purge('migration_rehearsal');
        DB::setDefaultConnection($this->previousDefaultConnection);

        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_empty_schema_applies_every_migration_and_builds_the_current_contract(): void
    {
        $this->runMigrations();

        foreach ([
            'users',
            'personal_access_tokens',
            'user_profiles',
            'user_preferences',
            'user_devices',
            'user_bookmarks',
            'user_recent_media',
            'user_sync_revisions',
            'user_playlists',
            'user_playlist_items',
            'user_bookmark_operations',
            'user_preference_operations',
            'user_wellness_sessions',
            'user_wellness_daily',
            'user_media',
            'notification_outbox',
            'user_push_subscriptions',
            'user_entitlements',
            'subscription_events',
            'user_wallets',
            'wallet_transactions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), sprintf('Expected migrated table [%s].', $table));
        }

        $migrationFiles = glob(database_path('migrations/*.php')) ?: [];

        $this->assertCount(count($migrationFiles), DB::table('migrations')->get());
        $this->assertTrue(Schema::hasColumn('user_entitlements', 'last_event_occurred_at'));
        $this->assertTrue(Schema::hasColumn('subscription_events', 'aggregate_revision'));
        $this->assertTrue(Schema::hasColumn('subscription_events', 'projection_status'));
    }

    public function test_legacy_account_session_and_queue_rows_survive_the_full_upgrade(): void
    {
        $this->createLegacySnapshot();

        $this->runMigrations();

        $user = DB::table('users')->where('id', 42)->first();

        $this->assertNotNull($user);
        $this->assertSame('legacy_migration_user', $user->username);
        $this->assertFalse((bool) $user->is_guest);
        $this->assertSame('legacy@example.test', $user->email);

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => 71,
            'tokenable_id' => 42,
            'token' => str_repeat('a', 64),
            'device_id' => '55555555-5555-4555-8555-555555555555',
        ]);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => 42,
            'profile_id' => 27,
            'photo_url' => 'https://cdn.example.test/legacy-profile.webp',
        ]);
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => 42,
            'timezone' => 'UTC',
        ]);
        $this->assertDatabaseHas('user_devices', [
            'user_id' => 42,
            'device_id' => '55555555-5555-4555-8555-555555555555',
            'device_name' => 'Legacy Android TV',
            'platform' => 'tv',
        ]);
        $this->assertDatabaseHas('cache', ['key' => 'legacy-cache-key', 'value' => 'legacy-cache-value']);
        $this->assertDatabaseHas('jobs', ['id' => 19, 'queue' => 'default', 'payload' => '{"legacy":true}']);

        $migrationFiles = glob(database_path('migrations/*.php')) ?: [];

        $this->assertCount(count($migrationFiles), DB::table('migrations')->get());
        $this->assertTrue(Schema::hasTable('wallet_transactions'));
        $this->assertTrue(Schema::hasColumn('subscription_events', 'aggregate_revision'));
    }

    private function runMigrations(): void
    {
        $exitCode = Artisan::call('migrate', [
            '--database' => 'migration_rehearsal',
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());
    }

    private function createLegacySnapshot(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->unsignedSmallInteger('profile_id')->default(0);
            $table->text('photo_url')->nullable();
            $table->boolean('is_guest')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', static function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->string('device_id', 64)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('platform', 16)->nullable();
        });

        Schema::create('cache', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        DB::table('users')->insert([
            'id' => 42,
            'name' => 'Legacy Account',
            'email' => 'legacy_migration_user@example.test',
            'email_verified_at' => '2026-11-20 08:00:00',
            'password' => 'legacy-password-hash',
            'remember_token' => 'legacy-remember-token',
            'profile_id' => 27,
            'photo_url' => 'https://cdn.example.test/legacy-profile.webp',
            'is_guest' => null,
            'created_at' => '2026-11-20 08:00:00',
            'updated_at' => '2026-11-21 08:00:00',
        ]);

        DB::table('personal_access_tokens')->insert([
            'id' => 71,
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => 42,
            'name' => 'Legacy TV session',
            'token' => str_repeat('a', 64),
            'abilities' => '["*"]',
            'last_used_at' => '2026-11-21 10:00:00',
            'expires_at' => null,
            'created_at' => '2026-11-20 09:00:00',
            'updated_at' => '2026-11-21 10:00:00',
            'device_id' => '55555555-5555-4555-8555-555555555555',
            'device_name' => 'Legacy Android TV',
            'platform' => 'tv',
        ]);

        DB::table('cache')->insert([
            'key' => 'legacy-cache-key',
            'value' => 'legacy-cache-value',
            'expiration' => 2_000_000_000,
        ]);

        DB::table('jobs')->insert([
            'id' => 19,
            'queue' => 'default',
            'payload' => '{"legacy":true}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => 2_000_000_000,
            'created_at' => 1_900_000_000,
        ]);
    }
}
