<?php
declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/** One localized lifecycle email; the text comes from lang/{fa,en}/emails.php. */
final class LifecycleMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @param array<string,mixed> $params */
    public function __construct(public readonly string $type, public readonly array $params, public readonly ?string $unsubscribeUrl = null) {}

    private function line(string $key): string
    {
        $params = array_filter($this->params, fn ($v) => is_scalar($v));
        return (string) __('emails.'.$this->type.'.'.$key, $params);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->line('subject'));
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->unsubscribeUrl ? ['List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>'] : []);
    }

    public function content(): Content
    {
        $params = array_filter($this->params, fn ($v) => is_scalar($v));
        $lines = __('emails.'.$this->type.'.lines', $params);
        $cta = trans()->has('emails.'.$this->type.'.cta') ? $this->line('cta') : null;
        return new Content(view: 'emails.lifecycle', with: [
            'subjectLine' => $this->line('subject'),
            'greeting' => (string) __('emails.greeting', ['name' => (string) ($this->params['name'] ?? '')]),
            'lines' => is_array($lines) ? $lines : [(string) $lines],
            'extraLines' => array_values(array_filter((array) ($this->params['lines'] ?? []), 'is_string')),
            'cta' => $cta,
            'url' => is_string($this->params['url'] ?? null) ? $this->params['url'] : self::defaultUrl($this->type),
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'rtl' => app()->getLocale() === 'fa',
        ]);
    }

    public static function defaultUrl(string $type): string
    {
        return match ($type) {
            'trial_started', 'trial_ending', 'subscription_started', 'payment_failed', 'subscription_canceled', 'renewal_reminder' => route('billing.index'),
            default => route('planner.app'),
        };
    }
}
