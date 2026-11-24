<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPushSubscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
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
            'name' => 'RAVEN23 Push '.$label,
            'email' => 'push-'.$suffix.'@example.test',
            'username' => 'push_'.$suffix,
            'password' => 'Raven-Push-Test-Password-42!',
        ])->assertCreated();

        return [
            'id' => (int) $response->json('user.id'),
            'token' => (string) $response->json('token'),
        ];
    }

    public function test_fcm_registration_is_authenticated_encrypted_and_idempotent(): void
    {
        $owner = $this->account('FCM');
        $fcmToken = 'synthetic-fcm-token-0123456789abcdef';

        $this->withToken($owner['token'])
            ->postJson('/api/v1/devices/register', [
                'fcm_token' => $fcmToken,
                'platform' => 'android',
                'app_version' => '4.4.0+12',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $stored = UserPushSubscription::query()->sole();
        $this->assertSame($owner['id'], (int) $stored->user_id);
        $this->assertSame('fcm', $stored->provider);
        $this->assertSame($fcmToken, $stored->token);
        $this->assertSame('android', $stored->platform);
        $this->assertNotSame($fcmToken, DB::table('user_push_subscriptions')->value('token'));
        $this->assertSame(
            hash_hmac('sha256', 'fcm:'.$fcmToken, (string) config('app.key')),
            $stored->token_hash
        );

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/devices/register', [
                'fcm_token' => $fcmToken,
                'platform' => 'android',
                'app_version' => '4.4.1',
            ])
            ->assertOk();

        $this->assertDatabaseCount('user_push_subscriptions', 1);
        $this->assertSame('4.4.1', UserPushSubscription::query()->sole()->app_version);
    }

    public function test_onesignal_subscription_matches_flutter_contract_and_is_owner_scoped(): void
    {
        $first = $this->account('OneSignal owner');
        $second = $this->account('OneSignal next owner');
        $subscriptionId = '11111111-1111-4111-8111-111111111111';

        $this->withToken($first['token'])
            ->postJson('/api/v1/push/subscriptions', [
                'subscription_id' => $subscriptionId,
                'platform' => 'tv',
                'app_version' => '4.4.0',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Auth::forgetGuards();
        $this->withToken($first['token'])
            ->postJson('/api/v1/push/subscriptions', [
                'subscription_id' => $subscriptionId,
                'platform' => 'tv',
                'app_version' => '4.4.1',
            ])
            ->assertOk();

        $this->assertDatabaseCount('user_push_subscriptions', 1);
        $this->assertSame('4.4.1', UserPushSubscription::query()->sole()->app_version);

        // OneSignal IDs are device-scoped; a new authenticated account claims
        // the same installation and the old account must no longer own it.
        Auth::forgetGuards();
        $this->withToken($second['token'])
            ->postJson('/api/v1/push/subscriptions', [
                'subscription_id' => $subscriptionId,
                'platform' => 'tv',
                'app_version' => '4.4.1',
            ])
            ->assertOk();

        $this->assertDatabaseCount('user_push_subscriptions', 1);
        $this->assertSame($second['id'], (int) UserPushSubscription::query()->sole()->user_id);

        Auth::forgetGuards();
        $this->withToken($first['token'])
            ->deleteJson('/api/v1/push/subscriptions/'.$subscriptionId)
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseCount('user_push_subscriptions', 1);

        Auth::forgetGuards();
        $this->withToken($second['token'])
            ->deleteJson('/api/v1/push/subscriptions/'.$subscriptionId)
            ->assertOk();
        $this->assertDatabaseCount('user_push_subscriptions', 0);
    }

    public function test_push_registration_rejects_guests_without_auth_and_malformed_tokens(): void
    {
        $this->postJson('/api/v1/push/subscriptions', [
            'subscription_id' => 'not-a-uuid',
            'platform' => 'android',
        ])->assertUnauthorized();

        $owner = $this->account('validation');

        $this->withToken($owner['token'])
            ->postJson('/api/v1/devices/register', [
                'fcm_token' => 'too-short',
                'platform' => 'android',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fcm_token');

        $this->withToken($owner['token'])
            ->postJson('/api/v1/push/subscriptions', [
                'subscription_id' => 'not-a-uuid',
                'platform' => 'android',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_id');
    }

    public function test_account_deletion_cascades_push_subscriptions(): void
    {
        $owner = $this->account('delete');
        $subscriptionId = '22222222-2222-4222-8222-222222222222';

        $this->withToken($owner['token'])
            ->postJson('/api/v1/push/subscriptions', [
                'subscription_id' => $subscriptionId,
                'platform' => 'android',
            ])
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($owner['token'])->deleteJson('/api/v1/user/account')->assertOk();

        $this->assertDatabaseMissing('user_push_subscriptions', ['user_id' => $owner['id']]);
        $this->assertDatabaseMissing('users', ['id' => $owner['id']]);
    }
}
