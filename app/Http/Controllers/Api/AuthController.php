<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Google\Client as GoogleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private function session(User $user): JsonResponse
    {
        $token = $user->createToken('raven23-android')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $user->fresh()->toRavenArray(),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'username' => Str::lower(trim((string) $request->input('username'))),
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:40'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'username' => ['required', 'string', 'min:5', 'max:30', 'regex:/^[A-Za-z0-9_]+$/', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'profile_id' => ['sometimes', 'integer', 'min:0', 'max:143'],
        ]);

        // User creation and Sanctum token issuance must commit or roll back
        // together. A token-table failure must never leave an orphaned user
        // that then blocks the next registration attempt by unique email.
        return DB::transaction(function () use ($data): JsonResponse {
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

            return $this->session($user);
        });
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', Str::lower(trim($data['email'])))
            ->where('is_guest', false)
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The supplied credentials are invalid.',
            ], 401);
        }

        return $this->session($user);
    }

    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'min:20'],
        ]);

        $clientId = (string) config('services.google.client_id');
        if ($clientId === '') {
            return response()->json([
                'success' => false,
                'message' => 'Google authentication is not configured on the backend.',
            ], 503);
        }

        $client = new GoogleClient(['client_id' => $clientId]);
        $claims = $client->verifyIdToken($data['id_token']);
        if (!$claims || empty($claims['sub']) || empty($claims['email']) || ($claims['email_verified'] ?? false) !== true) {
            return response()->json([
                'success' => false,
                'message' => 'Google identity token is invalid or its email is not verified.',
            ], 401);
        }

        $email = Str::lower(trim($claims['email']));
        $user = User::where('email', $email)->first();

        if (!$user) {
            $base = Str::of($email)->before('@')->lower()->replaceMatches('/[^a-z0-9_]/', '_')->substr(0, 22)->toString();
            if (strlen($base) < 5) {
                $base = 'raven_' . Str::lower(Str::random(8));
            }
            $username = $base;
            while (User::where('username', $username)->exists()) {
                $username = substr($base, 0, 21) . '_' . Str::lower(Str::random(4));
            }

            $user = User::create([
                'name' => (string) ($claims['name'] ?? $base),
                'email' => $email,
                'username' => $username,
                'password' => Str::random(64),
                'provider' => 'google',
                'google_id' => (string) $claims['sub'],
                'is_verified' => true,
                'is_account_verified' => false,
                'is_guest' => false,
            ]);
        } else {
            if ($user->google_id && !hash_equals((string) $user->google_id, (string) $claims['sub'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'This email is linked to a different Google identity.',
                ], 409);
            }
            $user->forceFill([
                'google_id' => (string) $claims['sub'],
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        return $this->session($user);
    }

    public function guest(): JsonResponse
    {
        $suffix = Str::lower(Str::random(16));
        $user = User::create([
            'name' => 'Guest',
            'email' => "guest-{$suffix}@guest.raven23.invalid",
            'username' => "guest_{$suffix}",
            'password' => Str::random(64),
            'provider' => 'guest',
            'is_guest' => true,
        ]);

        return $this->session($user);
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
        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();

        return response()->json(['success' => true, 'message' => 'Password updated. Please sign in again.']);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->is_guest) {
            $user->delete();
            return response()->json(['success' => true]);
        }
        $data = $request->validate([
            'acknowledge_wallet_credit' => ['sometimes', 'boolean'],
        ]);
        $user->delete();

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
