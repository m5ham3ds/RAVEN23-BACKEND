<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRecentMedia extends Model
{
    protected $fillable = [
        'user_id',
        'media_type',
        'media_id',
        'metadata',
        'elapsed',
        'remaining',
        'updated_at_utc',
        'deleted_at_utc',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'media_id' => 'integer',
            'metadata' => 'array',
            'elapsed' => 'integer',
            'remaining' => 'integer',
            'updated_at_utc' => 'integer',
            'deleted_at_utc' => 'integer',
            'revision' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
