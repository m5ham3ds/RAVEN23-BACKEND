<?php

namespace Tests\Integration;

use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Boots the same Laravel application and production API routes used on Render.
 */
class PostgresAuthLifecycleTest extends TestCase
{
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

        // This test must use an origin-only base URL. Laravel's postJson()
        // converts relative paths through url(), so a path-bearing APP_URL can
        // silently turn /api/v1/... into a different request path and return 404.
        config(['app.url' => 'http://localhost']);
        URL::forceRootUrl('http://localhost');

        $preparedUrl = $this->prepareUrlForRequest('/api/v1/auth/register');
        $preparedPath = parse_url($preparedUrl, PHP_URL_PATH) ?: '/';
        fwrite(STDOUT, sprintf(
            "[RAVEN23 HTTP request URL] path=%s host=%s app_url_path=%s\n",
            $preparedPath,
            parse_url($preparedUrl, PHP_URL_HOST) ?: 'missing',
            parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/',
        ));
        $this->assertSame('/api/v1/auth/register', $preparedPath, 'Laravel postJson() must preserve the API route path.');

        $routes = $this->app->make('router')->getRoutes();
        $registered = collect($routes)->first(static function ($route): bool {
            return $route->uri() === 'api/v1/auth/register'
                && in_array('POST', $route->methods(), true);
        });
        $this->assertNotNull($registered, 'POST /api/v1/auth/register must be registered by the shared Laravel test bootstrap.');

        $routeRequest = Request::create('/api/v1/auth/register', 'POST');
        $matchedRoute = $routes->match($routeRequest);
        $this->assertSame('api/v1/auth/register', $matchedRoute->uri());
        $this->assertContains('POST', $matchedRoute->methods());

        $kernel = $this->app->make(HttpKernel::class);
        $routerProperty = new \ReflectionProperty($kernel, 'router');
        $kernelRouter = $routerProperty->getValue($kernel);
        $kernelRoutes = $kernelRouter->getRoutes();
        $kernelRegistered = collect($kernelRoutes)->first(static function ($route): bool {
            return $route->uri() === 'api/v1/auth/register'
                && in_array('POST', $route->methods(), true);
        });
        fwrite(STDOUT, sprintf(
            "[RAVEN23 route dispatch] app_router=%s kernel_router=%s app_route=%s kernel_route=%s methods=%s path=%s compiled=%s\n",
            spl_object_id($this->app->make('router')),
            spl_object_id($kernelRouter),
            $registered->uri(),
            $kernelRegistered?->uri() ?? 'missing',
            implode(',', $matchedRoute->methods()),
            $routeRequest->getPathInfo(),
            method_exists($kernelRoutes, 'isCompiled') && $kernelRoutes->isCompiled() ? 'yes' : 'no',
        ));
        $this->assertNotNull($kernelRegistered, 'HTTP Kernel router must contain POST /api/v1/auth/register.');

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

    public function test_postgresql_verification_reset_and_device_routes_use_restricted_application_role(): void
    {
        Notification::fake();
        config([
            'services.raven23_auth.mail_enabled' => true,
            'services.raven23_auth.action_url' => 'https://raven23.example.test/auth',
            'mail.default' => 'array',
            'auth.passwords.users.expire' => 60,
            'auth.passwords.users.throttle' => 0,
            'security.email_verification_expire_minutes' => 60,
        ]);

        $data = $this->accountPayload();
        $data['device_id'] = 'a83d810e-d04a-4796-a7c8-61fe1e21b4a8';
        $data['device_name'] = 'PostgreSQL Android';
        $data['platform'] = 'android';

        $registration = $this->postJson('/api/v1/auth/register', $data)->assertCreated();
        [$userId, $accessToken] = $this->rememberSession($registration->json());
        $user = User::on('pgsql')->findOrFail($userId);

        $verificationToken = null;
        Notification::assertSentTo(
            $user,
            EmailVerificationNotification::class,
            function ($notification) use (&$verificationToken): bool {
                $verificationToken = $notification->token;

                return true;
            },
        );

        $this->assertDatabaseHas('email_verification_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $verificationToken),
        ]);
        $this->postJson('/api/v1/auth/verify-email', [
            'user_id' => $userId,
            'token' => $verificationToken,
        ])->assertOk()->assertJsonPath('verified', true);

        $devices = $this->withToken($accessToken)->getJson('/api/v1/user/devices')
            ->assertOk()->assertJsonCount(1, 'devices');
        $this->assertSame('PostgreSQL Android', $devices->json('devices.0.device_name'));

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $data['email']])->assertAccepted();
        $resetToken = null;
        Notification::assertSentTo(
            $user,
            PasswordResetNotification::class,
            function ($notification) use (&$resetToken): bool {
                $resetToken = $notification->token;

                return true;
            },
        );

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $data['email'],
            'token' => $resetToken,
            'password' => 'Raven-PG-New-Password-42!',
            'password_confirmation' => 'Raven-PG-New-Password-42!',
        ])->assertOk();

        $this->withToken($accessToken)->getJson('/api/v1/user/profile')->assertUnauthorized();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $data['email']]);
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
            } catch (\Throwable) {
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

        $this->assertSame(201, $registration->status(), 'Email registration response: '.$registration->getContent());
        $registration->assertJsonPath('success', true);
        [$userId, $firstToken] = $this->rememberSession($registration->json());

        // Simulate the next HTTP request running with a fresh PDO connection,
        // as it does when Render routes a request after the registration call.
        DB::purge('pgsql');
        $user = User::on('pgsql')->findOrFail($userId);
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $userId,
            'tokenable_type' => User::class,
        ]);
        $this->withToken($firstToken)
            ->getJson('/api/v1/user/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $userId);

        DB::purge('pgsql');
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
        $this->assertSame(200, $response->status(), 'Guest registration response: '.$response->getContent());

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
        $this->assertSame(201, $first->status(), 'Device registration response: '.$first->getContent());
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
