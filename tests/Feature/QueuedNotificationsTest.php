<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\PasswordResetNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
}
