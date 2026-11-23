<?php

namespace App\Notifications;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    public function backoff(): array
    {
        return [10, 30, 90, 180];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    public function __construct(public readonly string $token)
    {
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
            'action' => 'reset-password',
            'email' => $notifiable->getEmailForPasswordReset(),
            'token' => $this->token,
        ], '', '&', PHP_QUERY_RFC3986);
        $url = $base.(str_contains($base, '?') ? '&' : '?').$query;
        $expires = max(1, (int) config('auth.passwords.users.expire', 60));

        return (new MailMessage)
            ->subject('Reset your RAVEN23 password')
            ->greeting('Password reset requested')
            ->line('Use the one-time link below to choose a new password.')
            ->action('Reset password', $url)
            ->line('This link expires in '.$expires.' minutes. If you did not request it, no action is required.');
    }
}
