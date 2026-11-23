<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\PasswordResetNotification;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\FailingQueueJob;
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

    private function assertMailJobIsEncryptedAndBounded(SendQueuedNotifications $job): void
    {
        $this->assertSame('mail', $job->queue);
        $this->assertTrue($job->afterCommit);
        $this->assertTrue($job->shouldBeEncrypted);
        $this->assertSame(5, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([10, 30, 90, 180], $job->backoff());
        $deadline = $job->retryUntil();
        $this->assertInstanceOf(\DateTimeInterface::class, $deadline);
        $this->assertGreaterThan(now()->getTimestamp(), $deadline->getTimestamp());
        $this->assertSame($deadline, $job->retryUntil());
    }

    public function test_email_verification_notification_is_encrypted_and_queued_after_commit(): void
    {
        Queue::fake();
        $user = $this->createUser();

        $user->notify(new EmailVerificationNotification(
            (int) $user->getKey(),
            'verification-token-never-store-in-plaintext',
            60,
        ));

        Queue::assertPushedOn('mail', SendQueuedNotifications::class);
        $jobs = Queue::pushed(SendQueuedNotifications::class);
        $this->assertCount(1, $jobs);
        $job = $jobs->first();
        $this->assertInstanceOf(EmailVerificationNotification::class, $job->notification);
        $this->assertSame($user->getKey(), $job->notifiables->first()->getKey());
        $this->assertSame('verification-token-never-store-in-plaintext', $job->notification->token);
        $this->assertMailJobIsEncryptedAndBounded($job);
    }

    public function test_password_reset_notification_is_encrypted_and_queued_after_commit(): void
    {
        Queue::fake();
        $user = $this->createUser();

        $user->sendPasswordResetNotification('password-reset-token-never-store-in-plaintext');

        Queue::assertPushedOn('mail', SendQueuedNotifications::class);
        $jobs = Queue::pushed(SendQueuedNotifications::class);
        $this->assertCount(1, $jobs);
        $job = $jobs->first();
        $this->assertInstanceOf(PasswordResetNotification::class, $job->notification);
        $this->assertSame('password-reset-token-never-store-in-plaintext', $job->notification->token);
        $this->assertMailJobIsEncryptedAndBounded($job);
    }

    public function test_verification_and_password_reset_templates_render_runtime_action_urls(): void
    {
        config([
            'services.raven23_auth.action_url' => 'https://links.raven23.test/auth/action',
        ]);

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

    public function test_database_worker_sends_queued_notification_through_local_array_mailer(): void
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

        $this->assertDatabaseCount('jobs', 1);
        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString($token, $payload);

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
    }

    public function test_database_worker_records_terminal_failures_in_failed_jobs(): void
    {
        Queue::pushOn('mail', new FailingQueueJob());

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
