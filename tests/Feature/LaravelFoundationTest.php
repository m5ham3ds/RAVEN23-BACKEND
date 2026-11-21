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
            $registered[$route->uri()] = array_values($route->methods());
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
}
