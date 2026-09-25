<?php
declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

final class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage())
            ->subject('بازیابی رمز عبور | Haman Planner')
            ->greeting('سلام '.($notifiable->name ?? ''))
            ->line('درخواست بازیابی رمز عبور حساب شما در Haman Planner ثبت شد.')
            ->action('تعیین رمز عبور جدید', $this->resetUrl($notifiable))
            ->line("این لینک تا {$minutes} دقیقه معتبر است.")
            ->line('اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید؛ رمز فعلی شما تغییری نمی‌کند.')
            ->salutation('Haman Planner');
    }
}
