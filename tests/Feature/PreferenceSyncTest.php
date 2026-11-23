<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PreferenceSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function registerAccount(): array
    {
        $suffix = bin2hex(random_bytes(5));
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'RAVEN23 Preference Test',
            'email' => 'preferences-'.$suffix.'@example.test',
            'username' => 'preferences_'.$suffix,
            'password' => 'Raven-Preferences-Test-Password-42!',
        ])->assertCreated();

        return ['id' => (int) $response->json('user.id'), 'token' => (string) $response->json('token')];
    }

    private function operation(string $id, int $baseRevision, array $settings): array
    {
        return [
            'operation_id' => $id,
            'action' => 'preferences.update',
            'base_revision' => $baseRevision,
            ...$settings,
        ];
    }

    public function test_full_and_incremental_preference_sync_are_revisioned_and_idempotent(): void
    {
        $account = $this->registerAccount();
        $initial = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/preferences', ['since_revision' => 0])
            ->assertOk()
            ->assertJsonPath('server_revision', 0)
            ->assertJsonPath('full', true)
            ->assertJsonPath('changed', false)
            ->assertJsonPath('preferences.locale', null)
            ->assertJsonPath('preferences.timezone', 'UTC')
            ->assertJsonPath('preferences.revision', 0);

        $operation = $this->operation(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            0,
            ['locale' => 'ar', 'timezone' => 'Africa/Khartoum'],
        );

        Auth::forgetGuards();
        $updated = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 0,
                'operations' => [$operation],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('full', true)
            ->assertJsonPath('changed', true)
            ->assertJsonPath('preferences.locale', 'ar')
            ->assertJsonPath('preferences.timezone', 'Africa/Khartoum')
            ->assertJsonPath('preferences.revision', 1)
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.revision', 1);

        Auth::forgetGuards();
        $replay = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 1,
                'operations' => [$operation],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('changed', false)
            ->assertJsonPath('preferences', null)
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.revision', 1);

        Auth::forgetGuards();
        $incremental = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/preferences', ['since_revision' => 0])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('preferences.locale', 'ar')
            ->assertJsonPath('preferences.timezone', 'Africa/Khartoum');

        $this->assertSame(0, $initial->json('server_revision'));
        $this->assertSame(1, $updated->json('server_revision'));
        $this->assertSame(1, $replay->json('server_revision'));
        $this->assertSame(1, $incremental->json('server_revision'));
        $this->assertDatabaseCount('user_preference_operations', 1);
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $account['id'],
            'locale' => 'ar',
            'timezone' => 'Africa/Khartoum',
            'revision' => 1,
        ]);
    }

    public function test_stale_device_update_conflicts_without_overwriting_current_preferences(): void
    {
        $account = $this->registerAccount();
        $user = User::findOrFail($account['id']);
        $secondDeviceToken = $user->createToken('preference-sync-second-device')->plainTextToken;

        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 0,
                'operations' => [$this->operation(
                    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    0,
                    ['locale' => 'ar'],
                )],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('preferences.locale', 'ar');

        Auth::forgetGuards();
        $stale = $this->withToken($secondDeviceToken)
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 0,
                'operations' => [$this->operation(
                    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                    0,
                    ['timezone' => 'Africa/Tripoli'],
                )],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.code', 'stale_write')
            ->assertJsonPath('results.0.current_revision', 1)
            ->assertJsonPath('preferences.locale', 'ar')
            ->assertJsonPath('preferences.timezone', 'UTC');

        Auth::forgetGuards();
        $fresh = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 1,
                'operations' => [$this->operation(
                    'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                    1,
                    ['timezone' => 'Africa/Khartoum'],
                )],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2)
            ->assertJsonPath('results.0.status', 'applied');

        Auth::forgetGuards();
        $replayedConflict = $this->withToken($secondDeviceToken)
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 2,
                'operations' => [$this->operation(
                    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                    0,
                    ['timezone' => 'Africa/Tripoli'],
                )],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2)
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.current_revision', 1);

        $this->assertSame('Africa/Khartoum', $fresh->json('preferences.timezone'));
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $account['id'],
            'locale' => 'ar',
            'timezone' => 'Africa/Khartoum',
            'revision' => 2,
        ]);
        $this->assertDatabaseCount('user_preference_operations', 3);
        $this->assertSame(2, $replayedConflict->json('server_revision'));
        $this->assertSame(1, $stale->json('server_revision'));
    }

    public function test_legacy_preferences_put_advances_the_sync_revision(): void
    {
        $account = $this->registerAccount();

        $this->withToken($account['token'])
            ->getJson('/api/v1/user/preferences')
            ->assertOk()
            ->assertJsonPath('preferences.revision', 0);

        Auth::forgetGuards();
        $updated = $this->withToken($account['token'])
            ->putJson('/api/v1/user/preferences', [
                'locale' => 'fr',
                'timezone' => 'Europe/Paris',
            ])
            ->assertOk()
            ->assertJsonPath('preferences.locale', 'fr')
            ->assertJsonPath('preferences.timezone', 'Europe/Paris')
            ->assertJsonPath('preferences.revision', 1);

        Auth::forgetGuards();
        $sync = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/preferences', ['since_revision' => 0])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('preferences.locale', 'fr')
            ->assertJsonPath('preferences.timezone', 'Europe/Paris');

        $this->assertSame(1, $updated->json('preferences.revision'));
        $this->assertSame(1, $sync->json('preferences.revision'));
    }

    public function test_preferences_are_owner_scoped_and_invalid_updates_are_rejected(): void
    {
        $owner = $this->registerAccount();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 0,
                'operations' => [$this->operation(
                    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    0,
                    ['locale' => 'ar'],
                )],
            ])
            ->assertOk();

        Auth::forgetGuards();
        $other = $this->registerAccount();
        $otherPull = $this->withToken($other['token'])
            ->postJson('/api/v1/sync/preferences', ['since_revision' => 0])
            ->assertOk()
            ->assertJsonPath('server_revision', 0)
            ->assertJsonPath('preferences.locale', null)
            ->assertJsonPath('preferences.timezone', 'UTC');

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 1,
                'operations' => [[
                    'operation_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                    'action' => 'preferences.update',
                    'base_revision' => 1,
                ]],
            ])
            ->assertUnprocessable();

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 1,
                'operations' => [$this->operation(
                    'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                    1,
                    ['timezone' => 'Not/A-Timezone'],
                )],
            ])
            ->assertUnprocessable();

        Auth::forgetGuards();
        $this->withToken('invalid-preference-test-token')
            ->postJson('/api/v1/sync/preferences', ['since_revision' => 0])
            ->assertUnauthorized();

        $this->assertSame(0, $otherPull->json('server_revision'));
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $owner['id'],
            'locale' => 'ar',
            'revision' => 1,
        ]);
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $other['id'],
            'locale' => null,
            'timezone' => 'UTC',
            'revision' => 0,
        ]);
        $this->assertDatabaseCount('user_preference_operations', 1);
    }

    public function test_operation_id_reuse_is_rejected_without_mutating_preferences(): void
    {
        $account = $this->registerAccount();
        $operation = $this->operation(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            0,
            ['locale' => 'ar'],
        );

        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 0,
                'operations' => [$operation],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1);

        $reused = $operation;
        $reused['locale'] = 'fr';
        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 1,
                'operations' => [$reused],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'operation_id_reused');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $account['id'],
            'locale' => 'ar',
            'revision' => 1,
        ]);
        $this->assertDatabaseCount('user_preference_operations', 1);
    }
}
