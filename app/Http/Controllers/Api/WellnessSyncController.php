<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;

class WellnessSyncController extends Controller
{
    private const MAX_BATCH = 450;

    private const MAX_PAGE = 450;

    private const MAX_FUTURE_SKEW_MS = 86_400_000;

    /**
     * Accept owner-scoped viewing-session and daily-summary writes, then serve
     * a stable, revision-bounded snapshot to the authenticated account.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_time_utc' => ['required', 'integer', 'min:1'],
            'since_revision' => ['sometimes', 'integer', 'min:0'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],

            'sessions' => ['sometimes', 'array', 'max:'.self::MAX_BATCH],
            'sessions.*' => ['required', 'array'],
            'sessions.*.id' => ['required', 'uuid'],
            'sessions.*.deviceId' => ['required', 'string', 'max:128'],
            'sessions.*.mediaType' => ['required', 'in:movie,episode,live'],
            'sessions.*.source' => ['required', 'in:streaming,offline,live'],
            'sessions.*.contentId' => ['required', 'string', 'max:255'],
            'sessions.*.seriesId' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sessions.*.title' => ['required', 'string', 'max:500'],
            'sessions.*.subtitle' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sessions.*.seasonNumber' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'sessions.*.episodeNumber' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'sessions.*.startedAtUtc' => ['required', 'integer', 'min:1'],
            'sessions.*.endedAtUtc' => ['required', 'integer', 'min:1'],
            'sessions.*.timezoneOffsetMinutes' => ['required', 'integer', 'min:-840', 'max:840'],
            'sessions.*.watchedMs' => ['required', 'integer', 'min:0', 'max:604800000'],
            'sessions.*.durationMs' => ['required', 'integer', 'min:0', 'max:604800000'],
            'sessions.*.progressEndMs' => ['required', 'integer', 'min:0', 'max:604800000'],
            'sessions.*.completed' => ['required', 'boolean'],
            'sessions.*.segments' => ['sometimes', 'array', 'max:500'],
            'sessions.*.segments.*' => ['required', 'array'],
            'sessions.*.segments.*.startedAtUtc' => ['required', 'integer', 'min:1'],
            'sessions.*.segments.*.endedAtUtc' => ['required', 'integer', 'min:1'],
            'sessions.*.posterPath' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'sessions.*.backdropPath' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'sessions.*.releaseYear' => ['sometimes', 'nullable', 'integer', 'min:1800', 'max:3000'],
            'sessions.*.provider' => ['sometimes', 'nullable', 'string', 'max:128'],
            'sessions.*.genres' => ['sometimes', 'array', 'max:100'],
            'sessions.*.genres.*' => ['string', 'max:120'],
            'sessions.*.languages' => ['sometimes', 'array', 'max:100'],
            'sessions.*.languages.*' => ['string', 'max:120'],
            'sessions.*.countries' => ['sometimes', 'array', 'max:100'],
            'sessions.*.countries.*' => ['string', 'max:120'],
            'sessions.*.networkBytes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sessions.*.updated_at_utc' => ['required', 'integer', 'min:1'],
            'sessions.*.deleted_at_utc' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'daily' => ['sometimes', 'array', 'max:'.self::MAX_BATCH],
            'daily.*' => ['required', 'array'],
            'daily.*.local_day' => ['required', 'date_format:Y-m-d'],
            'daily.*.timezone_offset_minutes' => ['required', 'integer', 'min:-840', 'max:840'],
            'daily.*.watched_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'daily.*.movie_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'daily.*.episode_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'daily.*.live_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'daily.*.completed_movies' => ['required', 'integer', 'min:0', 'max:10000'],
            'daily.*.completed_episodes' => ['required', 'integer', 'min:0', 'max:10000'],
            'daily.*.session_count' => ['required', 'integer', 'min:0', 'max:10000'],
            'daily.*.updated_at_utc' => ['required', 'integer', 'min:1'],
        ]);

        $sessions = $validated['sessions'] ?? [];
        $daily = $validated['daily'] ?? [];
        if (count($sessions) + count($daily) > self::MAX_BATCH) {
            return response()->json([
                'success' => false,
                'errors' => [
                    'batch' => ['A wellness sync batch may contain at most 450 combined session and daily rows.'],
                ],
            ], 422);
        }

        $serverTime = (int) floor(microtime(true) * 1000);
        if ((int) $validated['client_time_utc'] > $serverTime + self::MAX_FUTURE_SKEW_MS) {
            return $this->clockSkew($serverTime);
        }

        foreach ($sessions as $index => $session) {
            if ((int) $session['endedAtUtc'] < (int) $session['startedAtUtc']) {
                return response()->json([
                    'success' => false,
                    'errors' => [
                        "sessions.$index.endedAtUtc" => ['The session end must not precede its start.'],
                    ],
                ], 422);
            }

            if ((int) $session['updated_at_utc'] > $serverTime + self::MAX_FUTURE_SKEW_MS
                || (int) ($session['deleted_at_utc'] ?? 0) > $serverTime + self::MAX_FUTURE_SKEW_MS) {
                return $this->clockSkew($serverTime);
            }

            foreach ($session['segments'] ?? [] as $segmentIndex => $segment) {
                if ((int) $segment['endedAtUtc'] < (int) $segment['startedAtUtc']) {
                    return response()->json([
                        'success' => false,
                        'errors' => [
                            "sessions.$index.segments.$segmentIndex.endedAtUtc" => ['A playback segment end must not precede its start.'],
                        ],
                    ], 422);
                }
            }
        }

        foreach ($daily as $index => $summary) {
            if ((int) $summary['updated_at_utc'] > $serverTime + self::MAX_FUTURE_SKEW_MS) {
                return $this->clockSkew($serverTime);
            }
        }

        $userId = (int) $request->user()->getAuthIdentifier();
        $upload = DB::transaction(function () use ($sessions, $daily, $userId): array {
            $connection = DB::connection();
            $connection->table('user_sync_revisions')->insertOrIgnore([
                'user_id' => $userId,
                'domain' => 'wellness',
                'revision' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $state = $connection->table('user_sync_revisions')
                ->where('user_id', $userId)
                ->where('domain', 'wellness')
                ->lockForUpdate()
                ->first();

            $revision = (int) $state->revision;
            $daysToRefresh = [];
            $requestedDaily = [];

            foreach ($sessions as $session) {
                $revision = $this->storeSession(
                    $connection,
                    $userId,
                    $session,
                    $revision,
                    $daysToRefresh,
                );
            }

            foreach ($daily as $summary) {
                $normalized = $this->normalizeDaily($summary);
                $newReceipt = $this->recordDailyUploadReceipt(
                    $connection,
                    $userId,
                    $normalized,
                );
                $daysToRefresh[$normalized['local_day']] = true;
                if ($newReceipt) {
                    $requestedDaily[$normalized['local_day']] = $normalized;
                }
            }

            $revision = $this->rebuildDailySummaries(
                $connection,
                $userId,
                array_keys($daysToRefresh),
                $requestedDaily,
                $revision,
            );

            if ($revision !== (int) $state->revision) {
                $connection->table('user_sync_revisions')
                    ->where('id', $state->id)
                    ->update(['revision' => $revision, 'updated_at' => now()]);
            }

            return ['revision' => $revision];
        });

        // Upload batches intentionally do not advance the device checkpoint.
        // The client opens one stable pull only after every batch has completed.
        if (! array_key_exists('since_revision', $validated) && ! array_key_exists('cursor', $validated)) {
            return response()->json([
                'success' => true,
                'server_revision' => (int) $upload['revision'],
                'server_time_utc' => $serverTime,
            ]);
        }

        $sinceRevision = (int) ($validated['since_revision'] ?? 0);
        $limit = (int) ($validated['limit'] ?? self::MAX_PAGE);
        $cursorToken = $validated['cursor'] ?? null;
        $cursor = null;

        if ($cursorToken !== null) {
            $cursor = $this->decodeCursor((string) $cursorToken, $userId, $sinceRevision);
            if ($cursor === null) {
                return response()->json([
                    'success' => false,
                    'error' => 'invalid_sync_cursor',
                ], 422);
            }
        }

        $page = DB::transaction(function () use (
            $userId,
            $sinceRevision,
            $limit,
            $cursor,
        ): array {
            $connection = DB::connection();
            $state = $connection->table('user_sync_revisions')
                ->where('user_id', $userId)
                ->where('domain', 'wellness')
                ->first();

            $currentRevision = (int) ($state->revision ?? 0);
            if ($sinceRevision > $currentRevision) {
                return ['revision_ahead' => true, 'revision' => $currentRevision];
            }

            $snapshotRevision = $cursor['snapshot_revision'] ?? $currentRevision;
            $lastId = $cursor['last_id'] ?? 0;
            if ($snapshotRevision > $currentRevision || $snapshotRevision < $sinceRevision) {
                return ['invalid_cursor' => true];
            }

            $query = $connection->table('user_wellness_changes as changes')
                ->where('changes.user_id', $userId)
                ->where('changes.revision', '<=', $snapshotRevision)
                ->where('changes.revision', '>', $sinceRevision)
                ->whereNotExists(function ($newer) use ($snapshotRevision): void {
                    $newer->select(DB::raw('1'))
                        ->from('user_wellness_changes as newer')
                        ->whereColumn('newer.user_id', 'changes.user_id')
                        ->whereColumn('newer.entity_type', 'changes.entity_type')
                        ->whereColumn('newer.entity_key', 'changes.entity_key')
                        ->where('newer.revision', '<=', $snapshotRevision)
                        ->where(function ($revisionQuery): void {
                            $revisionQuery->whereColumn('newer.revision', '>', 'changes.revision')
                                ->orWhere(function ($sameRevision): void {
                                    $sameRevision->whereColumn('newer.revision', 'changes.revision')
                                        ->whereColumn('newer.id', '>', 'changes.id');
                                });
                        });
                });

            if ($lastId > 0) {
                $query->where('changes.id', '>', $lastId);
            }

            $rows = $query->orderBy('changes.id')
                ->limit($limit + 1)
                ->get([
                    'changes.id',
                    'changes.revision',
                    'changes.entity_type',
                    'changes.payload',
                    'changes.deleted',
                ]);

            $hasMore = $rows->count() > $limit;
            if ($hasMore) {
                $rows->pop();
            }

            $sessions = [];
            $daily = [];
            foreach ($rows as $row) {
                $payload = json_decode((string) $row->payload, true);
                $payload = is_array($payload) ? $payload : [];
                if ($row->entity_type === 'session') {
                    $payload['deleted'] = (bool) $row->deleted;
                    $sessions[] = $payload;
                } elseif ($row->entity_type === 'daily') {
                    $daily[] = $payload;
                }
            }

            $lastReturnedId = $rows->isEmpty() ? $lastId : (int) $rows->last()->id;

            return [
                'revision_ahead' => false,
                'invalid_cursor' => false,
                'snapshot_revision' => $snapshotRevision,
                'sessions' => $sessions,
                'daily' => $daily,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore
                    ? $this->encodeCursor($userId, $sinceRevision, $snapshotRevision, $lastReturnedId)
                    : null,
            ];
        });

        if (($page['revision_ahead'] ?? false) === true) {
            return response()->json([
                'success' => false,
                'errors' => [
                    'since_revision' => ['The client revision is ahead of the server. A full sync is required.'],
                ],
                'server_revision' => (int) $page['revision'],
            ], 422);
        }

        if (($page['invalid_cursor'] ?? false) === true) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_sync_cursor',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'server_revision' => (int) $page['snapshot_revision'],
            'server_time_utc' => $serverTime,
            'full' => $sinceRevision === 0,
            'requires_full_sync' => false,
            'sessions' => $page['sessions'],
            'daily' => $page['daily'],
            'has_more' => (bool) $page['has_more'],
            'next_cursor' => $page['next_cursor'],
        ]);
    }

    private function storeSession(
        $connection,
        int $userId,
        array $input,
        int $revision,
        array &$daysToRefresh,
    ): int {
        $updatedAt = (int) $input['updated_at_utc'];
        $submittedDeletedAt = isset($input['deleted_at_utc']) ? (int) $input['deleted_at_utc'] : null;
        $deletedAt = $submittedDeletedAt !== null && $submittedDeletedAt >= $updatedAt
            ? $submittedDeletedAt
            : null;
        $effectiveStamp = max($updatedAt, $deletedAt ?? 0);
        $payload = $this->normalizeSession($input, $effectiveStamp, $deletedAt);
        $encoded = $this->encode($payload);
        $fingerprint = hash('sha256', $encoded);
        $sessionId = (string) $input['id'];

        $receipt = $connection->table('user_wellness_upload_receipts')
            ->where('user_id', $userId)
            ->where('entity_type', 'session')
            ->where('entity_key', $sessionId)
            ->where('upload_stamp', $effectiveStamp)
            ->where('payload_hash', $fingerprint)
            ->exists();

        if ($receipt) {
            return $revision;
        }

        $existing = $connection->table('user_wellness_sessions')
            ->where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->lockForUpdate()
            ->first();

        $existingPayload = [];
        if ($existing !== null) {
            $decoded = json_decode((string) $existing->payload, true);
            $existingPayload = is_array($decoded) ? $decoded : [];
        }

        if ($existing === null || $effectiveStamp > max(
            (int) $existing->updated_at_utc,
            (int) ($existing->deleted_at_utc ?? 0),
        )) {
            $this->includeDailyDays($daysToRefresh, $existingPayload);
            $this->includeDailyDays($daysToRefresh, $payload);

            $revision++;
            if ($existing === null) {
                $connection->table('user_wellness_sessions')->insert([
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'updated_at_utc' => $effectiveStamp,
                    'deleted_at_utc' => $deletedAt,
                    'revision' => $revision,
                    'payload' => $encoded,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $connection->table('user_wellness_sessions')
                    ->where('id', $existing->id)
                    ->update([
                        'updated_at_utc' => $effectiveStamp,
                        'deleted_at_utc' => $deletedAt,
                        'revision' => $revision,
                        'payload' => $encoded,
                        'updated_at' => now(),
                    ]);
            }
            $this->appendChange($connection, $userId, $revision, 'session', $sessionId, $payload, $deletedAt !== null);
        } elseif ($existing !== null) {
            // A stale device still needs the winning session and every affected
            // daily aggregate. The canonical summary is rebuilt from server rows.
            $winner = $existingPayload;
            $this->includeDailyDays($daysToRefresh, $payload);
            $this->includeDailyDays($daysToRefresh, $winner);

            $revision++;
            $this->appendChange(
                $connection,
                $userId,
                $revision,
                'session',
                $sessionId,
                $winner,
                $existing->deleted_at_utc !== null,
            );
        }

        $connection->table('user_wellness_upload_receipts')->insert([
            'user_id' => $userId,
            'entity_type' => 'session',
            'entity_key' => $sessionId,
            'upload_stamp' => $effectiveStamp,
            'payload_hash' => $fingerprint,
            'created_at' => now(),
        ]);

        return $revision;
    }

    private function recordDailyUploadReceipt($connection, int $userId, array $payload): bool
    {
        $updatedAt = (int) $payload['updated_at_utc'];
        $localDay = (string) $payload['local_day'];
        $encoded = $this->encode($payload);
        $fingerprint = hash('sha256', $encoded);

        $receipt = $connection->table('user_wellness_upload_receipts')
            ->where('user_id', $userId)
            ->where('entity_type', 'daily')
            ->where('entity_key', $localDay)
            ->where('upload_stamp', $updatedAt)
            ->where('payload_hash', $fingerprint)
            ->exists();

        if ($receipt) {
            return false;
        }

        $connection->table('user_wellness_upload_receipts')->insert([
            'user_id' => $userId,
            'entity_type' => 'daily',
            'entity_key' => $localDay,
            'upload_stamp' => $updatedAt,
            'payload_hash' => $fingerprint,
            'created_at' => now(),
        ]);

        return true;
    }

    /**
     * A daily summary is derived from all active sessions for an account.
     * It is never replaced by one device's partial local aggregate.
     */
    private function rebuildDailySummaries(
        $connection,
        int $userId,
        array $daysToRefresh,
        array $requestedDaily,
        int $revision,
    ): int {
        $targetDays = array_fill_keys(array_values(array_unique($daysToRefresh)), true);
        if ($targetDays === []) {
            return $revision;
        }

        $aggregates = [];
        $rows = $connection->table('user_wellness_sessions')
            ->where('user_id', $userId)
            ->whereNull('deleted_at_utc')
            ->orderBy('id')
            ->get(['payload']);

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->payload, true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->dailyContributions($decoded) as $day => $contribution) {
                if (! isset($targetDays[$day])) {
                    continue;
                }

                if (! isset($aggregates[$day])) {
                    $aggregates[$day] = $contribution;
                    continue;
                }

                foreach ([
                    'watched_ms',
                    'movie_ms',
                    'episode_ms',
                    'live_ms',
                    'completed_movies',
                    'completed_episodes',
                    'session_count',
                ] as $field) {
                    $aggregates[$day][$field] += (int) $contribution[$field];
                }
                $aggregates[$day]['updated_at_utc'] = max(
                    (int) $aggregates[$day]['updated_at_utc'],
                    (int) $contribution['updated_at_utc'],
                );
            }
        }

        foreach (array_keys($targetDays) as $localDay) {
            $payload = $aggregates[$localDay] ?? [
                'local_day' => $localDay,
                'timezone_offset_minutes' => 0,
                'watched_ms' => 0,
                'movie_ms' => 0,
                'episode_ms' => 0,
                'live_ms' => 0,
                'completed_movies' => 0,
                'completed_episodes' => 0,
                'session_count' => 0,
                'updated_at_utc' => 0,
            ];
            $encoded = $this->encode($payload);
            $existing = $connection->table('user_wellness_daily')
                ->where('user_id', $userId)
                ->where('local_day', $localDay)
                ->lockForUpdate()
                ->first();

            $existingPayload = [];
            if ($existing !== null) {
                $decoded = json_decode((string) $existing->payload, true);
                $existingPayload = is_array($decoded) ? $decoded : [];
            }

            $same = $existing !== null && $this->encode($existingPayload) === $encoded;
            $requested = $requestedDaily[$localDay] ?? null;
            // Publish the canonical aggregate once when a new device payload
            // differs. The receipt suppresses the same stale payload on retry.
            $needsAcknowledgement = is_array($requested)
                && $this->encode($requested) !== $encoded;

            if ($same && ! $needsAcknowledgement) {
                continue;
            }

            $revision++;
            if ($existing === null) {
                $connection->table('user_wellness_daily')->insert([
                    'user_id' => $userId,
                    'local_day' => $localDay,
                    'updated_at_utc' => (int) $payload['updated_at_utc'],
                    'revision' => $revision,
                    'payload' => $encoded,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $connection->table('user_wellness_daily')
                    ->where('id', $existing->id)
                    ->update([
                        'updated_at_utc' => (int) $payload['updated_at_utc'],
                        'revision' => $revision,
                        'payload' => $encoded,
                        'updated_at' => now(),
                    ]);
            }
            $this->appendChange($connection, $userId, $revision, 'daily', $localDay, $payload, false);
        }

        return $revision;
    }

    /**
     * Matches the Flutter daily-summary allocation: 30-second qualifying
     * sessions, session count on the start day, watched time split by segments,
     * and completion counts on the local end day.
     */
    private function dailyContributions(array $session): array
    {
        if (($session['deleted_at_utc'] ?? null) !== null
            || (int) ($session['watched_ms'] ?? 0) < 30_000) {
            return [];
        }

        $offsetMinutes = (int) ($session['timezone_offset_minutes'] ?? 0);
        $offsetMilliseconds = $offsetMinutes * 60_000;
        $updatedAt = (int) ($session['updated_at_utc'] ?? 0);
        $mediaType = (string) ($session['media_type'] ?? 'movie');
        $mediaField = match ($mediaType) {
            'episode' => 'episode_ms',
            'live' => 'live_ms',
            default => 'movie_ms',
        };
        $buckets = [];

        $ensureDay = function (int $localMilliseconds) use (
            &$buckets,
            $offsetMinutes,
            $updatedAt,
        ): string {
            $day = gmdate('Y-m-d', intdiv($localMilliseconds, 1000));
            if (! isset($buckets[$day])) {
                $buckets[$day] = [
                    'local_day' => $day,
                    'timezone_offset_minutes' => $offsetMinutes,
                    'watched_ms' => 0,
                    'movie_ms' => 0,
                    'episode_ms' => 0,
                    'live_ms' => 0,
                    'completed_movies' => 0,
                    'completed_episodes' => 0,
                    'session_count' => 0,
                    'updated_at_utc' => $updatedAt,
                ];
            } elseif ($updatedAt > (int) $buckets[$day]['updated_at_utc']) {
                $buckets[$day]['updated_at_utc'] = $updatedAt;
            }

            return $day;
        };

        $startLocal = (int) $session['started_at_utc'] + $offsetMilliseconds;
        $startDay = $ensureDay($startLocal);
        $buckets[$startDay]['session_count']++;

        $segments = $session['segments'] ?? [];
        if ($segments === []) {
            $buckets[$startDay]['watched_ms'] += (int) $session['watched_ms'];
            $buckets[$startDay][$mediaField] += (int) $session['watched_ms'];
        } else {
            foreach ($segments as $segment) {
                $cursor = (int) $segment['startedAtUtc'] + $offsetMilliseconds;
                $end = (int) $segment['endedAtUtc'] + $offsetMilliseconds;
                while ($cursor < $end) {
                    $day = $ensureDay($cursor);
                    $nextMidnight = (intdiv($cursor, 86_400_000) + 1) * 86_400_000;
                    $sliceEnd = min($end, $nextMidnight);
                    $duration = $sliceEnd - $cursor;
                    $buckets[$day]['watched_ms'] += $duration;
                    $buckets[$day][$mediaField] += $duration;
                    $cursor = $sliceEnd;
                }
            }
        }

        if ((bool) ($session['completed'] ?? false)) {
            $endLocal = (int) $session['ended_at_utc'] + $offsetMilliseconds;
            $endDay = $ensureDay($endLocal);
            if ($mediaType === 'movie') {
                $buckets[$endDay]['completed_movies']++;
            } elseif ($mediaType === 'episode') {
                $buckets[$endDay]['completed_episodes']++;
            }
        }

        return $buckets;
    }

    private function includeDailyDays(array &$target, array $session): void
    {
        foreach (array_keys($this->dailyContributions($session)) as $day) {
            $target[$day] = true;
        }
    }

    private function appendChange(
        $connection,
        int $userId,
        int $revision,
        string $type,
        string $key,
        array $payload,
        bool $deleted,
    ): void {
        $connection->table('user_wellness_changes')->insert([
            'user_id' => $userId,
            'revision' => $revision,
            'entity_type' => $type,
            'entity_key' => $key,
            'payload' => $this->encode($payload),
            'deleted' => $deleted,
            'created_at' => now(),
        ]);
    }

    private function normalizeSession(array $input, int $updatedAt, ?int $deletedAt): array
    {
        $segments = [];
        foreach ($input['segments'] ?? [] as $segment) {
            $segments[] = [
                'startedAtUtc' => (int) $segment['startedAtUtc'],
                'endedAtUtc' => (int) $segment['endedAtUtc'],
            ];
        }

        $payload = [
            'id' => (string) $input['id'],
            'device_id' => (string) $input['deviceId'],
            'media_type' => (string) $input['mediaType'],
            'source' => (string) $input['source'],
            'content_id' => (string) $input['contentId'],
            'title' => (string) $input['title'],
            'started_at_utc' => (int) $input['startedAtUtc'],
            'ended_at_utc' => (int) $input['endedAtUtc'],
            'timezone_offset_minutes' => (int) $input['timezoneOffsetMinutes'],
            'watched_ms' => (int) $input['watchedMs'],
            'duration_ms' => (int) $input['durationMs'],
            'progress_end_ms' => (int) $input['progressEndMs'],
            'completed' => (bool) $input['completed'],
            'segments' => $segments,
            'series_id' => $input['seriesId'] ?? null,
            'subtitle' => $input['subtitle'] ?? null,
            'season_number' => isset($input['seasonNumber']) ? (int) $input['seasonNumber'] : null,
            'episode_number' => isset($input['episodeNumber']) ? (int) $input['episodeNumber'] : null,
            'poster_path' => $input['posterPath'] ?? null,
            'backdrop_path' => $input['backdropPath'] ?? null,
            'release_year' => isset($input['releaseYear']) ? (int) $input['releaseYear'] : null,
            'provider' => $input['provider'] ?? null,
            'genres' => array_values($input['genres'] ?? []),
            'languages' => array_values($input['languages'] ?? []),
            'countries' => array_values($input['countries'] ?? []),
            'updated_at_utc' => $updatedAt,
            'deleted_at_utc' => $deletedAt,
        ];
        if (array_key_exists('networkBytes', $input) && $input['networkBytes'] !== null) {
            $payload['network_bytes'] = (int) $input['networkBytes'];
        }

        return $payload;
    }

    private function normalizeDaily(array $input): array
    {
        return [
            'local_day' => (string) $input['local_day'],
            'timezone_offset_minutes' => (int) $input['timezone_offset_minutes'],
            'watched_ms' => (int) $input['watched_ms'],
            'movie_ms' => (int) $input['movie_ms'],
            'episode_ms' => (int) $input['episode_ms'],
            'live_ms' => (int) $input['live_ms'],
            'completed_movies' => (int) $input['completed_movies'],
            'completed_episodes' => (int) $input['completed_episodes'],
            'session_count' => (int) $input['session_count'],
            'updated_at_utc' => (int) $input['updated_at_utc'],
        ];
    }

    private function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function encodeCursor(int $userId, int $since, int $snapshot, int $lastId): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode([
            'version' => 1,
            'user_id' => $userId,
            'since_revision' => $since,
            'snapshot_revision' => $snapshot,
            'last_id' => $lastId,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, (string) config('app.key'));

        return $encoded.'.'.$signature;
    }

    private function decodeCursor(string $cursor, int $userId, int $since): ?array
    {
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expected = hash_hmac('sha256', $encoded, (string) config('app.key'));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $base64 = strtr($encoded, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ($payload['user_id'] ?? null) !== $userId
            || ($payload['since_revision'] ?? null) !== $since
            || ! is_int($payload['snapshot_revision'] ?? null)
            || ! is_int($payload['last_id'] ?? null)
            || $payload['last_id'] < 0) {
            return null;
        }

        return [
            'snapshot_revision' => $payload['snapshot_revision'],
            'last_id' => $payload['last_id'],
        ];
    }

    private function clockSkew(int $serverTime): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'clock_skew_detected',
            'server_time_utc' => $serverTime,
        ], 422);
    }
}
