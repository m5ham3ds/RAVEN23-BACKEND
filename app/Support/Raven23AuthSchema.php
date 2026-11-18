<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class Raven23AuthSchema
{
    public static function ensure(): void
    {
        self::ensureUsers();
        self::ensurePersonalAccessTokens();
    }

    private static function ensureUsers(): void
    {
        if (!Schema::hasTable('users')) {
            throw new RuntimeException('The Laravel users table is missing.');
        }

        $existing = Schema::getColumnListing('users');

        Schema::table('users', static function (Blueprint $table) use ($existing): void {
            if (!in_array('username', $existing, true)) {
                $table->string('username', 30)->nullable();
            }
            if (!in_array('profile_id', $existing, true)) {
                $table->unsignedInteger('profile_id')->default(0);
            }
            if (!in_array('photo_url', $existing, true)) {
                $table->text('photo_url')->nullable();
            }
            if (!in_array('provider', $existing, true)) {
                $table->string('provider', 24)->default('email');
            }
            if (!in_array('google_id', $existing, true)) {
                $table->string('google_id')->nullable();
            }
            if (!in_array('is_verified', $existing, true)) {
                $table->boolean('is_verified')->default(false);
            }
            if (!in_array('is_account_verified', $existing, true)) {
                $table->boolean('is_account_verified')->default(false);
            }
            if (!in_array('is_guest', $existing, true)) {
                $table->boolean('is_guest')->default(false);
            }
        });

        // Preserve valid existing usernames and backfill only missing,
        // malformed or duplicate values. No account rows are removed.
        $users = DB::table('users')
            ->select(['id', 'email', 'username'])
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $original = (string) ($user->username ?? '');
            $candidate = strtolower(trim($original));
            $valid = preg_match('/\A[a-z0-9_]{5,30}\z/', $candidate) === 1;
            $duplicate = $valid && DB::table('users')
                ->where('username', $candidate)
                ->where('id', '<>', $user->id)
                ->exists();

            if (!$valid || $duplicate) {
                $candidate = self::generatedUsername($user);
            }

            if ($original !== $candidate) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['username' => $candidate]);
            }
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('RAVEN23 account schema requires PostgreSQL.');
        }

        DB::statement('ALTER TABLE users ALTER COLUMN username SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_username_unique ON users (username)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_google_id_unique ON users (google_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS users_is_guest_index ON users (is_guest)');
    }

    private static function generatedUsername(object $user): string
    {
        $email = (string) ($user->email ?? '');
        $localPart = explode('@', $email, 2)[0];
        $base = strtolower((string) preg_replace('/[^a-z0-9_]+/i', '_', $localPart));
        $base = trim($base, '_');

        if (strlen($base) < 5) {
            $base = 'user_' . (string) $user->id;
        }

        $idSuffix = base_convert((string) $user->id, 10, 36);
        $attempt = 0;

        do {
            $tail = '_' . $idSuffix . ($attempt > 0 ? '_' . $attempt : '');
            $candidate = substr($base, 0, max(1, 30 - strlen($tail))) . $tail;
            $attempt++;
        } while (DB::table('users')
            ->where('username', $candidate)
            ->where('id', '<>', $user->id)
            ->exists());

        return $candidate;
    }

    private static function ensurePersonalAccessTokens(): void
    {
        if (!Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', static function (Blueprint $table): void {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });

            return;
        }

        $required = [
            'id', 'tokenable_type', 'tokenable_id', 'name', 'token',
            'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at',
        ];
        $actual = Schema::getColumnListing('personal_access_tokens');
        $missing = array_values(array_diff($required, $actual));

        if ($missing !== []) {
            throw new RuntimeException(
                'The existing personal_access_tokens table is incomplete: ' . implode(', ', $missing)
            );
        }
    }
}
