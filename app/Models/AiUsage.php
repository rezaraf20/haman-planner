<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One AI API call: tokens, outcome and timing. Never stores prompts or answers. */
final class AiUsage extends Model
{
    protected $table = 'ai_usage';
    public $timestamps = false;

    protected $fillable = ['ai_provider_id', 'provider', 'model', 'user_id', 'feature', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'success', 'latency_ms', 'error', 'request_id', 'created_at'];

    protected $casts = ['success' => 'boolean', 'created_at' => 'datetime'];

    public function aiProvider(): BelongsTo { return $this->belongsTo(AiProvider::class); }
}
