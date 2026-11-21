<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RuntimeHealthTest extends TestCase
{
    private function prepareReadyDatabase(): void
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

        $this->artisan('migrate:fresh', ['--force' => true])
            ->assertExitCode(0);
    }

    public function test_liveness_does_not_depend_on_postgresql_availability(): void
    {
        config(['database.default' => 'missing-runtime-connection']);
        DB::purge('missing-runtime-connection');

        $this->get('/up')->assertOk();
    }

    public function test_readiness_checks_database_and_auth_schema(): void
    {
        $this->prepareReadyDatabase();

        $this->getJson('/ready')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.application', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.schema', 'ready');

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('database', 'connected')
            ->assertJsonPath('schema', 'ready');
    }

    public function test_readiness_returns_503_when_postgresql_connection_is_refused(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.driver' => 'pgsql',
            'database.connections.pgsql.url' => null,
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '1',
            'database.connections.pgsql.database' => 'raven23_unavailable_test',
            'database.connections.pgsql.username' => 'raven23_unavailable_test',
            'database.connections.pgsql.password' => 'unavailable',
            'logging.default' => 'stderr',
        ]);

        DB::purge('pgsql');

        $this->getJson('/ready')
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.application', 'ok')
            ->assertJsonPath('checks.database', 'unavailable')
            ->assertJsonPath('checks.schema', 'unknown');
    }

    public function test_readiness_returns_503_when_persistent_runtime_schema_is_incomplete(): void
    {
        $this->prepareReadyDatabase();

        Schema::drop('cache_locks');

        $this->getJson('/ready')
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.schema', 'incomplete');
    }

    public function test_readiness_returns_503_without_exposing_connection_details(): void
    {
        config(['database.default' => 'missing-runtime-connection']);
        DB::purge('missing-runtime-connection');

        $response = $this->getJson('/ready')
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.application', 'ok')
            ->assertJsonPath('checks.database', 'unavailable');

        $body = $response->getContent();
        $this->assertStringNotContainsString('Neon', $body);
        $this->assertStringNotContainsString('Render', $body);
        $this->assertStringNotContainsString('password', strtolower($body));
    }

    public function test_api_health_exposes_generic_source_metadata_only(): void
    {
        $this->prepareReadyDatabase();

        putenv('RAVEN23_SOURCE_COMMIT=phase02-test-commit');
        try {
            $response = $this->getJson('/api/v1/health')
                ->assertOk()
                ->assertJsonPath('runtime_target.source_commit', 'phase02-test-commit');

            $target = $response->json('runtime_target');
            $this->assertArrayNotHasKey('render_git_commit', $target);
            $this->assertArrayNotHasKey('configured_host', $target);
            $this->assertArrayNotHasKey('configured_database', $target);
        } finally {
            putenv('RAVEN23_SOURCE_COMMIT');
        }
    }
}
