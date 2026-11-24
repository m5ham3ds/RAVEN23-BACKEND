<?php

namespace App\Models;

use App\Services\Notifications\NotificationOutboxService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'profile_id',
        'photo_url',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'profile_id' => 'integer',
            'is_verified' => 'boolean',
            'is_account_verified' => 'boolean',
            'is_guest' => 'boolean',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(UserMedia::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        app(NotificationOutboxService::class)->queuePasswordReset($this, (string) $token);
    }

    public function toRavenArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'joined_at' => optional($this->created_at)->toISOString(),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
            'profile_id' => (int) ($this->profile_id ?? 0),
            'photo_url' => $this->photo_url,
            'provider' => $this->provider ?? 'email',
            'is_verified' => (bool) $this->is_verified,
            'is_account_verified' => (bool) $this->is_account_verified,
        ];
    }
}
