<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityResponseTest extends TestCase
{
    public function test_request_id_is_generated_and_not_copied_from_client_input(): void
    {
        $response = $this->get('/up', [
            'X-Request-ID' => 'caller-controlled-id',
        ])->assertOk();

        $requestId = (string) $response->headers->get('X-Request-ID');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $requestId,
        );
        $this->assertNotSame('caller-controlled-id', $requestId);
    }

    public function test_api_exception_response_contains_only_generic_message_and_request_id(): void
    {
        Route::get('/api/v1/security-test-error', static function () {
            throw new \RuntimeException('sensitive-message-that-must-not-leak');
        });

        $response = $this->getJson('/api/v1/security-test-error')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The server could not complete the request. Please try again.');

        $requestId = (string) $response->json('request_id');
        $this->assertNotSame('', $requestId);
        $this->assertSame($requestId, $response->headers->get('X-Request-ID'));

        $body = $response->getContent();
        foreach ([
            'diagnostic',
            'RuntimeException',
            'sensitive-message-that-must-not-leak',
            'source_file',
            'source_line',
            'application_call_site',
            'database_name',
            'schema_name',
            'instance_id',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_cors_preflight_allows_an_explicit_origin_without_credentials(): void
    {
        config(['cors.allowed_origins' => ['https://raven.example.test']]);

        $allowed = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://raven.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $allowed->assertNoContent();
        $this->assertSame('https://raven.example.test', $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('true', $allowed->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_cors_preflight_does_not_authorize_an_unlisted_origin_with_one_static_origin(): void
    {
        // Fruitcake's single-origin optimization returns the configured
        // origin, not the caller's Origin. Browsers reject the mismatch.
        config(['cors.allowed_origins' => ['https://raven.example.test']]);

        $blocked = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://untrusted.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $blocked->assertNoContent();
        $this->assertSame(
            'https://raven.example.test',
            $blocked->headers->get('Access-Control-Allow-Origin'),
        );
        $this->assertNotSame(
            'https://untrusted.example.test',
            $blocked->headers->get('Access-Control-Allow-Origin'),
        );
        $this->assertNotSame('*', $blocked->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_preflight_omits_allow_origin_for_unlisted_origin_with_multiple_static_origins(): void
    {
        config(['cors.allowed_origins' => [
            'https://raven.example.test',
            'https://raven-admin.example.test',
        ]]);

        $blocked = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://untrusted.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $blocked->assertNoContent();
        $this->assertNull($blocked->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_configuration_rejects_wildcard_subdomain_origins(): void
    {
        $name = 'CORS_ALLOWED_ORIGINS';
        $originalProcessValue = getenv($name);
        $hadEnvValue = array_key_exists($name, $_ENV);
        $originalEnvValue = $_ENV[$name] ?? null;
        $hadServerValue = array_key_exists($name, $_SERVER);
        $originalServerValue = $_SERVER[$name] ?? null;

        putenv($name.'=https://*.example.test');
        $_ENV[$name] = 'https://*.example.test';
        $_SERVER[$name] = 'https://*.example.test';

        try {
            $this->expectException(\InvalidArgumentException::class);
            require base_path('config/cors.php');
        } finally {
            if ($originalProcessValue === false) {
                putenv($name);
            } else {
                putenv($name.'='.$originalProcessValue);
            }

            if ($hadEnvValue) {
                $_ENV[$name] = $originalEnvValue;
            } else {
                unset($_ENV[$name]);
            }

            if ($hadServerValue) {
                $_SERVER[$name] = $originalServerValue;
            } else {
                unset($_SERVER[$name]);
            }
        }
    }

    public function test_api_error_logs_exclude_request_credentials_and_personal_identifiers(): void
    {
        $capturedContext = null;

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$capturedContext): bool {
                if ($message !== 'RAVEN23 API request failed') {
                    return false;
                }

                $capturedContext = $context;

                return true;
            });

        Route::post('/api/v1/security-test-log-redaction', static function () {
            throw new \RuntimeException('private-exception-message-must-not-be-logged');
        });

        $this->postJson('/api/v1/security-test-log-redaction', [
            'email' => 'private-raven-user@example.test',
            'password' => 'Private-Raven-Password-42!',
            'token' => 'private-request-token-value',
        ], [
            'Authorization' => 'Bearer private-bearer-token-value',
        ])->assertStatus(500);

        $this->assertIsArray($capturedContext);
        $this->assertArrayHasKey('request_id', $capturedContext);
        $this->assertArrayHasKey('exception_type', $capturedContext);

        $serializedContext = json_encode($capturedContext, JSON_THROW_ON_ERROR);
        foreach ([
            'private-raven-user@example.test',
            'Private-Raven-Password-42!',
            'private-request-token-value',
            'private-bearer-token-value',
            'private-exception-message-must-not-be-logged',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serializedContext);
        }
    }

    public function test_remote_production_postgresql_defaults_to_encrypted_tls(): void
    {
        $database = $this->withEnvironment([
            'APP_ENV' => 'production',
            'DB_URL' => 'postgresql://raven23_app:secret@db.example.test/raven23?connect_timeout=5',
            'DB_HOST' => null,
            'DB_SSLMODE' => null,
        ], static fn (): array => require base_path('config/database.php'));

        $this->assertSame('require', $database['connections']['pgsql']['sslmode']);
    }

    public function test_remote_production_postgresql_rejects_tls_downgrade_from_environment(): void
    {
        try {
            $this->withEnvironment([
                'APP_ENV' => 'production',
                'DB_URL' => 'postgresql://raven23_app:secret@db.example.test/raven23',
                'DB_HOST' => null,
                'DB_SSLMODE' => 'prefer',
            ], static fn (): array => require base_path('config/database.php'));

            $this->fail('A remote production PostgreSQL connection must reject sslmode=prefer.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Remote PostgreSQL connections in production', $exception->getMessage());
        }
    }

    public function test_remote_production_postgresql_rejects_tls_downgrade_in_database_url_query(): void
    {
        try {
            $this->withEnvironment([
                'APP_ENV' => 'production',
                'DB_URL' => 'postgresql://raven23_app:secret@db.example.test/raven23?sslmode=disable',
                'DB_HOST' => null,
                'DB_SSLMODE' => 'require',
            ], static fn (): array => require base_path('config/database.php'));

            $this->fail('A DB_URL sslmode query must not downgrade remote production TLS.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Remote PostgreSQL connections in production', $exception->getMessage());
        }
    }

    public function test_loopback_only_postgresql_ci_connection_may_disable_tls(): void
    {
        $database = $this->withEnvironment([
            'APP_ENV' => 'production',
            'DB_URL' => 'postgresql://raven23_app:secret@127.0.0.1/raven23?sslmode=disable',
            'DB_HOST' => null,
            'DB_SSLMODE' => 'disable',
        ], static fn (): array => require base_path('config/database.php'));

        $this->assertSame('disable', $database['connections']['pgsql']['sslmode']);
    }

    private function withEnvironment(array $values, callable $callback): mixed
    {
        $previous = [];

        foreach ($values as $name => $value) {
            $previous[$name] = [
                'process' => getenv($name),
                'env_exists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];

            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $name => $state) {
                if ($state['process'] === false) {
                    putenv($name);
                } else {
                    putenv($name.'='.$state['process']);
                }

                if ($state['env_exists']) {
                    $_ENV[$name] = $state['env'];
                } else {
                    unset($_ENV[$name]);
                }

                if ($state['server_exists']) {
                    $_SERVER[$name] = $state['server'];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }
}
