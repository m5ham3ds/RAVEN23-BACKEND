<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;

class PreferenceSyncController extends Controller
{
    /**
     * Account-shared preferences are intentionally limited to locale/timezone.
     * Player, subtitle, theme, source-order and other device settings stay local.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since_revision' => ['sometimes', 'integer', 'min:0'],
            'operations' => ['sometimes', 'array', 'max:1'],
            'operations.*' => ['required', 'array'],
            'operations.*.operation_id' => ['required', 'uuid'],
            'operations.*.action' => ['required', 'in:preferences.update'],
            'operations.*.base_revision' => ['required', 'integer', 'min:0'],
            'operations.*.locale' => [
                'sometimes',
                'nullable',
                'string',
                'min:2',
                'max:16',
                'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/',
            ],
            'operations.*.timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
        ]);

        $operations = $validated['operations'] ?? [];
        foreach ($operations as $index => $operation) {
            if (! array_key_exists('locale', $operation)
                && ! array_key_exists('timezone', $operation)) {
                return response()->json([
                    'success' => false,
                    'errors' => [
                        "operations.$index" => ['At least one shared preference must be supplied.'],
                    ],
                ], 422);
            }
        }

        $userId = (int) $request->user()->getAuthIdentifier();
        $sinceRevision = (int) ($validated['since_revision'] ?? 0);
        $serverTime = (int) floor(microtime(true) * 1000);

        $result = DB::transaction(function () use (
            $operations,
            $userId,
            $sinceRevision,
        ): array {
            $connection = DB::connection();
            $now = now();

            $connection->table('user_preferences')->insertOrIgnore([
                'user_id' => $userId,
                'locale' => null,
                'timezone' => 'UTC',
                'revision' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $preferences = $connection->table('user_preferences')
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($preferences === null) {
                throw new \RuntimeException('The preference row could not be initialized.');
            }

            $revision = (int) $preferences->revision;
            if ($sinceRevision > $revision) {
                return [
                    'revision_ahead' => true,
                    'server_revision' => $revision,
                    'preferences' => null,
                    'results' => [],
                ];
            }

            $operation = $operations[0] ?? null;
            $results = [];
            $hadConflict = false;

            if ($operation !== null) {
                $normalized = [
                    'operation_id' => (string) $operation['operation_id'],
                    'action' => 'preferences.update',
                    'base_revision' => (int) $operation['base_revision'],
                ];
                if (array_key_exists('locale', $operation)) {
                    $normalized['locale'] = $operation['locale'];
                }
                if (array_key_exists('timezone', $operation)) {
                    $normalized['timezone'] = (string) $operation['timezone'];
                }
                $canonical = $normalized;
                ksort($canonical);
                $fingerprint = hash('sha256', json_encode(
                    $canonical,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));

                $stored = $connection->table('user_preference_operations')
                    ->where('user_id', $userId)
                    ->where('operation_id', $normalized['operation_id'])
                    ->lockForUpdate()
                    ->first();

                if ($stored !== null && ! hash_equals((string) $stored->payload_hash, $fingerprint)) {
                    return [
                        'operation_id_reused' => true,
                        'operation_id' => $normalized['operation_id'],
                    ];
                }

                if ($stored !== null) {
                    $saved = json_decode((string) $stored->result, true);
                    $results[] = is_array($saved) ? $saved : [
                        'operation_id' => $normalized['operation_id'],
                        'status' => 'applied',
                        'revision' => (int) $stored->revision,
                    ];
                } elseif ($normalized['base_revision'] > $revision) {
                    return [
                        'revision_ahead' => true,
                        'server_revision' => $revision,
                        'preferences' => null,
                        'results' => [],
                    ];
                } else {
                    if ($normalized['base_revision'] < $revision) {
                        $operationResult = [
                            'operation_id' => $normalized['operation_id'],
                            'status' => 'conflict',
                            'code' => 'stale_write',
                            'current_revision' => $revision,
                        ];
                        $hadConflict = true;
                    } else {
                        $changes = [];
                        foreach (['locale', 'timezone'] as $field) {
                            if (array_key_exists($field, $normalized)
                                && $normalized[$field] !== $preferences->{$field}) {
                                $changes[$field] = $normalized[$field];
                            }
                        }

                        if ($changes !== []) {
                            $revision++;
                            $connection->table('user_preferences')
                                ->where('user_id', $userId)
                                ->update([
                                    ...$changes,
                                    'revision' => $revision,
                                    'updated_at' => now(),
                                ]);
                            foreach ($changes as $field => $value) {
                                $preferences->{$field} = $value;
                            }
                            $preferences->revision = $revision;
                        }

                        $operationResult = [
                            'operation_id' => $normalized['operation_id'],
                            'status' => 'applied',
                            'revision' => $revision,
                        ];
                    }

                    $connection->table('user_preference_operations')->insert([
                        'user_id' => $userId,
                        'operation_id' => $normalized['operation_id'],
                        'payload_hash' => $fingerprint,
                        'result' => json_encode(
                            $operationResult,
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        ),
                        'revision' => $revision,
                        'created_at' => now(),
                    ]);
                    $results[] = $operationResult;
                }
            }

            $includePreferences = $sinceRevision === 0
                || $sinceRevision < $revision
                || $hadConflict;

            return [
                'revision_ahead' => false,
                'operation_id_reused' => false,
                'server_revision' => $revision,
                'preferences' => $includePreferences ? [
                    'locale' => $preferences->locale,
                    'timezone' => (string) $preferences->timezone,
                    'revision' => $revision,
                    'updated_at' => (string) $preferences->updated_at,
                ] : null,
                'changed' => $sinceRevision < $revision,
                'full' => $sinceRevision === 0,
                'results' => $results,
            ];
        });

        if (($result['revision_ahead'] ?? false) === true) {
            return response()->json([
                'success' => false,
                'errors' => [
                    'since_revision' => ['The client revision is ahead of the server. A full sync is required.'],
                ],
                'server_revision' => (int) $result['server_revision'],
            ], 422);
        }

        if (($result['operation_id_reused'] ?? false) === true) {
            return response()->json([
                'success' => false,
                'error' => 'operation_id_reused',
                'operation_id' => $result['operation_id'],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'server_revision' => (int) $result['server_revision'],
            'server_time_utc' => $serverTime,
            'full' => (bool) $result['full'],
            'changed' => (bool) $result['changed'],
            'preferences' => $result['preferences'],
            'results' => $result['results'],
        ]);
    }
}
