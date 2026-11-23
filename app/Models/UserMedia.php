<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMedia extends Model
{
    use HasUuids;

    protected $table = 'user_media';

    protected $fillable = [
        'purpose',
        'object_key',
        'original_name',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
