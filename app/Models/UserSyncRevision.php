<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSyncRevision extends Model
{
    protected $fillable = ['user_id', 'domain', 'revision'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'revision' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
