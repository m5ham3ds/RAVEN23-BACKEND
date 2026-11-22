<?php

namespace Tests\Feature;

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

    public function test_cors_preflight_only_allows_explicit_origins_and_no_credentials(): void
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

        $blocked = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://untrusted.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $blocked->assertNoContent();
        $this->assertNull($blocked->headers->get('Access-Control-Allow-Origin'));
    }
}
