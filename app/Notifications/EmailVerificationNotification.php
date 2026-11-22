<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationNotification extends Notification
{
    public function __construct(
        public readonly int $userId,
        public readonly string $token,
        public readonly int $expiresInMinutes,
    ) {}

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

        return (new MailMessage())
            ->subject('Verify your RAVEN23 email address')
            ->greeting('Welcome to RAVEN23')
            ->line('Verify your email address to complete your account setup.')
            ->action('Verify email address', $url)
            ->line('This one-time link expires in '.$this->expiresInMinutes.' minutes.');
    }
}
