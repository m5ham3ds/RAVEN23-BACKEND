<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpireDueEntitlementsTest extends TestCase
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

    private function account(string $label): User
    {
        $suffix = bin2hex(random_bytes(5));

        return User::create([
            'name' => 'RAVEN23 Expiry '.$label,
            'email' => 'expiry-'.$suffix.'@example.test',
            'username' => 'expiry_'.$suffix,
            'password' => 'Raven-Expiry-Test-Password-42!',
        ]);
    }

    private function entitlement(
        User $user,
        string $code,
        \DateTimeInterface $startsAt,
        ?\DateTimeInterface $expiresAt,
        ?\DateTimeInterface $revokedAt = null,
    ): UserEntitlement {
        return UserEntitlement::create([
            'user_id' => $user->getKey(),
            'code' => $code,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'revoked_at' => $revokedAt,
            'revision' => 1,
            'source' => 'internal',
            'source_reference' => 'expiry-test',
        ]);
    }

    public function test_default_run_is_a_read_only_preview(): void
    {
        $user = $this->account('preview');
        $started = now('UTC')->subDays(10);
        $expiredAt = now('UTC')->subDay();
        $this->entitlement($user, 'ad_free', $started, $expiredAt);

        $this->artisan('subscriptions:expire-due')
            ->expectsOutputToContain('dry_run=true candidates=1')
            ->assertExitCode(0);

        $this->assertDatabaseCount('subscription_events', 0);
        $this->assertDatabaseHas('user_entitlements', [
            'user_id' => $user->getKey(),
            'code' => 'ad_free',
            'revoked_at' => null,
        ]);
    }

    public function test_apply_records_expiry_event_once_and_skips_future_or_revoked_access(): void
    {
        $expiredUser = $this->account('expired');
        $futureUser = $this->account('future');
        $revokedUser = $this->account('revoked');
        $started = now('UTC')->subDays(10);
        $expiredAt = now('UTC')->subDay();

        $expired = $this->entitlement($expiredUser, 'ad_free', $started, $expiredAt);
        $future = $this->entitlement($futureUser, 'ad_free', $started, now('UTC')->addDay());
        $revoked = $this->entitlement($revokedUser, 'ad_free', $started, $expiredAt, now('UTC')->subHours(2));

        $this->artisan('subscriptions:expire-due', ['--apply' => true])
            ->expectsOutputToContain('dry_run=false applied=1')
            ->assertExitCode(0);

        $this->assertDatabaseCount('subscription_events', 1);
        $event = DB::table('subscription_events')->sole();
        $this->assertSame('expired', $event->event_type);
        $this->assertSame('internal', $event->source);
        $this->assertSame('applied', $event->projection_status);
        $this->assertSame(
            sprintf('entitlement-expiry:%d:1', $expired->getKey()),
            $event->idempotency_key,
        );

        $this->assertDatabaseHas('user_entitlements', [
            'id' => $expired->getKey(),
            'revoked_at' => $expiredAt->toDateTimeString(),
        ]);
        $this->assertDatabaseHas('user_entitlements', [
            'id' => $future->getKey(),
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('user_entitlements', [
            'id' => $revoked->getKey(),
            'revoked_at' => $revoked->revoked_at->toDateTimeString(),
        ]);

        $this->artisan('subscriptions:expire-due', ['--apply' => true])
            ->expectsOutputToContain('candidates=0')
            ->assertExitCode(0);

        $this->assertDatabaseCount('subscription_events', 1);
    }

    public function test_limit_is_bounded_and_invalid_values_are_rejected(): void
    {
        $this->artisan('subscriptions:expire-due', ['--limit' => 0])
            ->expectsOutputToContain('between 1 and 5000')
            ->assertExitCode(2);
    }
}
