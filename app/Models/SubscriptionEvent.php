<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'event_type',
        'entitlement_code',
        'source',
        'source_reference',
        'idempotency_key',
        'reason_code',
        'occurred_at',
        'starts_at',
        'expires_at',
        'event_hash',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
