<?php

namespace App\Notifications;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    public readonly DateTimeImmutable $retryDeadlineAt;

    public function backoff(): array
    {
        return [10, 30, 90, 180];
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadlineAt;
    }

    public function __construct(
        public readonly int $userId,
        public readonly string $token,
        public readonly int $expiresInMinutes,
    ) {
        $this->retryDeadlineAt = now()->addMinutes(20)->toDateTimeImmutable();
        $this->onQueue('mail')->afterCommit();
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $base = (string) config('services.raven23_auth.action_url');
        $query = http_build_query([
            'action' => 'verify-email',
            'user_id' => $this->userId,
            'token' => $this->token,
        ], '', '&', PHP_QUERY_RFC3986);
        $url = $base.(str_contains($base, '?') ? '&' : '?').$query;

        return (new MailMessage)
            ->subject('Verify your RAVEN23 email address')
            ->greeting('Welcome to RAVEN23')
            ->line('Verify your email address to complete your account setup.')
            ->action('Verify email address', $url)
            ->line('This one-time link expires in '.$this->expiresInMinutes.' minutes.');
    }
}
