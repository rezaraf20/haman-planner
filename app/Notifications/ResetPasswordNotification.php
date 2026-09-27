<?php
declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/** Password reset email in the account's own language. */
final class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $locale = method_exists($notifiable, 'preferredLocale') ? $notifiable->preferredLocale() : app()->getLocale();
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage())
            ->subject(__('auth.mail_reset_subject', [], $locale))
            ->greeting(__('auth.mail_greeting', ['name' => $notifiable->name ?? ''], $locale))
            ->line(__('auth.mail_reset_line', [], $locale))
            ->action(__('auth.mail_reset_action', [], $locale), $this->resetUrl($notifiable))
            ->line(__('auth.mail_reset_expire', ['minutes' => $minutes], $locale))
            ->line(__('auth.mail_reset_ignore', [], $locale))
            ->salutation('Haman Planner');
    }
}
