<?php

namespace Tests\Integration;

use App\Jobs\DeliverNotificationOutboxJob;
use App\Models\User;
use App\Models\UserPushSubscription;
use App\Services\Notifications\NotificationOutboxService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostgresNotificationOutboxTest extends TestCase
{
    private array $createdUsers = [];

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
            'database.connections.pgsql.sslmode' => 'disable',
            'cache.default' => 'array',
        ]);
        DB::purge('pgsql');
        Queue::fake();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        DB::connection()->selectOne('select 1');
    }

    private function createAccount(string $label): User
    {
        $suffix = Str::lower(Str::random(12));
        $user = User::query()->create([
            'name' => 'RAVEN23 Outbox '.$label,
            'email' => 'outbox-'.$suffix.'@example.test',
            'username' => 'outbox_'.$suffix,
            'password' => 'Raven-Outbox-Postgres-42!',
        ]);

        $this->createdUsers[] = (int) $user->getKey();

        return $user;
    }

    public function test_postgresql_outbox_uses_unique_encrypted_idempotent_events(): void
    {
        $user = $this->createAccount('dedupe');
        $token = 'postgres-outbox-never-store-token';
        $service = app(NotificationOutboxService::class);

        $firstId = $service->queuePasswordReset($user, $token);
        $secondId = $service->queuePasswordReset($user, $token);

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, DB::table('notification_outbox')->where('user_id', $user->getKey())->count());
        $record = DB::table('notification_outbox')->where('id', $firstId)->first();

        $this->assertSame('pending', $record->status);
        $this->assertSame(64, strlen($record->idempotency_key));
        $this->assertStringNotContainsString($token, $record->encrypted_payload);
        Queue::assertPushedOn('mail', DeliverNotificationOutboxJob::class);
        $this->assertCount(1, Queue::pushed(DeliverNotificationOutboxJob::class));
    }

    public function test_postgresql_outbox_dispatch_is_deferred_until_the_outer_transaction_commits(): void
    {
        $user = $this->createAccount('rollback');
        $token = 'postgres-outbox-rollback-token';

        DB::beginTransaction();
        try {
            $outboxId = app(NotificationOutboxService::class)->queuePasswordReset($user, $token);
            $this->assertDatabaseHas('notification_outbox', ['id' => $outboxId]);
            DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('notification_outbox', [
            'user_id' => $user->getKey(),
            'notification_type' => 'password_reset',
        ]);
    }

    public function test_postgresql_outbox_relay_recovers_expired_worker_claims_but_preserves_active_claims(): void
    {
        $expiredUser = $this->createAccount('expired-claim');
        $activeUser = $this->createAccount('active-claim');

        $service = app(NotificationOutboxService::class);
        $expiredId = $service->queuePasswordReset($expiredUser, 'postgres-expired-claim-token');
        $activeId = $service->queuePasswordReset($activeUser, 'postgres-active-claim-token');

        DB::table('notification_outbox')->where('id', $expiredId)->update([
            'status' => 'processing',
            'claimed_at' => now()->subMinutes(2),
            'last_dispatched_at' => now(),
        ]);
        DB::table('notification_outbox')->where('id', $activeId)->update([
            'status' => 'processing',
            'claimed_at' => now(),
            'last_dispatched_at' => now(),
        ]);

        Artisan::call('notifications:outbox-dispatch', ['--limit' => 10]);

        $this->assertCount(3, Queue::pushed(DeliverNotificationOutboxJob::class));
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $expiredId,
            'status' => 'pending',
            'claimed_at' => null,
        ]);
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $activeId,
            'status' => 'processing',
        ]);
    }

    public function test_postgresql_push_outbox_is_encrypted_idempotent_and_queued_after_commit(): void
    {
        config([
            'services.raven23_push.enabled' => true,
            'services.raven23_push.provider' => 'fcm',
        ]);
        $user = $this->createAccount('push-outbox');
        $token = 'postgres-fcm-synthetic-token-0123456789abcdef';
        UserPushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'fcm',
            'token_hash' => hash_hmac('sha256', 'fcm:'.$token, (string) config('app.key')),
            'token' => $token,
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        DB::beginTransaction();
        try {
            $ids = app(NotificationOutboxService::class)->queuePush($user, 'postgres-media-ready:'.$user->getKey(), 'Media ready', 'Your media is available.');
            $this->assertCount(1, $ids);
            $this->assertDatabaseHas('notification_outbox', ['id' => $ids[0], 'notification_type' => 'push_delivery']);
            DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        Queue::assertNothingPushed();
        $ids = app(NotificationOutboxService::class)->queuePush($user, 'postgres-media-ready:'.$user->getKey(), 'Media ready', 'Your media is available.');

        $this->assertCount(1, $ids);
        $record = DB::table('notification_outbox')->where('id', $ids[0])->first();
        $this->assertSame('push_delivery', $record->notification_type);
        $this->assertSame('pending', $record->status);
        $this->assertStringNotContainsString('Media ready', $record->encrypted_payload);
        $this->assertStringNotContainsString($token, $record->encrypted_payload);
        $this->assertDatabaseCount('notification_outbox', 1);
        Queue::assertPushedOn('push', DeliverNotificationOutboxJob::class);
    }

    public function test_postgresql_outbox_status_reports_only_aggregate_counts(): void
    {
        $before = Artisan::call('notifications:outbox-status', ['--json' => true]);
        $this->assertSame(0, $before);
        $beforeSummary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $user = $this->createAccount('status-summary');
        $token = 'postgres-status-secret-token';
        app(NotificationOutboxService::class)->queuePasswordReset($user, $token);

        $this->assertSame(0, Artisan::call('notifications:outbox-status', ['--json' => true]));
        $output = Artisan::output();
        $summary = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            $beforeSummary['pending_due_count'] + 1,
            $summary['pending_due_count'],
        );
        $this->assertStringNotContainsString($user->email, $output);
        $this->assertStringNotContainsString($token, $output);
        $this->assertStringNotContainsString('user_id', $output);
        $this->assertStringNotContainsString('encrypted_payload', $output);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdUsers) as $userId) {
            try {
                DB::table('users')->where('id', $userId)->delete();
            } catch (\Throwable) {
                // Preserve the original test failure; user deletion cascades outbox records.
            }
        }

        parent::tearDown();
    }
}
