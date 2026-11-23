<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;

class BookmarkSyncController extends Controller
{
    private const MAX_BATCH = 500;

    /**
     * Merge the caller's pending bookmark additions and deletions, then return
     * the complete owner-scoped collection expected by the Flutter client.
     *
     * The client uses a local known-ID journal, so replaying a successful
     * batch must not create duplicate rows. The unique owner/media key and
     * database upsert make that guarantee hold under concurrent requests too.
     */
    public function sync(Request $request): JsonResponse
    {
        if ($request->exists('operations') || $request->exists('since_revision')
            || $request->exists('until_revision') || $request->exists('cursor')) {
            return $this->syncVersioned($request);
        }

        $validated = $request->validate([
            'movies' => ['sometimes', 'array', 'max:'.self::MAX_BATCH],
            'movies.*' => ['required', 'array'],
            'movies.*.id' => ['required', 'integer', 'min:1'],
            'movies.*.title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'movies.*.poster_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'movies.*.backdrop_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'movies.*.overview' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'movies.*.original_title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'movies.*.original_language' => ['sometimes', 'nullable', 'string', 'max:32'],
            'movies.*.release_date' => ['sometimes', 'nullable', 'string', 'max:64'],
            'movies.*.date_added' => ['sometimes', 'nullable', 'string', 'max:64'],
            'movies.*.vote_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'movies.*.vote_average' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'movies.*.popularity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'movies.*.genre_ids' => ['sometimes', 'nullable'],

            'tvShows' => ['sometimes', 'array', 'max:'.self::MAX_BATCH],
            'tvShows.*' => ['required', 'array'],
            'tvShows.*.id' => ['required', 'integer', 'min:1'],
            'tvShows.*.name' => ['sometimes', 'nullable', 'string', 'max:500'],
            'tvShows.*.poster_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'tvShows.*.backdrop_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'tvShows.*.overview' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'tvShows.*.original_title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'tvShows.*.original_language' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tvShows.*.first_air_date' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tvShows.*.date_added' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tvShows.*.vote_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tvShows.*.vote_average' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'tvShows.*.popularity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tvShows.*.genre_ids' => ['sometimes', 'nullable'],

            'deleted_media' => ['sometimes', 'array', 'max:1000'],
            'deleted_media.*' => ['required', 'array'],
            'deleted_media.*.media_type' => ['required', 'in:movie,tv'],
            'deleted_media.*.media_id' => ['required', 'integer', 'min:1'],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $now = now();
        $pending = [];

        foreach ($validated['movies'] ?? [] as $movie) {
            $id = (int) $movie['id'];
            $pending['movie:'.$id] = [
                'user_id' => $userId,
                'media_type' => 'movie',
                'media_id' => $id,
                'metadata' => $this->encodeMetadata($this->metadata($movie, 'movie')),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($validated['tvShows'] ?? [] as $show) {
            $id = (int) $show['id'];
            $pending['tv:'.$id] = [
                'user_id' => $userId,
                'media_type' => 'tv',
                'media_id' => $id,
                'metadata' => $this->encodeMetadata($this->metadata($show, 'tv')),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $deletions = [];
        foreach ($validated['deleted_media'] ?? [] as $deleted) {
            $type = (string) $deleted['media_type'];
            $id = (int) $deleted['media_id'];
            $deletions[$type.':'.$id] = ['media_type' => $type, 'media_id' => $id];
        }

        DB::transaction(function () use ($pending, $deletions, $userId, $now): void {
            // Keep each SQLite statement below its host-parameter limit.
            foreach (array_chunk(array_values($pending), 100) as $batch) {
                DB::table('user_bookmarks')->upsert(
                    $batch,
                    ['user_id', 'media_type', 'media_id'],
                    ['metadata', 'updated_at'],
                );
            }

            // Deletions are applied last so a conflicting entry in one request
            // cannot silently override the caller's explicit tombstone.
            foreach (['movie', 'tv'] as $type) {
                $ids = array_values(array_map(
                    static fn (array $row): int => $row['media_id'],
                    array_filter(
                        $deletions,
                        static fn (array $row): bool => $row['media_type'] === $type,
                    ),
                ));

                foreach (array_chunk($ids, 500) as $idBatch) {
                    foreach ($idBatch as $mediaId) {
                        $this->appendLegacyRemoval(
                            DB::connection(),
                            $userId,
                            $type,
                            $mediaId,
                        );
                    }
                }
            }
        });

        $rows = DB::table('user_bookmarks')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderBy('media_type')
            ->orderBy('media_id')
            ->get(['media_type', 'media_id', 'metadata', 'created_at']);

        $movies = [];
        $tvShows = [];

        foreach ($rows as $row) {
            $metadata = json_decode((string) $row->metadata, true);
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['id'] = (int) $row->media_id;
            $metadata['genre_ids'] = $this->normalizeGenreIds($metadata['genre_ids'] ?? null);
            $metadata['date_added'] = $metadata['date_added'] ?? (string) $row->created_at;

            if ($row->media_type === 'movie') {
                $movies[] = $metadata;
            } else {
                $tvShows[] = $metadata;
            }
        }

        return response()->json([
            'success' => true,
            'movies' => $movies,
            'tvShows' => $tvShows,
        ]);
    }

    /**
     * Delete one bookmark. A missing row is still a successful result, which
     * makes the endpoint safe for client retries and duplicate requests.
     */
    public function delete(Request $request, string $type, string $id): JsonResponse
    {
        abort_unless(in_array($type, ['movie', 'tv'], true), 404);

        $mediaId = filter_var($id, FILTER_VALIDATE_INT);
        abort_unless($mediaId !== false && $mediaId > 0, 404);

        $userId = (int) $request->user()->getAuthIdentifier();
        DB::transaction(function () use ($userId, $type, $mediaId): void {
            $connection = DB::connection();
            $state = $this->lockRevision($connection, $userId);
            $this->appendLegacyRemoval($connection, $userId, $type, $mediaId, (int) $state->revision);
        });

        return response()->json(['success' => true]);
    }

    /**
     * Revision-aware bookmark operations used by clients that have migrated
     * from the legacy full-union contract. Each mutation is owner-scoped,
     * replay-safe and conditional on the revision the device last observed.
     */
    private function syncVersioned(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since_revision' => ['sometimes', 'integer', 'min:0'],
            'until_revision' => ['sometimes', 'integer', 'min:0'],
            'cursor' => ['required_with:until_revision', 'integer', 'min:0'],
            'client_time_utc' => ['required', 'integer', 'min:1'],
            'operations' => ['sometimes', 'array', 'max:500', 'prohibits:until_revision', 'prohibits:cursor'],
            'operations.*' => ['required', 'array'],
            'operations.*.operation_id' => ['required', 'uuid'],
            'operations.*.action' => ['required', 'in:bookmark.add,bookmark.remove'],
            'operations.*.media_type' => ['required', 'in:movie,tv'],
            'operations.*.media_id' => ['required', 'integer', 'min:1'],
            'operations.*.base_revision' => ['required', 'integer', 'min:0'],
            'operations.*.metadata' => ['sometimes', 'array'],
            'operations.*.metadata.title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'operations.*.metadata.name' => ['sometimes', 'nullable', 'string', 'max:500'],
            'operations.*.metadata.poster_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'operations.*.metadata.backdrop_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'operations.*.metadata.overview' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'operations.*.metadata.original_title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'operations.*.metadata.original_language' => ['sometimes', 'nullable', 'string', 'max:32'],
            'operations.*.metadata.release_date' => ['sometimes', 'nullable', 'string', 'max:64'],
            'operations.*.metadata.first_air_date' => ['sometimes', 'nullable', 'string', 'max:64'],
            'operations.*.metadata.date_added' => ['sometimes', 'nullable', 'string', 'max:64'],
            'operations.*.metadata.vote_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'operations.*.metadata.vote_average' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'operations.*.metadata.popularity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'operations.*.metadata.genre_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'operations.*.metadata.genre_ids.*' => ['integer', 'min:1'],
        ]);

        $serverTime = (int) floor(microtime(true) * 1000);
        if ((int) $validated['client_time_utc'] > $serverTime + 86_400_000) {
            return response()->json([
                'success' => false,
                'error' => 'clock_skew_detected',
                'server_time_utc' => $serverTime,
            ], 422);
        }

        foreach ($validated['operations'] ?? [] as $index => $operation) {
            if ($operation['action'] === 'bookmark.add'
                && ! isset($operation['metadata'])) {
                return response()->json([
                    'success' => false,
                    'errors' => [
                        "operations.$index.metadata" => ['Metadata is required when adding a bookmark.'],
                    ],
                ], 422);
            }
        }

        $userId = (int) $request->user()->getAuthIdentifier();
        $sinceRevision = (int) ($validated['since_revision'] ?? 0);
        $full = $sinceRevision === 0;

        return DB::transaction(function () use (
            $validated,
            $userId,
            $serverTime,
            $sinceRevision,
            $full,
        ): JsonResponse {
            $connection = DB::connection();
            $state = $this->lockRevision($connection, $userId);
            $revision = (int) $state->revision;

            if ($sinceRevision > $revision) {
                return response()->json([
                    'success' => false,
                    'errors' => [
                        'since_revision' => ['The client revision is ahead of the server. A full sync is required.'],
                    ],
                ], 422);
            }

            if (isset($validated['until_revision'])
                && (int) $validated['until_revision'] > $revision) {
                return response()->json([
                    'success' => false,
                    'errors' => [
                        'until_revision' => ['The requested snapshot revision is ahead of the server.'],
                    ],
                ], 422);
            }

            if ($full && isset($validated['until_revision'])) {
                return response()->json([
                    'success' => false,
                    'errors' => [
                        'until_revision' => ['A full snapshot does not use incremental page cursors.'],
                    ],
                ], 422);
            }

            $hashes = [];
            foreach ($validated['operations'] ?? [] as $operation) {
                $normalized = $this->normalizeVersionedOperation($operation);
                $hash = hash('sha256', $this->encodeMetadata($this->canonicalize($normalized)));
                $operationId = $normalized['operation_id'];

                if (isset($hashes[$operationId]) && $hashes[$operationId] !== $hash) {
                    return response()->json([
                        'success' => false,
                        'error' => 'operation_id_reused',
                    ], 409);
                }
                $hashes[$operationId] = $hash;

                if ((int) $normalized['base_revision'] > $revision) {
                    return response()->json([
                        'success' => false,
                        'errors' => [
                            'operations' => ['An operation base revision is ahead of the server.'],
                        ],
                    ], 422);
                }

                $stored = $connection->table('user_bookmark_operations')
                    ->where('user_id', $userId)
                    ->where('operation_id', $operationId)
                    ->first();
                if ($stored !== null && ! hash_equals((string) $stored->payload_hash, $hash)) {
                    return response()->json([
                        'success' => false,
                        'error' => 'operation_id_reused',
                    ], 409);
                }
            }

            $results = [];
            foreach ($validated['operations'] ?? [] as $operation) {
                $normalized = $this->normalizeVersionedOperation($operation);
                $operationId = $normalized['operation_id'];
                $hash = $hashes[$operationId];
                $stored = $connection->table('user_bookmark_operations')
                    ->where('user_id', $userId)
                    ->where('operation_id', $operationId)
                    ->first();

                if ($stored !== null) {
                    $storedResult = json_decode((string) $stored->result, true);
                    $results[] = is_array($storedResult) ? $storedResult : [
                        'operation_id' => $operationId,
                        'status' => 'applied',
                        'revision' => (int) $stored->revision,
                    ];

                    continue;
                }

                $type = $normalized['media_type'];
                $mediaId = (int) $normalized['media_id'];
                $latest = $connection->table('user_bookmark_changes')
                    ->where('user_id', $userId)
                    ->where('media_type', $type)
                    ->where('media_id', $mediaId)
                    ->orderByDesc('revision')
                    ->first();
                $latestRevision = $latest === null ? 0 : (int) $latest->revision;

                if ((int) $normalized['base_revision'] < $latestRevision) {
                    $result = [
                        'operation_id' => $operationId,
                        'status' => 'conflict',
                        'code' => 'stale_write',
                        'current_revision' => $latestRevision,
                    ];
                    $this->storeBookmarkOperation($connection, $userId, $normalized, $hash, $result, $latestRevision);
                    $results[] = $result;

                    continue;
                }

                $action = $normalized['action'];
                $metadata = $normalized['metadata'];
                if ($action === 'bookmark.add') {
                    $metadata['id'] = $mediaId;
                    $connection->table('user_bookmarks')->updateOrInsert(
                        [
                            'user_id' => $userId,
                            'media_type' => $type,
                            'media_id' => $mediaId,
                        ],
                        [
                            'metadata' => $this->encodeMetadata($metadata),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    );
                } else {
                    $connection->table('user_bookmarks')
                        ->where('user_id', $userId)
                        ->where('media_type', $type)
                        ->where('media_id', $mediaId)
                        ->delete();
                }

                $revision++;
                $payload = $action === 'bookmark.add' ? $metadata : [];
                $connection->table('user_bookmark_changes')->insert([
                    'user_id' => $userId,
                    'revision' => $revision,
                    'media_type' => $type,
                    'media_id' => $mediaId,
                    'action' => $action,
                    'payload' => $this->encodeMetadata($payload),
                    'deleted' => $action === 'bookmark.remove',
                    'created_at' => now(),
                ]);

                $result = [
                    'operation_id' => $operationId,
                    'status' => 'applied',
                    'revision' => $revision,
                ];
                $this->storeBookmarkOperation($connection, $userId, $normalized, $hash, $result, $revision);
                $results[] = $result;
            }

            $connection->table('user_sync_revisions')
                ->where('id', $state->id)
                ->update(['revision' => $revision, 'updated_at' => now()]);

            $untilRevision = isset($validated['until_revision'])
                ? (int) $validated['until_revision']
                : $revision;
            $cursor = (int) ($validated['cursor'] ?? 0);
            $changes = [];
            $nextCursor = null;
            $movies = [];
            $tvShows = [];

            if ($full) {
                $snapshot = $connection->table('user_bookmarks')
                    ->where('user_id', $userId)
                    ->orderBy('created_at')
                    ->orderBy('media_type')
                    ->orderBy('media_id')
                    ->get(['media_type', 'media_id', 'metadata', 'created_at']);
                foreach ($snapshot as $row) {
                    $metadata = json_decode((string) $row->metadata, true);
                    $metadata = is_array($metadata) ? $metadata : [];
                    $metadata['id'] = (int) $row->media_id;
                    $metadata['genre_ids'] = $this->normalizeGenreIds($metadata['genre_ids'] ?? null);
                    $metadata['date_added'] = $metadata['date_added'] ?? (string) $row->created_at;

                    if ($row->media_type === 'movie') {
                        $movies[] = $metadata;
                    } else {
                        $tvShows[] = $metadata;
                    }
                }
            } else {
                $page = $connection->table('user_bookmark_changes')
                    ->where('user_id', $userId)
                    ->where('revision', '>', $sinceRevision)
                    ->where('revision', '<=', $untilRevision)
                    ->where('id', '>', $cursor)
                    ->orderBy('id')
                    ->limit(201)
                    ->get();

                if ($page->count() > 200) {
                    $nextCursor = (int) $page[199]->id;
                    $page = $page->take(200);
                }

                $changes = $page->map(function ($row): array {
                    $metadata = json_decode((string) $row->payload, true);

                    return [
                        'revision' => (int) $row->revision,
                        'media_type' => (string) $row->media_type,
                        'media_id' => (int) $row->media_id,
                        'action' => (string) $row->action,
                        'deleted' => (bool) $row->deleted,
                        'metadata' => is_array($metadata) ? $metadata : [],
                    ];
                })->all();
            }

            return response()->json([
                'success' => true,
                'server_revision' => $revision,
                'server_time_utc' => $serverTime,
                'full' => $full,
                'until_revision' => $untilRevision,
                'results' => $results,
                'changes' => $changes,
                'movies' => $movies,
                'tvShows' => $tvShows,
                'next_cursor' => $nextCursor,
            ]);
        });
    }

    /**
     * Create or retrieve the serialized revision row for this account/domain.
     */
    private function lockRevision($connection, int $userId): object
    {
        $connection->table('user_sync_revisions')->insertOrIgnore([
            'user_id' => $userId,
            'domain' => 'bookmarks',
            'revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $connection->table('user_sync_revisions')
            ->where('user_id', $userId)
            ->where('domain', 'bookmarks')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Preserve deletes made through the legacy endpoint in the versioned
     * change stream. Repeated deletes do not generate another revision.
     */
    private function appendLegacyRemoval(
        $connection,
        int $userId,
        string $type,
        int $mediaId,
        ?object $state = null,
    ): void {
        $state ??= $this->lockRevision($connection, $userId);
        $latest = $connection->table('user_bookmark_changes')
            ->where('user_id', $userId)
            ->where('media_type', $type)
            ->where('media_id', $mediaId)
            ->orderByDesc('revision')
            ->first();

        if ($latest === null || $latest->action !== 'bookmark.remove') {
            $revision = (int) $state->revision + 1;
            $connection->table('user_bookmark_changes')->insert([
                'user_id' => $userId,
                'revision' => $revision,
                'media_type' => $type,
                'media_id' => $mediaId,
                'action' => 'bookmark.remove',
                'payload' => '{}',
                'deleted' => true,
                'created_at' => now(),
            ]);
            $connection->table('user_sync_revisions')
                ->where('id', $state->id)
                ->update(['revision' => $revision, 'updated_at' => now()]);
        }

        $connection->table('user_bookmarks')
            ->where('user_id', $userId)
            ->where('media_type', $type)
            ->where('media_id', $mediaId)
            ->delete();
    }

    private function normalizeVersionedOperation(array $operation): array
    {
        $metadata = $operation['metadata'] ?? [];
        $metadata = is_array($metadata) ? $metadata : [];
        $type = (string) $operation['media_type'];
        $mediaId = (int) $operation['media_id'];
        $allowed = $type === 'movie'
            ? ['title', 'poster_path', 'backdrop_path', 'overview', 'original_title', 'original_language', 'release_date', 'date_added', 'vote_count', 'vote_average', 'popularity', 'genre_ids']
            : ['name', 'poster_path', 'backdrop_path', 'overview', 'original_title', 'original_language', 'first_air_date', 'date_added', 'vote_count', 'vote_average', 'popularity', 'genre_ids'];

        $metadata = array_intersect_key($metadata, array_fill_keys($allowed, true));
        if (array_key_exists('genre_ids', $metadata)) {
            $metadata['genre_ids'] = $this->normalizeGenreIds($metadata['genre_ids']);
        }

        return [
            'operation_id' => (string) $operation['operation_id'],
            'action' => (string) $operation['action'],
            'media_type' => $type,
            'media_id' => $mediaId,
            'base_revision' => (int) $operation['base_revision'],
            'metadata' => $metadata,
        ];
    }

    private function storeBookmarkOperation(
        $connection,
        int $userId,
        array $operation,
        string $hash,
        array $result,
        int $revision,
    ): void {
        $connection->table('user_bookmark_operations')->insert([
            'user_id' => $userId,
            'operation_id' => $operation['operation_id'],
            'media_type' => $operation['media_type'],
            'media_id' => $operation['media_id'],
            'action' => $operation['action'],
            'payload_hash' => $hash,
            'result' => $this->encodeMetadata($result),
            'revision' => $revision,
            'created_at' => now(),
        ]);
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function metadata(array $item, string $type): array
    {
        $fields = [
            'id',
            'poster_path',
            'backdrop_path',
            'overview',
            'original_title',
            'original_language',
            'vote_count',
            'vote_average',
            'popularity',
            'genre_ids',
            'date_added',
        ];

        $fields[] = $type === 'movie' ? 'title' : 'name';
        $fields[] = $type === 'movie' ? 'release_date' : 'first_air_date';

        $metadata = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $item)) {
                continue;
            }

            $metadata[$field] = $field === 'genre_ids'
                ? $this->normalizeGenreIds($item[$field])
                : $item[$field];
        }

        $metadata['id'] = (int) $item['id'];

        return $metadata;
    }

    private function normalizeGenreIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @throws JsonException
     */
    private function encodeMetadata(array $metadata): string
    {
        return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
