<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Invoice extends Model
{
    protected $fillable = ['payment_id', 'user_id', 'number', 'amount', 'currency', 'billing_name', 'billing_email', 'lines', 'issued_at'];

    protected $casts = ['lines' => 'array', 'issued_at' => 'datetime', 'amount' => 'integer'];

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
