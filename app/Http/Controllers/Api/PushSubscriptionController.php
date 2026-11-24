<?php

namespace App\Http\Controllers\Api;

use App\Models\UserPushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PushSubscriptionController
{
    public function registerFcm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string', 'min:20', 'max:4096', 'regex:/^\\S+$/'],
            'platform' => ['required', 'string', 'min:2', 'max:24'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        return $this->store($request, 'fcm', $data['fcm_token'], $data);
    }

    public function registerOneSignal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'uuid'],
            'platform' => ['required', 'string', 'min:2', 'max:24'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        return $this->store($request, 'onesignal', $data['subscription_id'], $data);
    }

    public function unregisterOneSignal(Request $request, string $subscriptionId): JsonResponse
    {
        abort_unless(
            preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $subscriptionId) === 1,
            404
        );

        $hash = $this->tokenHash('onesignal', $subscriptionId);

        UserPushSubscription::query()
            ->where('user_id', $request->user()->getKey())
            ->where('provider', 'onesignal')
            ->where('token_hash', $hash)
            ->delete();

        return response()->json(['success' => true]);
    }

    private function store(Request $request, string $provider, string $token, array $data): JsonResponse
    {
        $hash = $this->tokenHash($provider, $token);

        DB::transaction(function () use ($request, $provider, $token, $hash, $data): void {
            UserPushSubscription::query()->updateOrCreate(
                ['provider' => $provider, 'token_hash' => $hash],
                [
                    'user_id' => $request->user()->getKey(),
                    'token' => $token,
                    'platform' => $data['platform'],
                    'app_version' => $data['app_version'] ?? null,
                    'last_seen_at' => now(),
                ]
            );
        });

        return response()->json(['success' => true]);
    }

    private function tokenHash(string $provider, string $token): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Application encryption key is unavailable.');
        }

        return hash_hmac('sha256', $provider.':'.$token, $key);
    }
}
