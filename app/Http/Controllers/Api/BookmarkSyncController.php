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
                    DB::table('user_bookmarks')
                        ->where('user_id', $userId)
                        ->where('media_type', $type)
                        ->whereIn('media_id', $idBatch)
                        ->delete();
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

        DB::table('user_bookmarks')
            ->where('user_id', (int) $request->user()->getAuthIdentifier())
            ->where('media_type', $type)
            ->where('media_id', $mediaId)
            ->delete();

        return response()->json(['success' => true]);
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
