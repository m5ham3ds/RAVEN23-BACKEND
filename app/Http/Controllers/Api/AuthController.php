<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DeletePrivateObjectJob;
use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Support\GoogleIdTokenVerifier;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private readonly GoogleIdTokenVerifier $googleVerifier) {}

    private function markStage(Request $request, string $stage): void
    {
        $request->attributes->set('raven23_auth_stage', $stage);
    }

    private function deviceMetadata(Request $request): array
    {
        return $request->validate([
            'device_id' => ['sometimes', 'nullable', 'uuid'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'platform' => ['sometimes', 'nullable', 'in:android,ios,tv'],
            'replace_device_id' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    /**
     * Ensure normalized account data exists before issuing a session or
     * serving account-data endpoints. insertOrIgnore/upsert make repeated
     * sign-ins safe under concurrent requests.
     */
    private function ensureAccountData(User $user, array $device = []): void
    {
        $connectionName = (string) ($user->getConnectionName() ?: config('database.default', 'pgsql'));
        $connection = DB::connection($connectionName);
        $now = now();

        $connection->transaction(function () use ($connection, $user, $device, $now): void {
            $connection->table('user_profiles')->insertOrIgnore([
                'user_id' => $user->getKey(),
                'profile_id' => (int) ($user->profile_id ?? 0),
                'photo_url' => $user->photo_url,
                'created_at' => $user->created_at ?? $now,
                'updated_at' => $user->updated_at ?? $now,
            ]);

            $connection->table('user_preferences')->insertOrIgnore([
                'user_id' => $user->getKey(),
                'locale' => null,
                'timezone' => 'UTC',
                'revision' => 0,
                'created_at' => $user->created_at ?? $now,
                'updated_at' => $user->updated_at ?? $now,
            ]);

            $deviceId = $device['device_id'] ?? null;
            if (is_string($deviceId) && $deviceId !== '') {
                $connection->table('user_devices')->upsert(
                    [[
                        'user_id' => $user->getKey(),
                        'device_id' => $deviceId,
                        'device_name' => $device['device_name'] ?? null,
                        'platform' => $device['platform'] ?? null,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]],
                    ['user_id', 'device_id'],
                    ['device_name', 'platform', 'last_seen_at', 'updated_at'],
                );
            }
        });
    }

    private function authMailReady(): bool
    {
        if (! (bool) config('services.raven23_auth.mail_enabled', false)) {
            return false;
        }

        $actionUrl = trim((string) config('services.raven23_auth.action_url', ''));
        $parts = $actionUrl !== '' ? parse_url($actionUrl) : false;
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'raven23'], true)) {
            return false;
        }

        if (app()->environment('production') && in_array((string) config('mail.default'), ['log', 'array'], true)) {
            return false;
        }

        return true;
    }

    private function issueEmailVerification(User $user, ?Request $request = null): bool
    {
        if (! $this->authMailReady() || $user->is_guest || $user->is_verified || $user->email_verified_at) {
            return false;
        }

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);
        $expiresInMinutes = max(1, (int) config('security.email_verification_expire_minutes', 60));

        DB::table('email_verification_tokens')->updateOrInsert(
            ['user_id' => $user->getKey()],
            [
                'token_hash' => $tokenHash,
                'expires_at' => now()->addMinutes($expiresInMinutes),
                'created_at' => now(),
            ],
        );

        try {
            $user->notify(new EmailVerificationNotification((int) $user->getKey(), $plainToken, $expiresInMinutes));
        } catch (Throwable $exception) {
            DB::table('email_verification_tokens')
                ->where('user_id', $user->getKey())
                ->where('token_hash', $tokenHash)
                ->delete();

            Log::warning('RAVEN23 email verification delivery failed', [
                'request_id' => $request?->attributes->get('raven23_request_id'),
                'exception_type' => get_class($exception),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Issue a Sanctum token and bind it to this installation when metadata is
     * supplied. Re-authentication on the same installation replaces its old
     * token instead of accumulating stale credentials.
     */
    private function session(User $user, Request $request, int $status = 200): JsonResponse
    {
        $this->markStage($request, 'session.validate_device_metadata');
        $device = $this->deviceMetadata($request);
        $deviceId = $device['device_id'] ?? null;
        $platform = $device['platform'] ?? 'client';

        // Registration persists the account in its own transaction. Recreate
        // the PDO connection before issuing Sanctum credentials so a failed or
        // stale transaction state from the previous write cannot leak into the
        // independent token write (notably with pooled PostgreSQL connections).
        $connectionName = (string) ($user->getConnectionName() ?: config('database.default', 'pgsql'));
        $connectionDriver = DB::connection($connectionName)->getDriverName();
        if ($connectionDriver === 'pgsql') {
            $this->markStage($request, 'session.reset_database_connection');
            // Recreate the connection after the account write before issuing a
            // bearer token. Do not log database/schema names or user identifiers.
            DB::purge($connectionName);
            $afterConnection = DB::connection($connectionName);
            $afterConnection->selectOne('select 1');
            $persistedUser = User::on($connectionName)->find($user->getKey());

            if ($persistedUser === null) {
                Log::critical('RAVEN23 account persistence could not be verified after reconnect', [
                    'request_id' => $request->attributes->get('raven23_request_id'),
                    'db_driver' => 'pgsql',
                    'stage' => 'session.reset_database_connection',
                ]);

                // Never issue a bearer token for an Eloquent instance whose
                // account row is not visible through a fresh PostgreSQL
                // connection.
                throw new \RuntimeException(
                    'The account could not be verified after refreshing the database connection.'
                );
            }

            $user = $persistedUser;
            $user->setConnection($connectionName);
        }

        // Persist normalized account data only after PostgreSQL has been
        // refreshed, matching the established Phase 04 connection lifecycle.
        $this->markStage($request, 'session.ensure_account_data');
        $this->ensureAccountData($user, $device);

        // Issue and persist the replacement session before revoking older
        // device credentials. Each statement commits independently, so a
        // failed token write cannot leave the account without its old session.
        $this->markStage($request, 'session.create_sanctum_token');
        $issued = $user->createToken('raven23-'.$platform);

        try {
            $this->markStage($request, 'session.persist_device_metadata');
            $issued->accessToken->forceFill([
                'device_id' => $deviceId,
                'device_name' => $device['device_name'] ?? null,
                'platform' => $device['platform'] ?? null,
            ])->save();

            if (is_string($deviceId) && $deviceId !== '') {
                $this->markStage($request, 'session.revoke_previous_token_for_device');
                $user->tokens()
                    ->where('device_id', $deviceId)
                    ->where('id', '<>', $issued->accessToken->getKey())
                    ->delete();
            }

            if (isset($device['replace_device_id'])) {
                $this->markStage($request, 'session.replace_selected_device_token');
                // The relationship is scoped to this account; a caller cannot
                // revoke another user's token by guessing its database ID.
                $user->tokens()
                    ->whereKey((int) $device['replace_device_id'])
                    ->where('id', '<>', $issued->accessToken->getKey())
                    ->delete();
            }
        } catch (Throwable $exception) {
            // Do not leave an unusable partially-created credential behind.
            try {
                $issued->accessToken->delete();
            } catch (Throwable) {
                // Preserve the original database failure for safe diagnostics.
            }
            throw $exception;
        }

        $this->markStage($request, 'session.serialize_user_response');

        return response()->json([
            'success' => true,
            'token' => $issued->plainTextToken,
            'user' => $user->toRavenArray(),
        ], $status);
    }

    /**
     * A guest token is a short-lived proof that the caller owns the guest
     * account being upgraded. Invalid/stale guest tokens are ignored so they
     * cannot prevent a normal email or Google sign-in.
     */
    private function guestAccount(?string $plainTextToken): ?User
    {
        if (! is_string($plainTextToken) || trim($plainTextToken) === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($plainTextToken);
        $user = $accessToken?->tokenable;

        return $user instanceof User && $user->is_guest ? $user : null;
    }

    private function discardGuest(?User $guest, ?User $destination = null): void
    {
        if (! $guest || ($destination && $guest->is($destination))) {
            return;
        }

        $guest->tokens()->delete();
        $guest->delete();
    }

    private function googleConflict(string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ], 409);
    }

    private function googleUsername(string $email): string
    {
        $base = Str::of($email)
            ->before('@')
            ->lower()
            ->replaceMatches('/[^a-z0-9_]/', '_')
            ->trim('_')
            ->substr(0, 22)
            ->toString();

        if (strlen($base) < 5) {
            $base = 'raven_'.Str::lower(Str::random(8));
        }

        $candidate = $base;
        while (User::where('username', $candidate)->exists()) {
            $candidate = substr($base, 0, 21).'_'.Str::lower(Str::random(4));
        }

        return $candidate;
    }

    public function register(Request $request): JsonResponse
    {
        $this->markStage($request, 'register.normalize_input');
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'username' => Str::lower(trim((string) $request->input('username'))),
        ]);

        $this->markStage($request, 'register.validate_input');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:40'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'username' => ['required', 'string', 'min:5', 'max:30', 'regex:/^[A-Za-z0-9_]+$/', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'profile_id' => ['sometimes', 'integer', 'min:0', 'max:143'],
            'photo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'guest_token' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        $this->markStage($request, 'register.resolve_guest_token');
        $guest = $this->guestAccount($data['guest_token'] ?? null);

        $this->markStage($request, 'register.persist_account_and_session');
        $payload = DB::transaction(function () use ($data, $guest, $request): array {
            if ($guest) {
                $this->markStage($request, 'register.upgrade_guest_user');
                $guest->tokens()->delete();
                $guest->forceFill([
                    'name' => trim($data['name']),
                    'email' => Str::lower(trim($data['email'])),
                    'username' => Str::lower(trim($data['username'])),
                    'password' => $data['password'],
                    'profile_id' => $data['profile_id'] ?? 0,
                    'photo_url' => $data['photo_url'] ?? $guest->photo_url,
                    'provider' => 'email',
                    'google_id' => null,
                    'email_verified_at' => null,
                    'is_verified' => false,
                    'is_account_verified' => false,
                    'is_guest' => false,
                ])->save();
                $user = $guest;
            } else {
                $this->markStage($request, 'register.insert_user');
                $user = User::create([
                    'name' => trim($data['name']),
                    'email' => Str::lower(trim($data['email'])),
                    'username' => Str::lower(trim($data['username'])),
                    'password' => $data['password'],
                    'profile_id' => $data['profile_id'] ?? 0,
                    'photo_url' => $data['photo_url'] ?? null,
                ]);
                $user->forceFill([
                    'provider' => 'email',
                    'google_id' => null,
                    'email_verified_at' => null,
                    'is_verified' => false,
                    'is_account_verified' => false,
                    'is_guest' => false,
                ])->save();
            }

            return ['user' => $user, 'response_status' => 201];
        });

        // Email delivery is deliberately outside the account transaction.
        $this->markStage($request, 'register.reload_user');
        /** @var User $user */
        $user = $payload['user'];
        $response = $this->session($user, $request, $payload['response_status']);
        $verificationSent = $this->issueEmailVerification($user, $request);
        $responseData = $response->getData(true);
        $responseData['email_verification_required'] = true;
        $responseData['verification_email_sent'] = $verificationSent;

        return response()->json($responseData, $response->getStatusCode());
    }

    public function login(Request $request): JsonResponse
    {
        $this->markStage($request, 'login.validate_input');
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
            'guest_token' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        $this->markStage($request, 'login.find_account');
        $normalizedEmail = Str::lower(trim($data['email']));
        $emailRow = User::query()
            ->where('email', $normalizedEmail)
            ->first();

        // Older production schemas may contain NULL for is_guest. Treat that
        // legacy value as a normal account; actual guest accounts are TRUE.
        $user = $emailRow && ! $emailRow->is_guest ? $emailRow : null;
        $passwordMatches = $user !== null && Hash::check($data['password'], $user->password);
        if (! $user || ! $passwordMatches) {
            // Keep the log independent of account existence and submitted PII.
            // The API response is identical for an unknown email and bad password.
            Log::warning('RAVEN23 login rejected', [
                'request_id' => $request->attributes->get('raven23_request_id'),
                'reason' => 'invalid_credentials',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The supplied credentials are invalid.',
            ], 401);
        }

        $this->markStage($request, 'login.consume_guest_account');
        $guest = $this->guestAccount($data['guest_token'] ?? null);
        if ($guest && ! $guest->is($user)) {
            DB::transaction(function () use ($guest): void {
                $guest->tokens()->delete();
                $guest->delete();
            });
        }

        $this->markStage($request, 'login.issue_session');

        return $this->session($user, $request);
    }

    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'min:20', 'max:8192'],
            'guest_token' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        if (! filled(config('services.google.client_id'))) {
            return response()->json([
                'success' => false,
                'message' => 'Google authentication is not configured on the backend.',
            ], 503);
        }

        $claims = $this->googleVerifier->verify($data['id_token']);
        if (
            ! $claims
            || ! is_string($claims['sub'] ?? null)
            || trim($claims['sub']) === ''
            || ! is_string($claims['email'] ?? null)
            || trim($claims['email']) === ''
            || ($claims['email_verified'] ?? false) !== true
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Google identity token is invalid or its email is not verified.',
            ], 401);
        }

        $googleId = trim($claims['sub']);
        $email = Str::lower(trim($claims['email']));
        $name = trim((string) ($claims['name'] ?? ''));

        $result = DB::transaction(function () use ($data, $googleId, $email, $name): array|JsonResponse {
            $user = User::query()
                ->where('google_id', $googleId)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                $user = User::query()
                    ->where('email', $email)
                    ->lockForUpdate()
                    ->first();
            }

            $guest = $this->guestAccount($data['guest_token'] ?? null);

            if ($user && $user->google_id && ! hash_equals((string) $user->google_id, $googleId)) {
                return $this->googleConflict(
                    'google_identity_conflict',
                    'This email is linked to a different Google identity.',
                );
            }

            if ($user && ! $user->is_guest && ! $user->google_id) {
                // Match the app's account-linking contract: Google cannot
                // silently take over an existing password account.
                return $this->googleConflict(
                    'email_password_account_exists',
                    'This email already has a password account. Sign in with your password first.',
                );
            }

            if ($user) {
                $emailOwner = User::query()
                    ->where('email', $email)
                    ->where('id', '<>', $user->id)
                    ->exists();

                if ($emailOwner) {
                    return $this->googleConflict(
                        'google_email_in_use',
                        'This Google email is already used by another account.',
                    );
                }

                $user->forceFill([
                    'name' => $name !== '' ? $name : $user->name,
                    'email' => $email,
                    'provider' => 'google',
                    'google_id' => $googleId,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'is_verified' => true,
                    'is_guest' => false,
                ])->save();
            } elseif ($guest) {
                $guest->tokens()->delete();
                $guest->forceFill([
                    'name' => $name !== '' ? $name : 'RAVEN23 User',
                    'email' => $email,
                    'username' => $this->googleUsername($email),
                    'password' => Str::random(64),
                    'provider' => 'google',
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                    'is_verified' => true,
                    'is_account_verified' => false,
                    'is_guest' => false,
                ])->save();
                $user = $guest->fresh();
            } else {
                $user = User::create([
                    'name' => $name !== '' ? $name : 'RAVEN23 User',
                    'email' => $email,
                    'username' => $this->googleUsername($email),
                    'password' => Str::random(64),
                ]);
                $user->forceFill([
                    'provider' => 'google',
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                    'is_verified' => true,
                    'is_account_verified' => false,
                    'is_guest' => false,
                ])->save();
            }

            $this->discardGuest($guest, $user);

            return ['user_id' => (int) $user->id];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $this->markStage($request, 'google.reload_user');
        $user = User::findOrFail($result['user_id']);

        // Keep token issuance outside the account/linking transaction so an
        // isolated session failure cannot poison the account transaction.
        return $this->session($user, $request);
    }

    public function guest(Request $request): JsonResponse
    {
        $this->markStage($request, 'guest.insert_user');
        $user = User::create([
            'name' => 'Guest',
            'email' => 'guest-'.Str::lower(Str::random(16)).'@guest.raven23.invalid',
            'username' => 'guest_'.Str::lower(Str::random(16)),
            'password' => Str::random(64),
        ]);
        $user->forceFill([
            'provider' => 'guest',
            'is_guest' => true,
        ])->save();

        $this->markStage($request, 'guest.issue_session');

        return $this->session($user, $request);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['success' => true, 'message' => 'Signed out.']);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'user' => $request->user()->toRavenArray(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($request->has('username')) {
            $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:40'],
            'username' => ['sometimes', 'string', 'min:5', 'max:30', 'regex:/^[A-Za-z0-9_]+$/', 'unique:users,username,'.$user->id],
            'profile_id' => ['sometimes', 'integer', 'min:0', 'max:143'],
            'photo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ]);
        $connectionName = (string) ($user->getConnectionName() ?: config('database.default', 'pgsql'));
        DB::connection($connectionName)->transaction(function () use ($user, $data, $connectionName): void {
            $user->update($data);

            // Mirror only the legacy avatar fields. Name/username and auth
            // identity remain on users to preserve existing Android clients.
            $profileFields = array_intersect_key($data, array_flip(['profile_id', 'photo_url']));
            if ($profileFields !== []) {
                DB::connection($connectionName)->table('user_profiles')->updateOrInsert(
                    ['user_id' => $user->getKey()],
                    [...$profileFields, 'updated_at' => now()],
                );
            }
        });

        return response()->json(['success' => true, 'user' => $user->fresh()->toRavenArray()]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureAccountData($user);
        $preferences = $user->preferences()->firstOrFail();

        return response()->json([
            'success' => true,
            'preferences' => [
                'locale' => $preferences->locale,
                'timezone' => $preferences->timezone,
                'revision' => (int) $preferences->revision,
                'updated_at' => optional($preferences->updated_at)->toISOString(),
            ],
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        // Requiring at least one field routes empty updates through Laravel's
        // standard API validation renderer, preserving the common 422 envelope.
        $data = $request->validate([
            'locale' => [
                'required_without:timezone',
                'string',
                'min:2',
                'max:16',
                'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/',
            ],
            'timezone' => ['required_without:locale', 'string', 'max:64', 'timezone'],
        ]);

        $user = $request->user();
        $this->ensureAccountData($user);
        $preferences = DB::transaction(function () use ($user, $data) {
            $preferences = $user->preferences()->lockForUpdate()->firstOrFail();
            $preferences->fill($data);
            if ($preferences->isDirty(['locale', 'timezone'])) {
                $preferences->revision = (int) $preferences->revision + 1;
            }
            $preferences->save();

            return $preferences->fresh();
        });

        return response()->json([
            'success' => true,
            'preferences' => [
                'locale' => $preferences->locale,
                'timezone' => $preferences->timezone,
                'revision' => (int) $preferences->revision,
                'updated_at' => optional($preferences->updated_at)->toISOString(),
            ],
        ]);
    }

    /**
     * Export the authenticated account's server-held data only. Credentials,
     * token hashes, bearer tokens and recovery/verification secrets are never
     * part of this response.
     */
    public function exportUserData(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $user->getKey();
        $this->ensureAccountData($user);
        $profile = $user->profile()->firstOrFail();
        $preferences = $user->preferences()->firstOrFail();
        $media = Schema::hasTable('user_media')
            ? $user->media()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'purpose', 'original_name', 'mime_type', 'size_bytes', 'checksum_sha256', 'created_at'])
                ->map(function ($item) use ($userId): array {
                    $expiresAt = now()->addMinutes((int) config('filesystems.private_media_url_ttl_minutes', 5));

                    return [
                        'id' => (string) $item->getKey(),
                        'purpose' => $item->purpose,
                        'original_name' => $item->original_name,
                        'mime_type' => $item->mime_type,
                        'size_bytes' => (int) $item->size_bytes,
                        'checksum_sha256' => $item->checksum_sha256,
                        'created_at' => optional($item->created_at)->toISOString(),
                        'download_url' => route('api.v1.user.media.show', ['media' => $item->getKey()]),
                        'temporary_download_url' => URL::temporarySignedRoute(
                            'api.v1.user.media.temporary',
                            $expiresAt,
                            ['owner' => $userId, 'media' => $item->getKey()],
                        ),
                        'download_url_expires_at' => $expiresAt->toISOString(),
                    ];
                })
                ->values()
            : collect();
        $devices = $user->devices()
            ->orderBy('first_seen_at')
            ->get(['device_id', 'device_name', 'platform', 'first_seen_at', 'last_seen_at'])
            ->map(static fn ($device): array => [
                'device_id' => $device->device_id,
                'device_name' => $device->device_name,
                'platform' => $device->platform,
                'first_seen_at' => optional($device->first_seen_at)->toISOString(),
                'last_seen_at' => optional($device->last_seen_at)->toISOString(),
            ])
            ->values();

        $bookmarkRows = DB::table('user_bookmarks')
            ->where('user_id', $userId)
            ->orderBy('media_type')
            ->orderBy('media_id')
            ->get(['media_type', 'media_id', 'metadata', 'created_at']);
        $bookmarks = ['movies' => [], 'tvShows' => []];
        foreach ($bookmarkRows as $row) {
            $metadata = json_decode((string) $row->metadata, true);
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['id'] = (int) $row->media_id;
            $metadata['date_added'] = $metadata['date_added'] ?? (string) $row->created_at;
            if ($row->media_type === 'movie') {
                $bookmarks['movies'][] = $metadata;
            } elseif ($row->media_type === 'tv') {
                $bookmarks['tvShows'][] = $metadata;
            }
        }

        $recentRows = DB::table('user_recent_media')
            ->where('user_id', $userId)
            ->orderBy('media_type')
            ->orderBy('media_id')
            ->get(['media_type', 'media_id', 'metadata', 'elapsed', 'remaining', 'updated_at_utc', 'deleted_at_utc', 'revision']);
        $recentlyWatched = ['movies' => [], 'episodes' => []];
        foreach ($recentRows as $row) {
            $metadata = json_decode((string) $row->metadata, true);
            $metadata = is_array($metadata) ? $metadata : [];
            $record = [
                ...$metadata,
                'id' => (int) $row->media_id,
                ($row->media_type === 'movie' ? 'movie_id' : 'episode_id') => (int) $row->media_id,
                'elapsed' => (int) $row->elapsed,
                'remaining' => (int) $row->remaining,
                'updated_at_utc' => (int) $row->updated_at_utc,
                'deleted_at_utc' => $row->deleted_at_utc === null ? null : (int) $row->deleted_at_utc,
                'sync_revision' => (int) $row->revision,
            ];
            if ($row->media_type === 'movie') {
                $recentlyWatched['movies'][] = $record;
            } elseif ($row->media_type === 'episode') {
                $recentlyWatched['episodes'][] = $record;
            }
        }

        $playlistRows = DB::table('user_playlists')
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'name', 'description', 'version', 'created_at']);

        $playlistIds = $playlistRows->pluck('id')->all();
        $playlistItemRows = $playlistIds === []
            ? collect()
            : DB::table('user_playlist_items')
                ->whereIn('playlist_id', $playlistIds)
                ->orderBy('playlist_id')
                ->orderBy('position')
                ->get([
                    'playlist_id', 'media_type', 'media_id', 'position', 'title',
                    'poster_path', 'backdrop_path', 'vote_average', 'release_date',
                    'genre_ids', 'added_at',
                ]);

        $itemsByPlaylist = [];
        foreach ($playlistItemRows as $item) {
            $genreIds = json_decode((string) $item->genre_ids, true);
            $itemsByPlaylist[$item->playlist_id][] = [
                'playlist_id' => (string) $item->playlist_id,
                'media_type' => (string) $item->media_type,
                'media_id' => (int) $item->media_id,
                'position' => (int) $item->position,
                'title' => (string) $item->title,
                'poster_path' => $item->poster_path,
                'backdrop_path' => $item->backdrop_path,
                'vote_average' => $item->vote_average === null ? null : (float) $item->vote_average,
                'release_date' => $item->release_date,
                'genre_ids' => is_array($genreIds) ? $genreIds : [],
                'added_at' => $item->added_at,
            ];
        }

        $playlists = [];
        foreach ($playlistRows as $playlist) {
            $playlists[] = [
                'id' => (string) $playlist->id,
                'name' => (string) $playlist->name,
                'description' => $playlist->description,
                'version' => (int) $playlist->version,
                'created_at' => (string) $playlist->created_at,
                'items' => $itemsByPlaylist[$playlist->id] ?? [],
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'format_version' => 3,
                'exported_at' => now()->utc()->toISOString(),
                'account' => [
                    'id' => $userId,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'provider' => $user->provider ?? 'email',
                    'is_guest' => (bool) $user->is_guest,
                    'is_verified' => (bool) $user->is_verified,
                    'is_account_verified' => (bool) $user->is_account_verified,
                    'email_verified_at' => optional($user->email_verified_at)->toISOString(),
                    'created_at' => optional($user->created_at)->toISOString(),
                ],
                'profile' => [
                    'profile_id' => (int) $profile->profile_id,
                    'photo_url' => $profile->photo_url,
                ],
                'preferences' => [
                    'locale' => $preferences->locale,
                    'timezone' => $preferences->timezone,
                    'revision' => (int) $preferences->revision,
                    'updated_at' => optional($preferences->updated_at)->toISOString(),
                ],
                'devices' => $devices,
                'media' => $media,
                'bookmarks' => $bookmarks,
                'recently_watched' => $recentlyWatched,
                'playlists' => $playlists,
            ],
        ]);
    }
    public function changeEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email,'.$user->id],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['success' => false, 'message' => 'Current password is incorrect.'], 422);
        }

        $newEmail = Str::lower(trim($data['email']));
        $oldEmail = Str::lower((string) $user->email);
        if (! hash_equals($oldEmail, $newEmail)) {
            DB::transaction(function () use ($user, $newEmail, $oldEmail): void {
                $user->forceFill([
                    'email' => $newEmail,
                    'email_verified_at' => null,
                    'is_verified' => false,
                ])->save();

                DB::table('email_verification_tokens')->where('user_id', $user->getKey())->delete();
                DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                    ->where('email', $oldEmail)
                    ->delete();
            });
        }

        $user = $user->fresh();
        $verificationSent = $this->issueEmailVerification($user, $request);

        return response()->json([
            'success' => true,
            'user' => $user->toRavenArray(),
            'email_verification_required' => ! (bool) $user->is_verified,
            'verification_email_sent' => $verificationSent,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['success' => false, 'message' => 'Current password is incorrect.'], 422);
        }

        DB::transaction(function () use ($user, $data): void {
            $user->update(['password' => $data['password']]);
            $user->tokens()->delete();
        });

        return response()->json(['success' => true, 'message' => 'Password updated. Please sign in again.']);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'acknowledge_wallet_credit' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($user): void {
            // Encrypted cleanup jobs retain only server-generated object keys.
            // afterCommit prevents deletion before the account transaction commits.
            if (Schema::hasTable('user_media')) {
                foreach ($user->media()->orderBy('id')->pluck('object_key') as $objectKey) {
                    DeletePrivateObjectJob::dispatch((string) $objectKey);
                }
            }

            // Tokens are deliberately cleaned before removing the account row:
            // neither Sanctum nor temporary auth-token tables have a user FK.
            $user->tokens()->delete();
            DB::table('email_verification_tokens')->where('user_id', $user->getKey())->delete();
            DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                ->where('email', $user->email)
                ->delete();
            $user->delete();
        });

        return response()->json(['success' => true, 'message' => 'Account deleted.']);
    }

    public function sendEmailVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_guest) {
            return response()->json(['success' => false, 'message' => 'A guest account cannot verify an email address.'], 422);
        }

        if ($user->is_verified || $user->email_verified_at) {
            return response()->json(['success' => true, 'verified' => true, 'message' => 'This email address is already verified.']);
        }

        if (! $this->authMailReady()) {
            return response()->json(['success' => false, 'message' => 'Email verification is temporarily unavailable.'], 503);
        }

        if (! $this->issueEmailVerification($user, $request)) {
            return response()->json(['success' => false, 'message' => 'Email verification could not be sent. Please try again later.'], 503);
        }

        return response()->json(['success' => true, 'verified' => false, 'message' => 'A verification link has been sent if delivery is available.'], 202);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'token' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        $userId = (int) $data['user_id'];
        $tokenHash = hash('sha256', $data['token']);
        $verified = DB::transaction(function () use ($userId, $tokenHash): bool {
            $verification = DB::table('email_verification_tokens')
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (
                ! $verification
                || Carbon::parse($verification->expires_at)->isPast()
                || ! hash_equals((string) $verification->token_hash, $tokenHash)
            ) {
                return false;
            }

            $user = User::query()->lockForUpdate()->find($userId);
            if (! $user || $user->is_guest) {
                return false;
            }

            $user->forceFill(['email_verified_at' => now(), 'is_verified' => true])->save();
            DB::table('email_verification_tokens')
                ->where('user_id', $userId)
                ->where('token_hash', $tokenHash)
                ->delete();

            return true;
        });

        if (! $verified) {
            return response()->json(['success' => false, 'message' => 'The email verification token is invalid or expired.'], 422);
        }

        $user = User::findOrFail($userId);

        return response()->json(['success' => true, 'verified' => true, 'user' => $user->toRavenArray()]);
    }

    public function devices(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->getKey();

        $devices = $user->tokens()
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'device_id', 'device_name', 'platform', 'created_at', 'last_used_at', 'expires_at'])
            ->map(static fn (PersonalAccessToken $token): array => [
                'id' => (int) $token->getKey(),
                'device_id' => $token->device_id,
                'device_name' => $token->device_name,
                'platform' => $token->platform,
                'created_at' => optional($token->created_at)->toISOString(),
                'last_used_at' => optional($token->last_used_at)->toISOString(),
                'expires_at' => optional($token->expires_at)->toISOString(),
                'current' => $currentTokenId !== null && (int) $currentTokenId === (int) $token->getKey(),
            ])
            ->values();

        return response()->json(['success' => true, 'devices' => $devices]);
    }

    public function revokeDevice(Request $request, int $tokenId): JsonResponse
    {
        if ($request->user()->tokens()->whereKey($tokenId)->delete() === 0) {
            return response()->json(['success' => false, 'message' => 'The requested device session was not found.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Device session revoked.']);
    }

    public function revokeAllDevices(Request $request): JsonResponse
    {
        $revoked = $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'revoked_count' => $revoked,
            'message' => 'All device sessions have been revoked.',
        ]);
    }

    public function usernameAvailable(Request $request): JsonResponse
    {
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'username' => ['required', 'string', 'min:5', 'max:30', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        return response()->json([
            'success' => true,
            'available' => ! User::where('username', Str::lower($data['username']))->exists(),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);

        if (! $this->authMailReady()) {
            return response()->json(['success' => false, 'message' => 'Password recovery is temporarily unavailable.'], 503);
        }

        $email = Str::lower(trim($data['email']));
        $eligible = User::query()
            ->where('email', $email)
            ->where(static function ($query): void {
                $query->whereNull('is_guest')->orWhere('is_guest', false);
            })
            ->exists();

        if ($eligible) {
            try {
                Password::sendResetLink(['email' => $email]);
            } catch (Throwable $exception) {
                DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                    ->where('email', $email)
                    ->delete();

                Log::warning('RAVEN23 password reset delivery failed', [
                    'request_id' => $request->attributes->get('raven23_request_id'),
                    'exception_type' => get_class($exception),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'If the account exists, a password reset link has been sent.',
        ], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'token' => ['required', 'string', 'min:32', 'max:512'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);

        $status = Password::reset([
            'email' => Str::lower(trim($data['email'])),
            'token' => $data['token'],
            'password' => $data['password'],
        ], function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
                $user->tokens()->delete();
            });
        });

        if ($status === PasswordBroker::PASSWORD_RESET) {
            return response()->json(['success' => true, 'message' => 'Password updated. Please sign in again.']);
        }

        return response()->json(['success' => false, 'message' => 'The password reset token is invalid or expired.'], 422);
    }
}
