<?php

namespace Tests\Integration;

use App\Models\User;
use App\Models\UserPushSubscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PostgresPushSubscriptionTest extends TestCase
{
    private array $createdUsers = [];

    private array $createdTokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        $databaseUrl = trim((string) (getenv('NEON_DB_URL') ?: getenv('DB_URL')));
        if ($databaseUrl === '') {
            $this->markTestSkipped('DB_URL is not configured for PostgreSQL integration tests.');
        }

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.driver' => 'pgsql',
            'database.connections.pgsql.url' => $databaseUrl,
            'database.connections.pgsql.host' => null,
            'database.connections.pgsql.port' => null,
            'database.connections.pgsql.database' => null,
            'database.connections.pgsql.username' => null,
            'database.connections.pgsql.password' => null,
            'database.connections.pgsql.sslmode' => 'require',
            'cache.default' => 'array',
            'app.url' => 'http://localhost',
        ]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        DB::connection()->selectOne('select 1');
    }

    private function createAccount(string $label): array
    {
        $suffix = bin2hex(random_bytes(6));
        $user = User::create([
            'name' => 'RAVEN23 PostgreSQL '.$label,
            'email' => 'raven-push-'.$suffix.'@example.test',
            'username' => 'push_pg_'.$suffix,
            'password' => 'Raven-Push-Postgres-42!',
        ]);
        $plainText = $user->createToken('PostgreSQL push '.$label)->plainTextToken;
        $stored = PersonalAccessToken::findToken($plainText);

        $this->createdUsers[] = (int) $user->getKey();
        $this->createdTokens[] = (int) $stored->getKey();

        return ['id' => (int) $user->getKey(), 'token' => $plainText];
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        if ($this->createdTokens !== []) {
            DB::table('personal_access_tokens')->whereIn('id', $this->createdTokens)->delete();
        }
        if ($this->createdUsers !== []) {
            DB::table('users')->whereIn('id', $this->createdUsers)->delete();
        }

        parent::tearDown();
    }

    public function test_postgresql_push_subscriptions_are_encrypted_idempotent_and_owner_scoped(): void
    {
        $first = $this->createAccount('first');
        $second = $this->createAccount('second');
        $subscriptionId = '33333333-3333-4333-8333-333333333333';
        $fcmToken = 'synthetic-fcm-postgres-token-0123456789';

        $this->withToken($first['token'])
            ->postJson('/api/v1/devices/register', [
                'fcm_token' => $fcmToken,
                'platform' => 'android',
                'app_version' => '4.4.0',
            ])
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($first['token'])
            ->postJson('/api/v1/push/subscriptions', [
                'subscription_id' => $subscriptionId,
                'platform' => 'tv',
                'app_version' => '4.4.0',
            ])
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($first['token'])
            ->postJson('/api/v1/push/subscriptions', [
                'subscription_id' => $subscriptionId,
                'platform' => 'tv',
                'app_version' => '4.4.1',
            ])
            ->assertOk();

        $this->assertDatabaseCount('user_push_subscriptions', 2);
        $storedFcm = UserPushSubscription::query()->where('provider', 'fcm')->sole();
        $storedOneSignal = UserPushSubscription::query()->where('provider', 'onesignal')->sole();
        $this->assertSame($fcmToken, $storedFcm->token);
        $this->assertNotSame($fcmToken, DB::table('user_push_subscriptions')->where('provider', 'fcm')->value('token'));
        $this->assertSame('4.4.1', $storedOneSignal->app_version);

        Auth::forgetGuards();
        $this->withToken($second['token'])
            ->postJson('/api/v1/push/subscriptions', [
                'subscription_id' => $subscriptionId,
                'platform' => 'tv',
                'app_version' => '4.4.1',
            ])
            ->assertOk();

        $this->assertDatabaseCount('user_push_subscriptions', 2);
        $this->assertSame($second['id'], (int) UserPushSubscription::query()
            ->where('provider', 'onesignal')
            ->sole()
            ->user_id);

        Auth::forgetGuards();
        $this->withToken($first['token'])
            ->deleteJson('/api/v1/push/subscriptions/'.$subscriptionId)
            ->assertOk();
        $this->assertDatabaseCount('user_push_subscriptions', 2);

        Auth::forgetGuards();
        $this->withToken($second['token'])
            ->deleteJson('/api/v1/push/subscriptions/'.$subscriptionId)
            ->assertOk();
        $this->assertDatabaseCount('user_push_subscriptions', 1);
    }
}
