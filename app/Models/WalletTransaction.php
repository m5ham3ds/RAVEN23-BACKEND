<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'wallet_id',
        'type',
        'source',
        'amount_cents',
        'balance_after_cents',
        'reference',
        'idempotency_key',
        'payload_hash',
        'period_starts_at',
        'period_ends_at',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(UserWallet::class, 'wallet_id');
    }
}
