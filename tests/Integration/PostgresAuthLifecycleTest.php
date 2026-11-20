<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Runs only when the Docker validation build receives NEON_DB_URL.
 * Unlike AuthLifecycleTest, this suite exercises real PostgreSQL semantics.
 */
class PostgresAuthLifecycleTest extends TestCase
{
    private array $createdUsers = [];
    private array $createdTokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgresAuthLifecycleTest requires PostgreSQL.');
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdTokens) as $tokenId) {
            try {
                DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
            } catch (\Throwable) {
                // Preserve the original test failure; cleanup is best-effort.
            }
        }

        foreach (array_reverse($this->createdUsers) as $userId) {
            try {
                DB::table('users')->where('id', $userId)->delete();
            } catch (\Throwable) {
                // Preserve the original test failure; cleanup is best-effort.
            }
        }

        parent::tearDown();
    }

    private function accountPayload(): array
    {
        $suffix = bin2hex(random_bytes(8));

        return [
            'name' => 'RAVEN23 PostgreSQL Test',
            'email' => "raven-pg-{$suffix}@example.test",
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

        $registration = $this->postJson('/api/v1/auth/register', $data)
            ->assertCreated()
            ->assertJsonPath('success', true);

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

        $this->withToken($secondToken)
            ->getJson('/api/v1/user/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $userId);
    }

    public function test_postgresql_guest_creation_and_session_are_successful(): void
    {
        $response = $this->postJson('/api/v1/auth/guest')->assertOk();

        [$userId, $token] = $this->rememberSession($response->json());
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'is_guest' => true,
            'provider' => 'guest',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/user/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $userId);
    }

    public function test_postgresql_device_token_replacement_and_logout_are_persistent(): void
    {
        $data = $this->accountPayload();
        $deviceId = '7f3a1dd2-02ec-4e8f-bf53-8d75f55a6e4b';

        $first = $this->postJson('/api/v1/auth/register', $data + [
            'device_id' => $deviceId,
            'device_name' => 'PostgreSQL Integration Device',
            'platform' => 'android',
        ])->assertCreated();

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

        $this->withToken($secondToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($secondToken));
    }
}
