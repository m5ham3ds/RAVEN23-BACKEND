<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification
{
    public function __construct(public readonly string $token) {}

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

        return (new MailMessage())
            ->subject('Reset your RAVEN23 password')
            ->greeting('Password reset requested')
            ->line('Use the one-time link below to choose a new password.')
            ->action('Reset password', $url)
            ->line('This link expires in '.$expires.' minutes. If you did not request it, no action is required.');
    }
}
