<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GoogleIdTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private readonly GoogleIdTokenVerifier $googleVerifier)
    {
    }

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
            $beforeConnection = DB::connection($connectionName);
            $beforeIdentity = $beforeConnection->selectOne("
                SELECT current_database() AS database_name,
                       current_schema() AS schema_name,
                       current_setting('search_path') AS search_path
            ");
            $beforeVisible = User::on($connectionName)->whereKey($user->getKey())->exists();
            Log::info('RAVEN23 session persistence probe', [
                'stage' => 'before_connection_reset',
                'user_id' => (int) $user->getKey(),
                'row_visible' => $beforeVisible,
                'transaction_level' => $beforeConnection->transactionLevel(),
                'database_name' => $beforeIdentity->database_name ?? null,
                'schema_name' => $beforeIdentity->schema_name ?? null,
                'search_path' => $beforeIdentity->search_path ?? null,
            ]);

            DB::purge($connectionName);
            $afterConnection = DB::connection($connectionName);
            $afterConnection->selectOne('select 1');
            $afterIdentity = $afterConnection->selectOne("
                SELECT current_database() AS database_name,
                       current_schema() AS schema_name,
                       current_setting('search_path') AS search_path
            ");
            $persistedUser = User::on($connectionName)->find($user->getKey());
            Log::info('RAVEN23 session persistence probe', [
                'stage' => 'after_connection_reset',
                'user_id' => (int) $user->getKey(),
                'row_visible' => $persistedUser !== null,
                'stored_is_guest' => $persistedUser?->is_guest,
                'stored_provider' => $persistedUser ? (string) $persistedUser->provider : null,
                'transaction_level' => $afterConnection->transactionLevel(),
                'database_name' => $afterIdentity->database_name ?? null,
                'schema_name' => $afterIdentity->schema_name ?? null,
                'search_path' => $afterIdentity->search_path ?? null,
            ]);
            if ($persistedUser !== null) {
                $user = $persistedUser;
            }
            $user->setConnection($connectionName);
        }

        // Issue and persist the replacement session before revoking older
        // device credentials. Each statement commits independently, so a
        // failed token write cannot leave the account without its old session.
        $this->markStage($request, 'session.create_sanctum_token');
        $issued = $user->createToken('raven23-' . $platform);

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
        if (!is_string($plainTextToken) || trim($plainTextToken) === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($plainTextToken);
        $user = $accessToken?->tokenable;

        return $user instanceof User && $user->is_guest ? $user : null;
    }

    private function discardGuest(?User $guest, ?User $destination = null): void
    {
        if (!$guest || ($destination && $guest->is($destination))) {
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
            $base = 'raven_' . Str::lower(Str::random(8));
        }

        $candidate = $base;
        while (User::where('username', $candidate)->exists()) {
            $candidate = substr($base, 0, 21) . '_' . Str::lower(Str::random(4));
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
            'guest_token' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        $this->markStage($request, 'register.resolve_guest_token');
        $guest = $this->guestAccount($data['guest_token'] ?? null);

        $this->markStage($request, 'register.persist_account_and_session');
        $payload = DB::transaction(function () use ($data, $guest, $request): array {
            if ($guest) {
                $this->markStage($request, 'register.upgrade_guest_user');
                // Upgrade the existing guest row so its identity and linked
                // data remain stable. Every old anonymous token is revoked.
                $guest->tokens()->delete();
                $guest->forceFill([
                    'name' => trim($data['name']),
                    'email' => Str::lower(trim($data['email'])),
                    'username' => Str::lower(trim($data['username'])),
                    'password' => $data['password'],
                    'profile_id' => $data['profile_id'] ?? 0,
                    'provider' => 'email',
                    'google_id' => null,
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
                    'provider' => 'email',
                    'is_verified' => false,
                    'is_account_verified' => false,
                    'is_guest' => false,
                ]);
            }

            return [
                'user' => $user,
                'response_status' => 201,
            ];
        });

        // Session issuance is deliberately outside the account transaction.
        // If the token/device write fails, the user remains recoverable and a
        // retry does not get misreported as a failed user-row transaction.
        $this->markStage($request, 'register.reload_user');
        $user = $payload['user'];
        return $this->session($user, $request, $payload['response_status']);
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
        $user = $emailRow && !$emailRow->is_guest ? $emailRow : null;
        $passwordMatches = $user !== null && Hash::check($data['password'], $user->password);
        if (!$user || !$passwordMatches) {
            // Safe diagnostic only: never log the email, submitted password,
            // stored hash, SQL or bearer token. PostgreSQL identity functions
            // are deliberately skipped for SQLite feature tests.
            $loginConnection = DB::connection((string) config('database.default'));
            $loginDatabaseName = null;
            $loginSchemaName = null;
            if ($loginConnection->getDriverName() === 'pgsql') {
                $loginIdentity = $loginConnection->selectOne(
                    'SELECT current_database() AS database_name, current_schema() AS schema_name'
                );
                $loginDatabaseName = $loginIdentity->database_name ?? null;
                $loginSchemaName = $loginIdentity->schema_name ?? null;
            }

            Log::warning('RAVEN23 login credentials rejected', [
                'request_id' => (string) Str::uuid(),
                'account_found' => $user !== null,
                'email_row_found' => $emailRow !== null,
                'stored_is_guest' => $emailRow ? $emailRow->is_guest : null,
                'password_hash_matches' => $passwordMatches,
                'stored_provider' => $emailRow ? (string) $emailRow->provider : null,
                'database_driver' => $loginConnection->getDriverName(),
                'database_name' => $loginDatabaseName,
                'schema_name' => $loginSchemaName,
                'transaction_level' => $loginConnection->transactionLevel(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The supplied credentials are invalid.',
            ], 401);
        }

        $this->markStage($request, 'login.consume_guest_account');
        $guest = $this->guestAccount($data['guest_token'] ?? null);
        if ($guest && !$guest->is($user)) {
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

        if (!filled(config('services.google.client_id'))) {
            return response()->json([
                'success' => false,
                'message' => 'Google authentication is not configured on the backend.',
            ], 503);
        }

        $claims = $this->googleVerifier->verify($data['id_token']);
        if (
            !$claims
            || !is_string($claims['sub'] ?? null)
            || trim($claims['sub']) === ''
            || !is_string($claims['email'] ?? null)
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

        $result = DB::transaction(function () use ($data, $request, $googleId, $email, $name): array|JsonResponse {
            $user = User::query()
                ->where('google_id', $googleId)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                $user = User::query()
                    ->where('email', $email)
                    ->lockForUpdate()
                    ->first();
            }

            $guest = $this->guestAccount($data['guest_token'] ?? null);

            if ($user && $user->google_id && !hash_equals((string) $user->google_id, $googleId)) {
                return $this->googleConflict(
                    'google_identity_conflict',
                    'This email is linked to a different Google identity.',
                );
            }

            if ($user && !$user->is_guest && !$user->google_id) {
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
                    'provider' => 'google',
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                    'is_verified' => true,
                    'is_account_verified' => false,
                    'is_guest' => false,
                ]);
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
            'email' => 'guest-' . Str::lower(Str::random(16)) . '@guest.raven23.invalid',
            'username' => 'guest_' . Str::lower(Str::random(16)),
            'password' => Str::random(64),
            'provider' => 'guest',
            'is_guest' => true,
        ]);

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
            'username' => ['sometimes', 'string', 'min:5', 'max:30', 'regex:/^[A-Za-z0-9_]+$/', 'unique:users,username,' . $user->id],
            'profile_id' => ['sometimes', 'integer', 'min:0', 'max:143'],
            'photo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ]);
        $user->update($data);

        return response()->json(['success' => true, 'user' => $user->fresh()->toRavenArray()]);
    }

    public function changeEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email,' . $user->id],
        ]);
        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['success' => false, 'message' => 'Current password is incorrect.'], 422);
        }
        $user->forceFill([
            'email' => Str::lower(trim($data['email'])),
            'email_verified_at' => null,
            'is_verified' => false,
        ])->save();

        return response()->json(['success' => true, 'user' => $user->fresh()->toRavenArray()]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);
        if (!Hash::check($data['current_password'], $user->password)) {
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
            // Sanctum tokens are polymorphic and have no user foreign key;
            // explicitly revoke them before removing the account row.
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json(['success' => true, 'message' => 'Account deleted.']);
    }

    public function usernameAvailable(Request $request): JsonResponse
    {
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'username' => ['required', 'string', 'min:5', 'max:30', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        return response()->json([
            'success' => true,
            'available' => !User::where('username', Str::lower($data['username']))->exists(),
        ]);
    }

    public function forgotPassword(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Password reset email delivery is not configured yet.',
        ], 503);
    }

    public function resetPassword(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Password reset is unavailable until email delivery is configured.',
        ], 503);
    }
}
