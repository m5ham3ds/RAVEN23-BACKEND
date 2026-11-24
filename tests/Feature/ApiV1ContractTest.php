<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiV1ContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    public function test_api_v1_manifest_matches_registered_methods_and_authentication_boundary(): void
    {
        $manifest = json_decode(
            file_get_contents(base_path('contracts/api-v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(1, $manifest['contract_version']);
        $this->assertSame('/api/v1', $manifest['base_path']);
        $this->assertNotEmpty($manifest['routes']);

        $registeredRoutes = app('router')->getRoutes();

        foreach ($manifest['routes'] as $contractRoute) {
            $matchingRoutes = [];

            foreach ($registeredRoutes as $route) {
                if ($route->uri() === $contractRoute['path']
                    && in_array($contractRoute['method'], $route->methods(), true)) {
                    $matchingRoutes[] = $route;
                }
            }

            $this->assertNotEmpty(
                $matchingRoutes,
                sprintf(
                    'API contract route %s %s is not registered.',
                    $contractRoute['method'],
                    $contractRoute['path'],
                ),
            );

            $hasSanctumMiddleware = false;
            foreach ($matchingRoutes as $route) {
                if (in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                    $hasSanctumMiddleware = true;
                    break;
                }
            }

            $this->assertSame(
                $contractRoute['auth'],
                $hasSanctumMiddleware,
                sprintf(
                    'Authentication boundary changed for %s %s.',
                    $contractRoute['method'],
                    $contractRoute['path'],
                ),
            );
        }
    }

    public function test_public_health_and_billing_responses_match_the_v1_contract(): void
    {
        $health = $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('service', 'raven23-api')
            ->assertJsonPath('version', '1')
            ->assertJsonPath('database', 'connected')
            ->assertJsonPath('schema', 'ready');

        $this->assertSame(
            ['success', 'service', 'version', 'database', 'schema'],
            array_keys($health->json()),
        );

        $plans = $this->getJson('/api/v1/billing/plans')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('purchases_enabled', false)
            ->assertJsonPath('plans', []);

        $this->assertSame(
            ['success', 'purchases_enabled', 'plans'],
            array_keys($plans->json()),
        );

        $this->getJson('/api/v1/billing/plans?purchases_enabled=true&amount_cents=1')
            ->assertOk()
            ->assertJsonPath('purchases_enabled', false)
            ->assertJsonPath('plans', []);
    }

    public function test_registration_profile_preferences_entitlements_and_wallet_keep_stable_shapes(): void
    {
        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'RAVEN Contract User',
            'email' => 'api-contract@example.test',
            'username' => 'api_contract_user',
            'password' => 'Raven-Contract-Password-42!',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_verification_required', true)
            ->assertJsonPath('verification_email_sent', false);

        $this->assertSame(
            ['success', 'token', 'user', 'email_verification_required', 'verification_email_sent'],
            array_keys($registration->json()),
        );
        $this->assertMatchesRegularExpression('/^[0-9]+\\|[A-Za-z0-9]{40,64}$/', $registration->json('token'));
        $this->assertSame(
            [
                'id', 'name', 'email', 'username', 'joined_at', 'created_at', 'updated_at',
                'profile_id', 'photo_url', 'provider', 'is_verified', 'is_account_verified',
            ],
            array_keys($registration->json('user')),
        );

        $token = (string) $registration->json('token');
        $userId = (int) $registration->json('user.id');

        Auth::forgetGuards();
        $profile = $this->withToken($token)
            ->getJson('/api/v1/user/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $userId);
        $this->assertSame(['success', 'user'], array_keys($profile->json()));

        Auth::forgetGuards();
        $preferences = $this->withToken($token)
            ->getJson('/api/v1/user/preferences')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('preferences.timezone', 'UTC')
            ->assertJsonPath('preferences.revision', 0);
        $this->assertSame(['success', 'preferences'], array_keys($preferences->json()));
        $this->assertSame(
            ['locale', 'timezone', 'revision', 'updated_at'],
            array_keys($preferences->json('preferences')),
        );

        Auth::forgetGuards();
        $entitlements = $this->withToken($token)
            ->getJson('/api/v1/user/entitlements')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('entitlements.ad_free', false)
            ->assertJsonPath('entitlements.revision', 0)
            ->assertJsonPath('refresh_after_seconds', 300);
        $this->assertSame(
            ['success', 'entitlements', 'server_time', 'refresh_after_seconds'],
            array_keys($entitlements->json()),
        );

        Auth::forgetGuards();
        $wallet = $this->withToken($token)
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('wallet.currency', 'USD')
            ->assertJsonPath('wallet.balance_cents', 0)
            ->assertJsonPath('wallet.spendable_cents', 0)
            ->assertJsonPath('pending_topups', []);
        $this->assertSame(
            [
                'success', 'entitlements', 'server_time', 'refresh_after_seconds',
                'subscription', 'wallet', 'pending_topups',
            ],
            array_keys($wallet->json()),
        );
        $this->assertSame(
            [
                'id', 'currency', 'balance_cents', 'spendable_cents', 'status',
                'frozen', 'revision', 'periods_fundable',
            ],
            array_keys($wallet->json('wallet')),
        );

        Auth::forgetGuards();
        $transactions = $this->withToken($token)
            ->getJson('/api/v1/wallet/transactions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transactions', [])
            ->assertJsonPath('next_cursor', null);
        $this->assertSame(['success', 'transactions', 'next_cursor'], array_keys($transactions->json()));
    }

    public function test_protected_v1_endpoints_and_validation_errors_keep_common_http_contracts(): void
    {
        $protected = [
            ['GET', '/api/v1/user/profile'],
            ['PUT', '/api/v1/user/profile'],
            ['GET', '/api/v1/user/preferences'],
            ['PUT', '/api/v1/user/preferences'],
            ['GET', '/api/v1/user/data-export'],
            ['GET', '/api/v1/user/entitlements'],
            ['GET', '/api/v1/wallet'],
            ['GET', '/api/v1/wallet/transactions'],
            ['POST', '/api/v1/auth/logout'],
            ['POST', '/api/v1/sync/bookmarks'],
            ['POST', '/api/v1/sync/recently-watched'],
            ['POST', '/api/v1/sync/playlists'],
            ['POST', '/api/v1/sync/preferences'],
            ['POST', '/api/v1/sync/wellness'],
        ];

        foreach ($protected as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }

        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'RAVEN Contract Validation',
            'email' => 'api-validation@example.test',
            'username' => 'api_validation',
            'password' => 'Raven-Contract-Password-42!',
        ])->assertCreated();
        $token = (string) $registration->json('token');

        Auth::forgetGuards();
        $this->withToken($token)
            ->putJson('/api/v1/user/preferences', [])
            ->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => ['locale', 'timezone'],
            ]);

        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson('/api/v1/sync/wellness', [])
            ->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => ['client_time_utc'],
            ]);
    }
}
