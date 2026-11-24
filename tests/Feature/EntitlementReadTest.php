<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EntitlementReadTest extends TestCase
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

    private function account(string $label): array
    {
        $suffix = bin2hex(random_bytes(5));
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'RAVEN23 Entitlement '.$label,
            'email' => 'entitlement-'.$suffix.'@example.test',
            'username' => 'ent_'.$suffix,
            'password' => 'Raven-Entitlement-Test-Password-42!',
        ])->assertCreated();

        return [
            'id' => (int) $response->json('user.id'),
            'token' => (string) $response->json('token'),
        ];
    }

    public function test_entitlement_read_requires_authentication_and_defaults_to_no_paid_access(): void
    {
        $this->getJson('/api/v1/user/entitlements')->assertUnauthorized();

        $account = $this->account('default denied');

        $this->withToken($account['token'])
            ->getJson('/api/v1/user/entitlements')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('entitlements.ad_free', false)
            ->assertJsonPath('entitlements.starts_at', null)
            ->assertJsonPath('entitlements.expires_at', null)
            ->assertJsonPath('entitlements.revision', 0)
            ->assertJsonPath('refresh_after_seconds', 300);
    }

    public function test_entitlement_is_calculated_from_the_authenticated_owner_only(): void
    {
        $owner = $this->account('owner');
        $other = $this->account('other');

        UserEntitlement::create([
            'user_id' => $other['id'],
            'code' => 'ad_free',
            'starts_at' => now('UTC')->subDay(),
            'expires_at' => now('UTC')->addDay(),
            'revision' => 7,
            'source' => 'test_fixture',
            'source_reference' => 'owner-isolation',
        ]);

        $this->withToken($owner['token'])
            ->getJson('/api/v1/user/entitlements?user_id='.$other['id'])
            ->assertOk()
            ->assertJsonPath('entitlements.ad_free', false)
            ->assertJsonPath('entitlements.revision', 0);

        $this->withToken($other['token'])
            ->getJson('/api/v1/user/entitlements')
            ->assertOk()
            ->assertJsonPath('entitlements.ad_free', true)
            ->assertJsonPath('entitlements.revision', 7);
    }

    public function test_expired_future_and_revoked_entitlements_do_not_grant_access(): void
    {
        $expired = $this->account('expired');
        $future = $this->account('future');
        $revoked = $this->account('revoked');

        UserEntitlement::create([
            'user_id' => $expired['id'],
            'code' => 'ad_free',
            'starts_at' => now('UTC')->subDays(3),
            'expires_at' => now('UTC')->subDay(),
            'revision' => 2,
            'source' => 'test_fixture',
        ]);
        UserEntitlement::create([
            'user_id' => $future['id'],
            'code' => 'ad_free',
            'starts_at' => now('UTC')->addDay(),
            'expires_at' => now('UTC')->addDays(2),
            'revision' => 3,
            'source' => 'test_fixture',
        ]);
        UserEntitlement::create([
            'user_id' => $revoked['id'],
            'code' => 'ad_free',
            'starts_at' => now('UTC')->subDay(),
            'expires_at' => now('UTC')->addDay(),
            'revoked_at' => now('UTC')->subHour(),
            'revision' => 4,
            'source' => 'test_fixture',
        ]);

        foreach ([$expired, $future, $revoked] as $account) {
            $this->withToken($account['token'])
                ->getJson('/api/v1/user/entitlements')
                ->assertOk()
                ->assertJsonPath('entitlements.ad_free', false);
        }
    }

    public function test_guests_with_a_valid_session_have_no_entitlement_without_a_trusted_grant(): void
    {
        $response = $this->postJson('/api/v1/auth/guest', []);
        $response->assertCreated();

        $this->withToken((string) $response->json('token'))
            ->getJson('/api/v1/user/entitlements')
            ->assertOk()
            ->assertJsonPath('entitlements.ad_free', false)
            ->assertJsonPath('entitlements.revision', 0);
    }
}
