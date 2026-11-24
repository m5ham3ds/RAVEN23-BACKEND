<?php

namespace Tests\Feature;

use App\Contracts\Notifications\NotificationOutboxTransport;
use App\Contracts\Notifications\PushNotificationTransport;
use App\Jobs\DeliverNotificationOutboxJob;
use App\Models\User;
use App\Models\UserPushSubscription;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\PasswordResetNotification;
use App\Services\Notifications\NotificationOutboxService;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\FailingQueueJob;
use Tests\Fixtures\IdempotentNotificationOutboxTransportFake;
use Tests\Fixtures\PushNotificationTransportFake;
use Tests\TestCase;

class QueuedNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'cache.default' => 'array',
            'queue.default' => 'database',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'RAVEN23 Queue Test',
            'email' => 'queue-'.bin2hex(random_bytes(5)).'@example.test',
            'username' => 'queue_'.bin2hex(random_bytes(5)),
            'password' => 'Queue-Test-Password-42!',
        ]);
    }

    public function test_outbox_encrypts_tokens_and_deduplicates_the_same_business_event(): void
    {
        Queue::fake();
        $user = $this->createUser();
        $token = 'verification-outbox-token-never-store-in-plaintext';
        $service = app(NotificationOutboxService::class);

        $firstId = $service->queueEmailVerification($user, $token, 60);
        $secondId = $service->queueEmailVerification($user, $token, 60);

        $this->assertSame($firstId, $secondId);
        $this->assertDatabaseCount('notification_outbox', 1);
        $record = DB::table('notification_outbox')->where('id', $firstId)->first();

        $this->assertSame('email_verification', $record->notification_type);
        $this->assertSame('pending', $record->status);
        $this->assertSame(0, (int) $record->attempts);
        $this->assertSame(64, strlen($record->idempotency_key));
        $this->assertStringNotContainsString($token, $record->encrypted_payload);
        $this->assertNotNull($record->last_dispatched_at);
        Queue::assertPushedOn('mail', DeliverNotificationOutboxJob::class);
        $this->assertCount(1, Queue::pushed(DeliverNotificationOutboxJob::class));
    }

    public function test_password_reset_notification_is_persisted_in_the_encrypted_outbox(): void
    {
        Queue::fake();
        $user = $this->createUser();
        $token = 'password-reset-outbox-token-never-store-in-plaintext';

        $user->sendPasswordResetNotification($token);

        $record = DB::table('notification_outbox')->first();
        $this->assertNotNull($record);
        $this->assertSame('password_reset', $record->notification_type);
        $this->assertSame($user->id, (int) $record->user_id);
        $this->assertStringNotContainsString($token, $record->encrypted_payload);
        Queue::assertPushedOn('mail', DeliverNotificationOutboxJob::class);
    }

    public function test_verification_and_password_reset_templates_keep_the_existing_action_urls(): void
    {
        config(['services.raven23_auth.action_url' => 'https://links.raven23.test/auth/action']);
        $user = $this->createUser();

        $verification = (new EmailVerificationNotification(
            (int) $user->getKey(),
            'verification-render-token',
            60,
        ))->toMail($user);
        $verificationHtml = (string) $verification->render();

        $this->assertSame('Verify your RAVEN23 email address', $verification->subject);
        $this->assertStringContainsString('Verify email address', $verificationHtml);
        $this->assertStringContainsString('action=verify-email', $verificationHtml);
        $this->assertStringContainsString('verification-render-token', $verificationHtml);
        $this->assertStringContainsString('60 minutes', $verificationHtml);

        $passwordReset = (new PasswordResetNotification('password-reset-render-token'))->toMail($user);
        $passwordResetHtml = (string) $passwordReset->render();

        $this->assertSame('Reset your RAVEN23 password', $passwordReset->subject);
        $this->assertStringContainsString('Reset password', $passwordResetHtml);
        $this->assertStringContainsString('action=reset-password', $passwordResetHtml);
        $this->assertStringContainsString(rawurlencode($user->email), $passwordResetHtml);
        $this->assertStringContainsString('password-reset-render-token', $passwordResetHtml);
    }

    public function test_database_worker_delivers_an_outbox_notification_through_the_local_array_mailer(): void
    {
        config([
            'mail.default' => 'array',
            'services.raven23_auth.action_url' => 'https://links.raven23.test/auth/action',
        ]);

        $sentMessages = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sentMessages): void {
            $sentMessages[] = $event;
        });

        $user = $this->createUser();
        $token = 'worker-password-reset-token';
        $user->sendPasswordResetNotification($token);

        $record = DB::table('notification_outbox')->first();
        $this->assertNotNull($record);
        $this->assertStringNotContainsString($token, $record->encrypted_payload);
        $queuePayload = (string) DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString($token, $queuePayload);

        $exitCode = Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--queue' => 'mail',
            '--sleep' => 0,
            '--tries' => 1,
            '--timeout' => 10,
            '--quiet' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertCount(1, $sentMessages);

        $email = $sentMessages[0]->sent->getSymfonySentMessage()->getOriginalMessage();
        $this->assertSame('Reset your RAVEN23 password', $email->getSubject());
        $this->assertSame($user->email, $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('action=reset-password', (string) $email->getHtmlBody());
        $this->assertStringContainsString($token, (string) $email->getHtmlBody());
        $this->assertDatabaseHas('notification_outbox', ['id' => $record->id, 'status' => 'sent']);
        $this->assertNotNull(DB::table('notification_outbox')->where('id', $record->id)->value('sent_at'));
    }

    public function test_provider_replay_after_accept_then_worker_crash_reuses_the_same_idempotency_key(): void
    {
        $transport = new IdempotentNotificationOutboxTransportFake;
        $transport->crashAfterFirstAcceptance = true;
        $this->app->instance(NotificationOutboxTransport::class, $transport);

        $user = $this->createUser();
        $outboxId = app(NotificationOutboxService::class)->queuePasswordReset($user, 'crash-replay-token');
        $job = new DeliverNotificationOutboxJob($outboxId);

        try {
            $job->handle($transport);
            $this->fail('The synthetic transport should simulate a worker crash after acceptance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic crash after provider acceptance', $exception->getMessage());
        }

        $this->assertDatabaseHas('notification_outbox', ['id' => $outboxId, 'status' => 'pending']);
        DB::table('notification_outbox')->where('id', $outboxId)->update(['available_at' => now()]);
        $job->handle($transport);

        $this->assertCount(2, $transport->calls);
        $this->assertSame($transport->calls[0]['idempotency_key'], $transport->calls[1]['idempotency_key']);
        $this->assertCount(1, $transport->accepted);
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outboxId,
            'status' => 'sent',
            'attempts' => 2,
        ]);
    }

    public function test_pending_outbox_relay_requeues_rows_when_the_first_dispatch_was_lost(): void
    {
        Queue::fake();
        $user = $this->createUser();
        $outboxId = app(NotificationOutboxService::class)->queuePasswordReset($user, 'relay-recovery-token');

        DB::table('notification_outbox')->where('id', $outboxId)->update([
            'last_dispatched_at' => now()->subMinutes(6),
        ]);

        Artisan::call('notifications:outbox-dispatch', ['--limit' => 10]);

        $this->assertCount(2, Queue::pushed(DeliverNotificationOutboxJob::class));
        $this->assertDatabaseHas('notification_outbox', ['id' => $outboxId, 'status' => 'pending']);
    }

    public function test_pending_outbox_relay_recovers_expired_worker_claims_without_requeueing_active_claims(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $expiredId = app(NotificationOutboxService::class)->queuePasswordReset($user, 'expired-lease-token');
        $activeUser = User::query()->create([
            'name' => 'RAVEN23 Active Lease',
            'email' => 'active-'.bin2hex(random_bytes(5)).'@example.test',
            'username' => 'active_'.bin2hex(random_bytes(5)),
            'password' => 'Queue-Test-Password-42!',
        ]);
        $activeId = app(NotificationOutboxService::class)->queuePasswordReset($activeUser, 'active-lease-token');

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

    public function test_push_outbox_is_disabled_by_default_and_does_not_create_delivery_events(): void
    {
        Queue::fake();
        $user = $this->createUser();
        UserPushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'fcm',
            'token_hash' => hash_hmac('sha256', 'fcm:synthetic-fcm-token', (string) config('app.key')),
            'token' => 'synthetic-fcm-token-0123456789abcdef',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        $ids = app(NotificationOutboxService::class)->queuePush(
            $user,
            'media-ready:'.$user->getKey(),
            'Media ready',
            'Your upload has finished.',
        );

        $this->assertSame([], $ids);
        $this->assertDatabaseCount('notification_outbox', 0);
        Queue::assertNothingPushed();
    }

    public function test_push_outbox_encrypts_and_deduplicates_one_event_per_selected_subscription(): void
    {
        Queue::fake();
        config([
            'services.raven23_push.enabled' => true,
            'services.raven23_push.provider' => 'fcm',
        ]);
        $user = $this->createUser();
        $fcmToken = 'synthetic-fcm-token-0123456789abcdef';
        UserPushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'fcm',
            'token_hash' => hash_hmac('sha256', 'fcm:'.$fcmToken, (string) config('app.key')),
            'token' => $fcmToken,
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
        $oneSignalId = '11111111-1111-4111-8111-111111111111';
        UserPushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'onesignal',
            'token_hash' => hash_hmac('sha256', 'onesignal:'.$oneSignalId, (string) config('app.key')),
            'token' => $oneSignalId,
            'platform' => 'tv',
            'last_seen_at' => now(),
        ]);

        $service = app(NotificationOutboxService::class);
        $first = $service->queuePush($user, 'media-ready:'.$user->getKey(), 'Media ready', 'Your upload has finished.', ['media_id' => 'test-media-42']);
        $second = $service->queuePush($user, 'media-ready:'.$user->getKey(), 'Media ready', 'Your upload has finished.', ['media_id' => 'test-media-42']);

        $this->assertCount(1, $first);
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('notification_outbox', 1);
        $record = DB::table('notification_outbox')->where('id', $first[0])->first();
        $this->assertSame('push_delivery', $record->notification_type);
        $this->assertSame('pending', $record->status);
        $this->assertStringNotContainsString('Media ready', $record->encrypted_payload);
        $this->assertStringNotContainsString($fcmToken, $record->encrypted_payload);
        Queue::assertPushedOn('push', DeliverNotificationOutboxJob::class);
        $this->assertCount(1, Queue::pushed(DeliverNotificationOutboxJob::class));
    }

    public function test_push_outbox_uses_the_provider_transport_and_replays_with_the_same_idempotency_key(): void
    {
        Queue::fake();
        config([
            'services.raven23_push.enabled' => true,
            'services.raven23_push.provider' => 'fcm',
        ]);
        $user = $this->createUser();
        $token = 'synthetic-fcm-token-replay-0123456789abcdef';
        UserPushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'fcm',
            'token_hash' => hash_hmac('sha256', 'fcm:'.$token, (string) config('app.key')),
            'token' => $token,
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        $fake = new PushNotificationTransportFake;
        $fake->crashAfterFirstAcceptance = true;
        $this->app->instance(PushNotificationTransport::class, $fake);
        $transport = app(NotificationOutboxTransport::class);
        $ids = app(NotificationOutboxService::class)->queuePush(
            $user,
            'release-ready:'.$user->getKey(),
            'Release ready',
            'A new release is ready.',
            ['release_id' => '42'],
        );
        $this->assertCount(1, $ids);
        $outboxId = $ids[0];
        // Provider selection may change after enqueue; queued events retain their original target.
        config(['services.raven23_push.provider' => 'onesignal']);
        $job = new DeliverNotificationOutboxJob($outboxId, 'push');

        try {
            $job->handle($transport);
            $this->fail('The synthetic push transport should crash after provider acceptance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic push transport crash after acceptance', $exception->getMessage());
        }

        $this->assertDatabaseHas('notification_outbox', ['id' => $outboxId, 'status' => 'pending']);
        DB::table('notification_outbox')->where('id', $outboxId)->update(['available_at' => now()]);
        $job->handle($transport);

        $this->assertCount(2, $fake->calls);
        $this->assertSame($token, $fake->calls[0]['token']);
        $this->assertSame('fcm', $fake->calls[0]['provider']);
        $this->assertSame($fake->calls[0]['idempotency_key'], $fake->calls[1]['idempotency_key']);
        $this->assertCount(1, $fake->accepted);
        $this->assertSame('Release ready', $fake->calls[0]['message']['title']);
        $this->assertDatabaseHas('notification_outbox', ['id' => $outboxId, 'status' => 'sent', 'attempts' => 2]);
    }

    public function test_push_outbox_does_not_send_to_a_subscription_removed_before_delivery(): void
    {
        Queue::fake();
        config([
            'services.raven23_push.enabled' => true,
            'services.raven23_push.provider' => 'fcm',
        ]);
        $user = $this->createUser();
        $subscription = UserPushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'fcm',
            'token_hash' => hash_hmac('sha256', 'fcm:revoked-token', (string) config('app.key')),
            'token' => 'revoked-token-synthetic',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
        $fake = new PushNotificationTransportFake;
        $this->app->instance(PushNotificationTransport::class, $fake);
        $transport = app(NotificationOutboxTransport::class);
        $ids = app(NotificationOutboxService::class)->queuePush($user, 'security-alert:'.$user->getKey(), 'Security alert', 'Review your account activity.');
        $this->assertCount(1, $ids);

        $subscription->delete();
        (new DeliverNotificationOutboxJob($ids[0], 'push'))->handle($transport);

        $this->assertSame([], $fake->calls);
        $this->assertDatabaseHas('notification_outbox', ['id' => $ids[0], 'status' => 'sent']);
    }

    public function test_push_delivery_obeys_the_global_disable_switch_after_enqueue(): void
    {
        Queue::fake();
        config([
            'services.raven23_push.enabled' => true,
            'services.raven23_push.provider' => 'fcm',
        ]);
        $user = $this->createUser();
        $token = 'synthetic-fcm-disable-switch-0123456789abcdef';
        UserPushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'fcm',
            'token_hash' => hash_hmac('sha256', 'fcm:'.$token, (string) config('app.key')),
            'token' => $token,
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
        $fake = new PushNotificationTransportFake;
        $this->app->instance(PushNotificationTransport::class, $fake);
        $ids = app(NotificationOutboxService::class)->queuePush(
            $user,
            'disable-switch:'.$user->getKey(),
            'Security update',
            'A security update is available.',
        );
        $this->assertCount(1, $ids);

        config(['services.raven23_push.enabled' => false]);
        $transport = app(NotificationOutboxTransport::class);

        try {
            (new DeliverNotificationOutboxJob($ids[0], 'push'))->handle($transport);
            $this->fail('A queued push must not be delivered after the global switch is disabled.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Push delivery is disabled by configuration.', $exception->getMessage());
        }

        $this->assertSame([], $fake->calls);
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $ids[0],
            'status' => 'pending',
        ]);
    }

    public function test_outbox_status_reports_aggregate_health_without_exposing_private_fields(): void
    {
        Queue::fake();
        $user = $this->createUser();
        $service = app(NotificationOutboxService::class);
        $ids = [];
        for ($index = 0; $index < 6; $index++) {
            $ids[] = $service->queuePasswordReset($user, 'outbox-status-secret-token-'.$index);
        }

        $now = now();
        DB::table('notification_outbox')->where('id', $ids[0])->update([
            'created_at' => $now->copy()->subSeconds(90),
            'available_at' => $now->copy()->subSeconds(30),
        ]);
        DB::table('notification_outbox')->where('id', $ids[1])->update([
            'created_at' => $now->copy()->subSeconds(10),
            'available_at' => $now->copy()->addMinutes(5),
        ]);
        DB::table('notification_outbox')->where('id', $ids[2])->update([
            'created_at' => $now->copy()->subMinutes(2),
            'status' => 'processing',
            'claimed_at' => $now->copy()->subSeconds(120),
        ]);
        DB::table('notification_outbox')->where('id', $ids[3])->update([
            'created_at' => $now->copy()->subSeconds(20),
            'status' => 'processing',
            'claimed_at' => $now->copy()->subSeconds(5),
        ]);
        DB::table('notification_outbox')->where('id', $ids[4])->update([
            'status' => 'sent',
            'sent_at' => $now,
        ]);
        DB::table('notification_outbox')->where('id', $ids[5])->update([
            'status' => 'failed',
            'last_error_type' => RuntimeException::class,
        ]);

        $this->assertSame(0, Artisan::call('notifications:outbox-status', ['--json' => true]));
        $output = Artisan::output();
        $summary = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $summary['pending_due_count']);
        $this->assertSame(1, $summary['pending_scheduled_count']);
        $this->assertSame(2, $summary['processing_count']);
        $this->assertSame(1, $summary['expired_claim_count']);
        $this->assertSame(1, $summary['failed_count']);
        $this->assertSame(1, $summary['sent_count']);
        $this->assertGreaterThanOrEqual(90, $summary['oldest_due_pending_age_seconds']);
        $this->assertNotEmpty($summary['by_type_and_status']);
        $this->assertStringNotContainsString($user->email, $output);
        $this->assertStringNotContainsString('outbox-status-secret-token', $output);
        $this->assertStringNotContainsString('user_id', $output);
        $this->assertStringNotContainsString('encrypted_payload', $output);
        $this->assertStringNotContainsString('last_error_type', $output);
    }

    public function test_database_worker_records_unrelated_terminal_queue_failures_in_failed_jobs(): void
    {
        Queue::pushOn('mail', new FailingQueueJob);
        $this->assertDatabaseCount('jobs', 1);

        try {
            Artisan::call('queue:work', [
                'connection' => 'database',
                '--once' => true,
                '--queue' => 'mail',
                '--sleep' => 0,
                '--tries' => 1,
                '--timeout' => 10,
                '--quiet' => true,
            ]);
        } catch (\Throwable) {
            // The worker may rethrow the synthetic job exception after recording it.
        }

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $failedJob = DB::table('failed_jobs')->first();
        $this->assertStringContainsString('synthetic queue worker failure', $failedJob->exception);
        $payload = json_decode($failedJob->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(FailingQueueJob::class, $payload['data']['commandName']);
    }
}
