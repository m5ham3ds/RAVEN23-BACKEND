<?php

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Boots the same Laravel application and production API routes used on Render.
 */
class PostgresAuthLifecycleTest extends TestCase
{
    public function createApplication()
    {
        return require dirname(__DIR__, 2) . '/bootstrap/app.php';
    }

    private array $createdUsers = [];
    private array $createdTokens = [];
    private array $createdEmails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $databaseUrl = trim((string) (getenv('NEON_DB_URL') ?: getenv('DB_URL')));
        if ($databaseUrl === '') {
            $this->markTestSkipped('NEON_DB_URL is not configured for the PostgreSQL integration suite.');
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
        ]);
        DB::purge('pgsql');

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        DB::connection()->selectOne('select 1');

        $identity = DB::selectOne(
            "select current_database() as database_name, current_schema() as schema_name, current_setting('search_path') as search_path"
        );
        $userColumns = Schema::getColumnListing('users');
        $tokenColumns = Schema::getColumnListing('personal_access_tokens');

        fwrite(STDOUT, sprintf(
            "[RAVEN23 PostgreSQL integration] database=%s schema=%s search_path=%s users_username=%s tokens_device_metadata=%s\n",
            (string) $identity->database_name,
            (string) $identity->schema_name,
            (string) $identity->search_path,
            in_array('username', $userColumns, true) ? 'yes' : 'no',
            (in_array('device_id', $tokenColumns, true)
                && in_array('device_name', $tokenColumns, true)
                && in_array('platform', $tokenColumns, true)) ? 'yes' : 'no',
        ));

        $this->assertContains('username', $userColumns, 'PostgreSQL users.username column is missing.');
        $this->assertContains('device_id', $tokenColumns, 'PostgreSQL personal_access_tokens.device_id column is missing.');
        $this->assertContains('device_name', $tokenColumns, 'PostgreSQL personal_access_tokens.device_name column is missing.');
        $this->assertContains('platform', $tokenColumns, 'PostgreSQL personal_access_tokens.platform column is missing.');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdTokens) as $tokenId) {
            try {
                DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
            } catch (\\Throwable) {
                // Preserve the original test failure; cleanup is best-effort.
            }
        }

        foreach (array_reverse($this->createdUsers) as $userId) {
            try {
                DB::table('users')->where('id', $userId)->delete();
            } catch (\\Throwable) {
                // Preserve the original test failure; cleanup is best-effort.
            }
        }

        if ($this->createdEmails !== []) {
            try {
                $orphanedIds = DB::table('users')->whereIn('email', $this->createdEmails)->pluck('id')->all();
                if ($orphanedIds !== []) {
                    DB::table('personal_access_tokens')
                        ->where('tokenable_type', User::class)
                        ->whereIn('tokenable_id', $orphanedIds)
                        ->delete();
                    DB::table('users')->whereIn('id', $orphanedIds)->delete();
                }
            } catch (\\Throwable) {
                // Best-effort cleanup only for unique synthetic test emails.
            }
        }

        parent::tearDown();
    }

    private function accountPayload(): array
    {
        $suffix = bin2hex(random_bytes(8));
        $email = "raven-pg-{$suffix}@example.test";
        $this->createdEmails[] = $email;

        return [
            'name' => 'RAVEN23 PostgreSQL Test',
            'email' => $email,
            'username' => "raven_pg_{$suffix}",
            'password' => 'Raven-PG-Test-Password-42!',
        ];
    }

    private function rememberSession(array $response): array
    {
        $userId = (int) ($response['user']['id'] ?? 0);
        $plainToken = $response['token'] ?? null;

        $this->assertGreaterThan(0, $userId);
        $this->assertIsString($plainToken);
        $this->assertNotSame('', $plainToken);

        $this->createdUsers[] = $userId;
        $storedToken = PersonalAccessToken::findToken($plainToken);
        $this->assertNotNull($storedToken);
        $this->createdTokens[] = (int) $storedToken->id;

        return [$userId, $plainToken];
    }

    public function test_postgresql_email_registration_and_login_create_real_sanctum_sessions(): void
    {
        $data = $this->accountPayload();
        $registration = $this->postJson('/api/v1/auth/register', $data);

        $this->assertSame(201, $registration->status(), 'Email registration response: ' . $registration->getContent());
        $registration->assertJsonPath('success', true);
        [$userId, $firstToken] = $this->rememberSession($registration->json());

        $user = User::findOrFail($userId);
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $userId,
            'tokenable_type' => User::class,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $data['email'],
            'password' => $data['password'],
        ])->assertOk();

        [$loginUserId, $secondToken] = $this->rememberSession($login->json());
        $this->assertSame($userId, $loginUserId);
        $this->assertNotSame($firstToken, $secondToken);
        $this->withToken($secondToken)->getJson('/api/v1/user/profile')->assertOk()->assertJsonPath('user.id', $userId);
    }

    public function test_postgresql_guest_creation_and_session_are_successful(): void
    {
        $response = $this->postJson('/api/v1/auth/guest');
        $this->assertSame(200, $response->status(), 'Guest registration response: ' . $response->getContent());

        [$userId, $token] = $this->rememberSession($response->json());
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'is_guest' => true,
            'provider' => 'guest',
        ]);

        $this->withToken($token)->getJson('/api/v1/user/profile')->assertOk()->assertJsonPath('user.id', $userId);
    }

    public function test_postgresql_device_token_replacement_and_logout_are_persistent(): void
    {
        $data = $this->accountPayload();
        $deviceId = '7f3a1dd2-02ec-4e8f-bf53-8d75f55a6e4b';

        $first = $this->postJson('/api/v1/auth/register', $data + [
            'device_id' => $deviceId,
            'device_name' => 'PostgreSQL Integration Device',
            'platform' => 'android',
        ]);
        $this->assertSame(201, $first->status(), 'Device registration response: ' . $first->getContent());
        [$userId, $firstToken] = $this->rememberSession($first->json());

        $second = $this->postJson('/api/v1/auth/login', [
            'email' => $data['email'],
            'password' => $data['password'],
            'device_id' => $deviceId,
            'device_name' => 'PostgreSQL Integration Device',
            'platform' => 'android',
        ])->assertOk();

        [$secondUserId, $secondToken] = $this->rememberSession($second->json());
        $this->assertSame($userId, $secondUserId);
        $this->assertNull(PersonalAccessToken::findToken($firstToken));
        $this->assertNotNull(PersonalAccessToken::findToken($secondToken));

        $this->withToken($secondToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($secondToken));
    }
}
