<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserDataFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'cache.default' => 'array',
            'mail.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function registerAccount(array $overrides = []): array
    {
        $suffix = bin2hex(random_bytes(5));
        $payload = array_merge([
            'name' => 'RAVEN23 Data Test',
            'email' => 'data-'.$suffix.'@example.test',
            'username' => 'data_'.$suffix,
            'password' => 'Raven-Data-Test-Password-42!',
        ], $overrides);

        $response = $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

        return [
            'id' => (int) $response->json('user.id'),
            'token' => (string) $response->json('token'),
            'email' => $payload['email'],
        ];
    }

    public function test_account_preferences_are_private_validated_and_idempotent(): void
    {
        $first = $this->registerAccount();
        $second = $this->registerAccount();

        $default = $this->withToken($first['token'])
            ->getJson('/api/v1/user/preferences')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('preferences.locale', null)
            ->assertJsonPath('preferences.timezone', 'UTC');

        $payload = ['locale' => 'ar', 'timezone' => 'Africa/Khartoum'];
        $saved = $this->withToken($first['token'])
            ->putJson('/api/v1/user/preferences', $payload)
            ->assertOk()
            ->assertJsonPath('preferences.locale', 'ar')
            ->assertJsonPath('preferences.timezone', 'Africa/Khartoum');

        $replayed = $this->withToken($first['token'])
            ->putJson('/api/v1/user/preferences', $payload)
            ->assertOk()
            ->assertJsonPath('preferences.locale', 'ar')
            ->assertJsonPath('preferences.timezone', 'Africa/Khartoum');

        $this->withToken($second['token'])
            ->getJson('/api/v1/user/preferences')
            ->assertOk()
            ->assertJsonPath('preferences.locale', null)
            ->assertJsonPath('preferences.timezone', 'UTC');

        $this->withToken($first['token'])
            ->putJson('/api/v1/user/preferences', ['timezone' => 'Mars/Olympus_Mons'])
            ->assertUnprocessable();

        $this->withToken($first['token'])
            ->putJson('/api/v1/user/preferences', ['locale' => 'invalid locale'])
            ->assertUnprocessable();

        $this->withToken($first['token'])
            ->putJson('/api/v1/user/preferences', [])
            ->assertUnprocessable();

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $first['id'],
            'locale' => 'ar',
            'timezone' => 'Africa/Khartoum',
        ]);
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $second['id'],
            'locale' => null,
            'timezone' => 'UTC',
        ]);
        $this->assertNotNull($saved->json('preferences.updated_at'));
        $this->assertNotNull($replayed->json('preferences.updated_at'));
        $this->assertSame('UTC', $default->json('preferences.timezone'));
    }

    public function test_profile_updates_mirror_legacy_fields_without_changing_existing_response_contract(): void
    {
        $account = $this->registerAccount();
        $this->withToken($account['token'])
            ->putJson('/api/v1/user/profile', [
                'name' => 'Updated Data User',
                'profile_id' => 37,
                'photo_url' => 'https://cdn.example.test/avatar.webp',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.name', 'Updated Data User')
            ->assertJsonPath('user.profile_id', 37)
            ->assertJsonPath('user.photo_url', 'https://cdn.example.test/avatar.webp');

        $this->assertDatabaseHas('users', [
            'id' => $account['id'],
            'profile_id' => 37,
            'photo_url' => 'https://cdn.example.test/avatar.webp',
        ]);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $account['id'],
            'profile_id' => 37,
            'photo_url' => 'https://cdn.example.test/avatar.webp',
        ]);
    }

    public function test_export_contains_only_the_authenticated_accounts_server_held_data(): void
    {
        $first = $this->registerAccount([
            'device_id' => '11111111-1111-4111-8111-111111111111',
            'device_name' => 'Raven phone',
            'platform' => 'android',
        ]);
        $second = $this->registerAccount([
            'device_id' => '22222222-2222-4222-8222-222222222222',
            'device_name' => 'Raven tablet',
            'platform' => 'android',
        ]);

        $this->withToken($first['token'])->putJson('/api/v1/user/preferences', [
            'locale' => 'ar',
            'timezone' => 'Africa/Khartoum',
        ])->assertOk();

        $export = $this->withToken($first['token'])
            ->getJson('/api/v1/user/data-export')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.format_version', 1)
            ->assertJsonPath('data.account.id', $first['id'])
            ->assertJsonPath('data.preferences.locale', 'ar')
            ->assertJsonPath('data.preferences.timezone', 'Africa/Khartoum')
            ->assertJsonCount(1, 'data.devices');

        $json = $export->json('data');
        $this->assertSame('11111111-1111-4111-8111-111111111111', $json['devices'][0]['device_id']);
        $this->assertArrayNotHasKey('password', $json['account']);
        $this->assertArrayNotHasKey('google_id', $json['account']);
        $this->assertArrayNotHasKey('token', $json['account']);
        $this->assertArrayNotHasKey('token_hash', $json['account']);
        $this->assertArrayNotHasKey('personal_access_tokens', $json);
        $this->assertStringNotContainsString($second['email'], $export->getContent());
        $this->assertStringNotContainsString($second['token'], $export->getContent());

        $this->withToken($second['token'])
            ->getJson('/api/v1/user/data-export')
            ->assertOk()
            ->assertJsonPath('data.account.id', $second['id'])
            ->assertJsonCount(1, 'data.devices');
    }

    public function test_same_installation_updates_one_account_device_record(): void
    {
        $deviceId = '33333333-3333-4333-8333-333333333333';
        $account = $this->registerAccount([
            'device_id' => $deviceId,
            'device_name' => 'Old device name',
            'platform' => 'android',
        ]);
        $firstSeen = DB::table('user_devices')->where('user_id', $account['id'])->value('first_seen_at');

        $this->postJson('/api/v1/auth/login', [
            'email' => $account['email'],
            'password' => 'Raven-Data-Test-Password-42!',
            'device_id' => $deviceId,
            'device_name' => 'Updated device name',
            'platform' => 'tv',
        ])->assertOk();

        $this->assertDatabaseCount('user_devices', 1);
        $device = DB::table('user_devices')->where('user_id', $account['id'])->first();
        $this->assertSame('Updated device name', $device->device_name);
        $this->assertSame('tv', $device->platform);
        $this->assertSame($firstSeen, $device->first_seen_at);
        $this->assertGreaterThanOrEqual(strtotime($firstSeen), strtotime($device->last_seen_at));
    }

    public function test_account_deletion_cascades_to_profiles_preferences_and_device_records(): void
    {
        $account = $this->registerAccount([
            'device_id' => '44444444-4444-4444-8444-444444444444',
            'device_name' => 'Delete me',
            'platform' => 'android',
        ]);

        $this->assertDatabaseHas('user_profiles', ['user_id' => $account['id']]);
        $this->assertDatabaseHas('user_preferences', ['user_id' => $account['id']]);
        $this->assertDatabaseHas('user_devices', ['user_id' => $account['id']]);

        $this->withToken($account['token'])->deleteJson('/api/v1/user/account')->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $account['id']]);
        $this->assertDatabaseMissing('user_profiles', ['user_id' => $account['id']]);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $account['id']]);
        $this->assertDatabaseMissing('user_devices', ['user_id' => $account['id']]);
    }
}
