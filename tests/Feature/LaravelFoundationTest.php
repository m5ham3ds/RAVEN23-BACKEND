<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LaravelFoundationTest extends TestCase
{
    public function test_laravel_application_boots_and_registers_the_existing_api_contract(): void
    {
        $this->assertStringStartsWith('13.', app()->version());

        // Inspect registered route URIs and methods directly. CLI path filters
        // can vary with framework formatting and are not the API contract.
        $routes = app('router')->getRoutes();
        $registered = [];

        foreach ($routes as $route) {
            // A URI may intentionally support multiple verbs (e.g. profile GET + PUT).
            // Accumulate every verb instead of allowing the last route to overwrite it.
            $registered[$route->uri()] = array_values(array_unique(array_merge(
                $registered[$route->uri()] ?? [],
                $route->methods(),
            )));
        }

        $this->assertArrayHasKey('api/v1/auth/register', $registered);
        $this->assertContains('POST', $registered['api/v1/auth/register']);
        $this->assertArrayHasKey('api/v1/user/profile', $registered);
        $this->assertContains('GET', $registered['api/v1/user/profile']);
    }

    public function test_all_repository_migrations_run_against_a_fresh_sqlite_database(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'url' => null,
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('sqlite');

        $exitCode = Artisan::call('migrate:fresh', [
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());

        foreach ([
            'users',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'personal_access_tokens',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected migration table [{$table}] to exist.");
        }

        foreach (['username', 'profile_id', 'photo_url', 'provider', 'google_id', 'is_guest'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "Expected users.{$column} to exist.");
        }

        foreach (['device_id', 'device_name', 'platform'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('personal_access_tokens', $column),
                "Expected personal_access_tokens.{$column} to exist.",
            );
        }
    }
    public function test_baseline_migrations_preserve_preexisting_framework_tables_and_rows(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'url' => null,
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('sqlite');

        // Model a legacy deployment where Laravel generated these tables
        // before stable migration files were committed to this repository.
        Schema::create('users', static function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
        });
        DB::table('users')->insert([
            'id' => 77,
            'name' => 'Existing Raven Account',
            'email' => 'existing-raven@example.test',
            'password' => 'already-hashed',
        ]);

        Schema::create('cache', static function ($table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration');
        });
        DB::table('cache')->insert([
            'key' => 'legacy-cache-entry',
            'value' => 'preserve-me',
            'expiration' => 2000000000,
        ]);

        Schema::create('jobs', static function ($table): void {
            $table->id();
            $table->string('queue');
        });
        DB::table('jobs')->insert([
            'id' => 19,
            'queue' => 'legacy-queue',
        ]);

        $migrationPaths = [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/0001_01_01_000001_create_cache_table.php',
            'database/migrations/0001_01_01_000002_create_jobs_table.php',
        ];

        foreach ($migrationPaths as $path) {
            $migration = require base_path($path);
            $migration->up();
        }

        $this->assertDatabaseHas('users', [
            'id' => 77,
            'email' => 'existing-raven@example.test',
            'password' => 'already-hashed',
        ]);
        $this->assertDatabaseHas('cache', [
            'key' => 'legacy-cache-entry',
            'value' => 'preserve-me',
        ]);
        $this->assertDatabaseHas('jobs', [
            'id' => 19,
            'queue' => 'legacy-queue',
        ]);

        foreach ([
            'password_reset_tokens',
            'sessions',
            'cache_locks',
            'job_batches',
            'failed_jobs',
        ] as $missingTable) {
            $this->assertTrue(
                Schema::hasTable($missingTable),
                "Expected missing framework table [{$missingTable}] to be added.",
            );
        }
    }

}
