<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\GoogleIdTokenVerifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'services.google.client_id' => 'raven23-test-web-client.apps.googleusercontent.com',
        ]);
        DB::purge('sqlite');

        Schema::connection('sqlite')->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('username', 30)->unique();
            $table->unsignedInteger('profile_id')->default(0);
            $table->text('photo_url')->nullable();
            $table->string('provider', 24)->default('email');
            $table->string('google_id')->nullable()->unique();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_account_verified')->default(false);
            // Legacy production may already have this field without a default.
            $table->boolean('is_guest')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('personal_access_tokens', static function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('device_id', 64)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('platform', 16)->nullable();
            $table->timestamps();
            $table->index(['tokenable_type', 'tokenable_id', 'device_id']);
        });
    }

    private function registrationData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Raven Test User',
            'email' => 'raven-test@example.test',
            'username' => 'raven_test_user',
            'password' => 'Raven-Test-Password-42!',
        ], $overrides);
    }

    private function googleClaims(array $overrides = []): array
    {
        return array_merge([
            'sub' => 'google-subject-123456789',
            'email' => 'google-test@example.test',
            'email_verified' => true,
            'name' => 'Google Test User',
        ], $overrides);
    }

    private function useGoogleClaims(array $claims): void
    {
        $fake = new class($claims) extends GoogleIdTokenVerifier {
            public function __construct(private readonly array $claims)
            {
            }

            public function verify(string $idToken): ?array
            {
                return $this->claims;
            }
        };

        $this->app->instance(GoogleIdTokenVerifier::class, $fake);
    }

    public function test_registration_rate_limit_rejects_requests_after_five_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = $this->postJson('/api/v1/auth/register', $this->registrationData([
                'email' => "raven-rate-{$attempt}@example.test",
                'username' => "raven_rate_{$attempt}",
            ]));
            $response->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', $this->registrationData([
            'email' => 'raven-rate-limit@example.test',
            'username' => 'raven_rate_limit',
        ]))->assertStatus(429);
    }

    public function test_login_rate_limit_is_applied_to_an_hmac_identity_bucket(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'same-identity@example.test',
                'password' => 'incorrect-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'same-identity@example.test',
            'password' => 'incorrect-password',
        ])->assertStatus(429);

        // A different identity is still allowed under the per-IP threshold.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'different-identity@example.test',
            'password' => 'incorrect-password',
        ])->assertUnauthorized();
    }

    public function test_email_registration_persists_user_and_password_login_returns_same_account(): void
    {
        $registration = $this->postJson('/api/v1/auth/register', $this->registrationData())
            ->assertCreated()
            ->assertJsonPath('success', true);

        $userId = $registration->json('user.id');
        $registrationToken = $registration->json('token');
        $this->assertIsInt($userId);
        $this->assertNotEmpty($registrationToken);
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'raven-test@example.test',
            'provider' => 'email',
            'is_guest' => false,
        ]);
        $this->assertTrue(Hash::check(
            'Raven-Test-Password-42!',
            User::findOrFail($userId)->password,
        ));

        $this->withToken($registrationToken)
            ->getJson('/api/v1/user/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $userId);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => ' RAVEN-TEST@example.test ',
            'password' => 'Raven-Test-Password-42!',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame($userId, $login->json('user.id'));
        $this->postJson('/api/v1/auth/login', [
            'email' => 'raven-test@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_registration_upgrades_the_owned_guest_row_and_revokes_guest_token(): void
    {
        $guest = $this->postJson('/api/v1/auth/guest')->assertOk();
        $guestId = $guest->json('user.id');
        $guestToken = $guest->json('token');

        $registration = $this->postJson('/api/v1/auth/register', $this->registrationData([
            'guest_token' => $guestToken,
        ]))->assertCreated();

        $this->assertSame($guestId, $registration->json('user.id'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'id' => $guestId,
            'email' => 'raven-test@example.test',
            'provider' => 'email',
            'is_guest' => false,
        ]);

        $this->withToken($guestToken)->getJson('/api/v1/user/profile')->assertUnauthorized();
        $this->withToken($registration->json('token'))
            ->getJson('/api/v1/user/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $guestId);
    }

    public function test_login_consumes_a_guest_account_after_credentials_are_verified(): void
    {
        $registered = $this->postJson('/api/v1/auth/register', $this->registrationData())->assertCreated();
        $guest = $this->postJson('/api/v1/auth/guest')->assertOk();
        $guestId = $guest->json('user.id');
        $guestToken = $guest->json('token');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'raven-test@example.test',
            'password' => 'Raven-Test-Password-42!',
            'guest_token' => $guestToken,
        ])->assertOk();

        $this->assertSame($registered->json('user.id'), $login->json('user.id'));
        $this->assertDatabaseMissing('users', ['id' => $guestId]);
        $this->withToken($guestToken)->getJson('/api/v1/user/profile')->assertUnauthorized();
    }

    public function test_google_creates_a_verified_account_and_reuses_it_on_next_login(): void
    {
        $this->useGoogleClaims($this->googleClaims());
        $first = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'synthetic-google-id-token-123456789',
        ])->assertOk()->assertJsonPath('success', true);

        $userId = $first->json('user.id');
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'google-test@example.test',
            'google_id' => 'google-subject-123456789',
            'provider' => 'google',
            'is_verified' => true,
        ]);

        $second = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'synthetic-google-id-token-123456789',
        ])->assertOk();

        $this->assertSame($userId, $second->json('user.id'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_does_not_silently_take_over_an_existing_password_account(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationData())->assertCreated();
        $this->useGoogleClaims($this->googleClaims([
            'email' => 'raven-test@example.test',
        ]));

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'synthetic-google-id-token-123456789',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'email_password_account_exists');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_unverified_google_email_is_rejected_without_creating_an_account(): void
    {
        $this->useGoogleClaims($this->googleClaims(['email_verified' => false]));

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'synthetic-google-id-token-123456789',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_guest_can_be_upgraded_to_google_without_creating_a_second_user(): void
    {
        $guest = $this->postJson('/api/v1/auth/guest')->assertOk();
        $guestId = $guest->json('user.id');
        $guestToken = $guest->json('token');
        $this->useGoogleClaims($this->googleClaims());

        $google = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'synthetic-google-id-token-123456789',
            'guest_token' => $guestToken,
        ])->assertOk();

        $this->assertSame($guestId, $google->json('user.id'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'id' => $guestId,
            'provider' => 'google',
            'google_id' => 'google-subject-123456789',
            'is_guest' => false,
        ]);
        $this->withToken($guestToken)->getJson('/api/v1/user/profile')->assertUnauthorized();
    }

    public function test_same_device_sign_in_replaces_the_previous_install_token(): void
    {
        $deviceId = '11111111-1111-4111-8111-111111111111';
        $first = $this->postJson('/api/v1/auth/register', $this->registrationData([
            'device_id' => $deviceId,
            'device_name' => 'Raven test device',
            'platform' => 'android',
        ]))->assertCreated();

        $second = $this->postJson('/api/v1/auth/login', [
            'email' => 'raven-test@example.test',
            'password' => 'Raven-Test-Password-42!',
            'device_id' => $deviceId,
            'device_name' => 'Raven test device',
            'platform' => 'android',
        ])->assertOk();

        $this->withToken($first->json('token'))
            ->getJson('/api/v1/user/profile')
            ->assertUnauthorized();
        $this->withToken($second->json('token'))
            ->getJson('/api/v1/user/profile')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', [
            'device_id' => $deviceId,
            'device_name' => 'Raven test device',
            'platform' => 'android',
        ]);
    }

    public function test_logout_invalidates_the_exact_token_that_was_signed_out(): void
    {
        $registration = $this->postJson('/api/v1/auth/register', $this->registrationData())->assertCreated();
        $token = $registration->json('token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        // The API smoke workflow makes a new HTTP request against the live
        // PHP worker and checks 401. In this in-process Laravel test harness,
        // assert the exact Sanctum lookup used by bearer authentication.
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_account_deletion_revokes_every_token_before_removing_the_user(): void
    {
        $first = $this->postJson('/api/v1/auth/register', $this->registrationData())->assertCreated();
        $second = $this->postJson('/api/v1/auth/login', [
            'email' => 'raven-test@example.test',
            'password' => 'Raven-Test-Password-42!',
        ])->assertOk();

        $firstToken = $first->json('token');
        $secondToken = $second->json('token');
        $userId = $first->json('user.id');

        $this->withToken($firstToken)->deleteJson('/api/v1/user/account')->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $userId]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(PersonalAccessToken::findToken($firstToken));
        $this->assertNull(PersonalAccessToken::findToken($secondToken));
    }
}
