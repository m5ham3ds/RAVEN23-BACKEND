<?php

namespace Tests\Feature;

use App\Contracts\Notifications\NotificationOutboxTransport;
use App\Jobs\DeliverNotificationOutboxJob;
use App\Models\User;
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
