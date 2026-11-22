<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;

class RecentlyWatchedSyncController extends Controller
{
    private const MAX_BATCH = 450;
    private const CLOCK_SKEW_LIMIT_MS = 86_400_000;
    private const TOMBSTONE_RETENTION_MS = 7_776_000_000;

    /**
     * Merge progress uploads and return the caller's changes since its cursor.
     * All identifiers and revisions are scoped to the authenticated account.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since_revision' => ['sometimes', 'integer', 'min:0'],
            'client_time_utc' => ['required', 'integer', 'min:1'],

            'movies' => ['sometimes', 'array', 'max:'.self::MAX_BATCH],
            'movies.*' => ['required', 'array'],
            'movies.*.id' => ['sometimes', 'integer', 'min:1'],
            'movies.*.movie_id' => ['required', 'integer', 'min:1'],
            'movies.*.title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'movies.*.release_year' => ['sometimes', 'nullable', 'integer', 'min:1800', 'max:3000'],
            'movies.*.elapsed' => ['required', 'integer', 'min:0'],
            'movies.*.remaining' => ['required', 'integer', 'min:0'],
            'movies.*.date_watched' => ['sometimes', 'nullable', 'string', 'max:64'],
            'movies.*.poster_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'movies.*.backdrop_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'movies.*.updated_at_utc' => ['required', 'integer', 'min:1'],
            'movies.*.deleted_at_utc' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'episodes' => ['sometimes', 'array', 'max:'.self::MAX_BATCH],
            'episodes.*' => ['required', 'array'],
            'episodes.*.id' => ['sometimes', 'integer', 'min:1'],
            'episodes.*.episode_id' => ['required', 'integer', 'min:1'],
            'episodes.*.series_id' => ['required', 'integer', 'min:1'],
            'episodes.*.series_name' => ['sometimes', 'nullable', 'string', 'max:500'],
            'episodes.*.episode_name' => ['sometimes', 'nullable', 'string', 'max:500'],
            'episodes.*.episode_num' => ['required', 'integer', 'min:0'],
            'episodes.*.season_num' => ['required', 'integer', 'min:0'],
            'episodes.*.elapsed' => ['required', 'integer', 'min:0'],
            'episodes.*.remaining' => ['required', 'integer', 'min:0'],
            'episodes.*.date_added' => ['sometimes', 'nullable', 'string', 'max:64'],
            'episodes.*.poster_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'episodes.*.backdrop_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'episodes.*.updated_at_utc' => ['required', 'integer', 'min:1'],
            'episodes.*.deleted_at_utc' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $serverTime = $this->nowUtcMilliseconds();
        $sinceRevision = array_key_exists('since_revision', $validated)
            ? (int) $validated['since_revision']
            : 0;
        $full = $sinceRevision === 0;

        if ($this->hasFutureTimestamp($validated, $serverTime)) {
            return response()->json([
                'success' => false,
                'error' => 'clock_skew_detected',
                'server_time_utc' => $serverTime,
            ], 422);
        }

        $result = DB::transaction(function () use (
            $validated,
            $userId,
            $serverTime,
            $sinceRevision,
            $full,
        ): array {
            $connection = DB::connection();
            $connection->table('user_sync_revisions')->insertOrIgnore([
                'user_id' => $userId,
                'domain' => 'recent',
                'revision' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $state = $connection->table('user_sync_revisions')
                ->where('user_id', $userId)
                ->where('domain', 'recent')
                ->lockForUpdate()
                ->first();
            $revision = (int) $state->revision;

            if ($sinceRevision > $revision) {
                return ['revision_ahead' => true, 'revision' => $revision];
            }

            $uploads = ['movies' => [], 'episodes' => []];
            $conflicts = ['movie' => [], 'episode' => []];

            foreach ($validated['movies'] ?? [] as $movie) {
                $this->mergeUpload(
                    $connection,
                    $userId,
                    'movie',
                    (int) $movie['movie_id'],
                    $movie,
                    $revision,
                    $conflicts['movie'],
                    $uploads['movies'],
                    $serverTime,
                );
            }

            foreach ($validated['episodes'] ?? [] as $episode) {
                $this->mergeUpload(
                    $connection,
                    $userId,
                    'episode',
                    (int) $episode['episode_id'],
                    $episode,
                    $revision,
                    $conflicts['episode'],
                    $uploads['episodes'],
                    $serverTime,
                );
            }

            $connection->table('user_sync_revisions')
                ->where('id', $state->id)
                ->update(['revision' => $revision, 'updated_at' => now()]);

            // The Flutter client prunes its own synced tombstones after 90 days
            // and forces a full pull at least every seven days.
            $connection->table('user_recent_media')
                ->where('user_id', $userId)
                ->whereNotNull('deleted_at_utc')
                ->where('deleted_at_utc', '<', $serverTime - self::TOMBSTONE_RETENTION_MS)
                ->delete();

            $movies = $this->pullRows($connection, $userId, 'movie', $sinceRevision, $full, $conflicts['movie'], $serverTime);
            $episodes = $this->pullRows($connection, $userId, 'episode', $sinceRevision, $full, $conflicts['episode'], $serverTime);

            return [
                'revision_ahead' => false,
                'server_revision' => $revision,
                'movies' => $movies,
                'episodes' => $episodes,
                'uploads' => $uploads,
            ];
        });

        if ($result['revision_ahead']) {
            return response()->json([
                'success' => false,
                'errors' => [
                    'since_revision' => ['The client revision is ahead of the server. A full sync is required.'],
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'server_revision' => (int) $result['server_revision'],
            'server_time_utc' => $serverTime,
            'movies' => $result['movies'],
            'episodes' => $result['episodes'],
            'uploads' => $result['uploads'],
            'full' => $full,
            'requires_full_sync' => false,
        ]);
    }

    private function mergeUpload(
        $connection,
        int $userId,
        string $type,
        int $mediaId,
        array $input,
        int &$revision,
        array &$conflicts,
        array &$uploads,
        int $serverTime,
    ): void {
        $idField = $type === 'movie' ? 'movie_id' : 'episode_id';
        $updatedAt = (int) $input['updated_at_utc'];
        $deletedAt = isset($input['deleted_at_utc']) ? (int) $input['deleted_at_utc'] : null;
        $effectiveStamp = max($updatedAt, $deletedAt ?? 0);

        $uploads[] = [
            ...$input,
            'id' => $mediaId,
            $idField => $mediaId,
            'updated_at_utc' => $effectiveStamp,
            'deleted_at_utc' => $deletedAt,
        ];

        $existing = $connection->table('user_recent_media')
            ->where('user_id', $userId)
            ->where('media_type', $type)
            ->where('media_id', $mediaId)
            ->lockForUpdate()
            ->first();

        $existingStamp = $existing === null
            ? 0
            : max((int) $existing->updated_at_utc, (int) ($existing->deleted_at_utc ?? 0));

        if ($effectiveStamp <= $existingStamp) {
            if ($existing !== null) {
                $conflicts[] = $mediaId;
            }

            return;
        }

        $metadata = $this->metadata($input, $type);
        $values = [
            'metadata' => $this->encodeMetadata($metadata),
            'elapsed' => (int) $input['elapsed'],
            'remaining' => (int) $input['remaining'],
            'updated_at_utc' => $effectiveStamp,
            'deleted_at_utc' => $deletedAt,
            'revision' => ++$revision,
            'updated_at' => now(),
        ];

        $query = $connection->table('user_recent_media')
            ->where('user_id', $userId)
            ->where('media_type', $type)
            ->where('media_id', $mediaId);

        if ($existing === null) {
            $connection->table('user_recent_media')->insert([
                'user_id' => $userId,
                'media_type' => $type,
                'media_id' => $mediaId,
                ...$values,
                'created_at' => now(),
            ]);
        } else {
            $query->update($values);
        }
    }

    private function pullRows($connection, int $userId, string $type, int $sinceRevision, bool $full, array $conflicts, int $serverTime): array
    {
        $query = $connection->table('user_recent_media')
            ->where('user_id', $userId)
            ->where('media_type', $type);

        if (! $full) {
            $query->where(function ($builder) use ($sinceRevision, $conflicts): void {
                $builder->where('revision', '>', $sinceRevision);
                if ($conflicts !== []) {
                    $builder->orWhereIn('media_id', array_values(array_unique($conflicts)));
                }
            });
        }

        return $query
            ->orderBy('revision')
            ->orderBy('media_id')
            ->get()
            ->map(function ($row) use ($type, $serverTime): array {
                $metadata = json_decode((string) $row->metadata, true);
                $metadata = is_array($metadata) ? $metadata : [];
                $idField = $type === 'movie' ? 'movie_id' : 'episode_id';

                return [
                    ...$metadata,
                    'id' => (int) $row->media_id,
                    $idField => (int) $row->media_id,
                    'elapsed' => (int) $row->elapsed,
                    'remaining' => (int) $row->remaining,
                    'updated_at_utc' => (int) $row->updated_at_utc,
                    'deleted_at_utc' => $row->deleted_at_utc === null ? null : (int) $row->deleted_at_utc,
                    'sync_revision' => (int) $row->revision,
                    'synced_at_utc' => $serverTime,
                ];
            })
            ->all();
    }

    private function metadata(array $input, string $type): array
    {
        $fields = $type === 'movie'
            ? ['title', 'release_year', 'date_watched', 'poster_path', 'backdrop_path']
            : ['series_id', 'series_name', 'episode_name', 'episode_num', 'season_num', 'date_added', 'poster_path', 'backdrop_path'];

        $metadata = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $metadata[$field] = $input[$field];
            }
        }

        return $metadata;
    }

    private function hasFutureTimestamp(array $payload, int $serverTime): bool
    {
        $limit = $serverTime + self::CLOCK_SKEW_LIMIT_MS;
        if ((int) $payload['client_time_utc'] > $limit) {
            return true;
        }

        foreach (['movies', 'episodes'] as $field) {
            foreach ($payload[$field] ?? [] as $row) {
                if ((int) $row['updated_at_utc'] > $limit
                    || (isset($row['deleted_at_utc']) && (int) $row['deleted_at_utc'] > $limit)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function nowUtcMilliseconds(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * @throws JsonException
     */
    private function encodeMetadata(array $metadata): string
    {
        return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
