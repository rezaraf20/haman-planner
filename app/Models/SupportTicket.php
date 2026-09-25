<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SupportTicket extends Model
{
    public const STATUSES = ['open' => 'باز', 'answered' => 'پاسخ داده شد', 'closed' => 'بسته'];

    protected $fillable = ['user_id', 'subject', 'status', 'last_reply_at'];
    protected $casts = ['last_reply_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function messages(): HasMany { return $this->hasMany(SupportMessage::class)->orderBy('created_at')->orderBy('id'); }
    public function statusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }
}
