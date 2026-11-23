<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;

class PlaylistSyncController extends Controller
{
    private const MAX_OPERATIONS = 500;
    private const MAX_PLAYLISTS = 200;
    private const MAX_ITEMS = 2500;
    private const PAGE_SIZE = 200;

    private const ACTIONS = [
        'playlist.create',
        'playlist.update',
        'playlist.delete',
        'playlist.reorder',
        'item.add',
        'item.remove',
    ];

    /**
     * Apply a bounded idempotent operation batch and return one stable page
     * from the caller's owner-scoped change stream.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since_revision' => ['sometimes', 'integer', 'min:0'],
            'until_revision' => ['sometimes', 'integer', 'min:0'],
            'cursor' => ['required_with:until_revision', 'integer', 'min:0'],
            'operations' => ['sometimes', 'array', 'max:'.self::MAX_OPERATIONS, 'prohibits:until_revision'],
            'operations.*' => ['required', 'array'],
            'operations.*.operation_id' => ['required', 'uuid'],
            'operations.*.action' => ['required', 'in:'.implode(',', self::ACTIONS)],
            'operations.*.playlist_id' => ['required', 'uuid'],
            'operations.*.name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'operations.*.description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'operations.*.base_version' => ['sometimes', 'integer', 'min:1'],
            'operations.*.media_type' => ['sometimes', 'in:movie,tv'],
            'operations.*.media_id' => ['sometimes', 'integer', 'min:1'],
            'operations.*.title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'operations.*.poster_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'operations.*.backdrop_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'operations.*.vote_average' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'operations.*.release_date' => ['sometimes', 'nullable', 'string', 'max:64'],
            'operations.*.genre_ids' => ['sometimes', 'array', 'max:100'],
            'operations.*.genre_ids.*' => ['integer', 'min:1'],
            'operations.*.items' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'operations.*.items.*.media_type' => ['required_with:operations.*.items', 'in:movie,tv'],
            'operations.*.items.*.media_id' => ['required_with:operations.*.items', 'integer', 'min:1'],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $sinceRevision = (int) ($validated['since_revision'] ?? 0);
        $pageRequest = array_key_exists('until_revision', $validated);
        $cursor = isset($validated['cursor']) ? (int) $validated['cursor'] : null;

        $result = DB::transaction(function () use (
            $validated,
            $userId,
            $sinceRevision,
            $pageRequest,
            $cursor,
        ): array {
            $connection = DB::connection();

            $connection->table('user_sync_revisions')->insertOrIgnore([
                'user_id' => $userId,
                'domain' => 'playlists',
                'revision' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $state = $connection->table('user_sync_revisions')
                ->where('user_id', $userId)
                ->where('domain', 'playlists')
                ->lockForUpdate()
                ->first();

            $revision = (int) $state->revision;
            $startingRevision = $revision;
            $untilRevision = $pageRequest
                ? (int) $validated['until_revision']
                : $revision;

            if ($pageRequest && ($untilRevision > $revision || $untilRevision < $sinceRevision)) {
                return ['invalid_until' => true];
            }

            if (! $pageRequest && $sinceRevision > $revision) {
                return [
                    'revision_ahead' => true,
                    'revision' => $revision,
                ];
            }

            $results = [];
            if (! $pageRequest) {
                foreach ($validated['operations'] ?? [] as $operation) {
                    $results[] = $this->processOperation(
                        $connection,
                        $userId,
                        $operation,
                        $revision,
                    );
                }
            }

            if ($revision !== $startingRevision) {
                $connection->table('user_sync_revisions')
                    ->where('id', $state->id)
                    ->update([
                        'revision' => $revision,
                        'updated_at' => now(),
                    ]);
            }

            $untilRevision = $pageRequest ? $untilRevision : $revision;
            $page = $this->changePage(
                $connection,
                $userId,
                $sinceRevision,
                $untilRevision,
                $pageRequest,
                $cursor,
            );

            $itemRows = $page['items']->all();
            $hasMore = count($itemRows) > self::PAGE_SIZE;
            if ($hasMore) {
                array_pop($itemRows);
            }

            $playlists = [];
            foreach ($page['playlists'] as $row) {
                $payload = json_decode((string) $row->payload, true);
                $payload = is_array($payload) ? $payload : [];
                $payload['deleted'] = (bool) $row->deleted;
                $playlists[] = $payload;
            }

            $items = [];
            foreach ($itemRows as $row) {
                $payload = json_decode((string) $row->payload, true);
                $payload = is_array($payload) ? $payload : [];
                $payload['deleted'] = (bool) $row->deleted;
                $items[] = $payload;
            }

            return [
                'revision_ahead' => false,
                'invalid_until' => false,
                'server_revision' => $untilRevision,
                'full' => $sinceRevision === 0,
                'results' => $results,
                'playlists' => $playlists,
                'items' => $items,
                'next_cursor' => $hasMore ? (int) end($itemRows)->id : null,
            ];
        });

        if (($result['revision_ahead'] ?? false) === true) {
            return response()->json([
                'success' => false,
                'errors' => [
                    'since_revision' => ['The client revision is ahead of the server. A full sync is required.'],
                ],
            ], 422);
        }

        if (($result['invalid_until'] ?? false) === true) {
            return response()->json([
                'success' => false,
                'errors' => [
                    'until_revision' => ['The requested playlist snapshot is not available.'],
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'server_revision' => (int) $result['server_revision'],
            'full' => (bool) $result['full'],
            'results' => $result['results'],
            'playlists' => $result['playlists'],
            'items' => $result['items'],
            'next_cursor' => $result['next_cursor'],
        ]);
    }

    private function processOperation($connection, int $userId, array $operation, int &$revision): array
    {
        $operationId = (string) $operation['operation_id'];
        $fingerprint = $this->fingerprint($operation);

        $existing = $connection->table('user_playlist_operations')
            ->where('user_id', $userId)
            ->where('operation_id', $operationId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if (! hash_equals((string) $existing->payload_hash, $fingerprint)) {
                return [
                    'operation_id' => $operationId,
                    'status' => 'rejected',
                    'code' => 'operation_id_reused',
                ];
            }

            $stored = json_decode((string) $existing->result, true);
            return is_array($stored) ? $stored : [
                'operation_id' => $operationId,
                'status' => 'applied',
            ];
        }

        $result = $this->applyOperation($connection, $userId, $operation, $revision);
        $connection->table('user_playlist_operations')->insert([
            'user_id' => $userId,
            'operation_id' => $operationId,
            'playlist_id' => (string) $operation['playlist_id'],
            'action' => (string) $operation['action'],
            'payload_hash' => $fingerprint,
            'result' => $this->encode($result),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $result;
    }

    private function applyOperation($connection, int $userId, array $operation, int &$revision): array
    {
        $action = (string) $operation['action'];
        $playlistId = (string) $operation['playlist_id'];
        $operationId = (string) $operation['operation_id'];
        $playlist = $connection->table('user_playlists')
            ->where('user_id', $userId)
            ->where('id', $playlistId)
            ->lockForUpdate()
            ->first();

        if ($action === 'playlist.create') {
            if ($playlist !== null) {
                return $this->operationResult($operationId, 'applied', ['code' => 'already_exists']);
            }

            if ($connection->table('user_playlists')->where('id', $playlistId)->exists()) {
                return $this->operationResult($operationId, 'rejected', ['code' => 'playlist_id_taken']);
            }

            $listCount = $connection->table('user_playlists')
                ->where('user_id', $userId)
                ->count();
            if ($listCount >= self::MAX_PLAYLISTS) {
                return $this->operationResult($operationId, 'rejected', ['code' => 'playlist_limit']);
            }

            $name = trim((string) ($operation['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80) {
                return $this->operationResult($operationId, 'rejected', ['code' => 'invalid_name']);
            }

            $description = $this->cleanDescription($operation['description'] ?? null);
            $now = now();
            $connection->table('user_playlists')->insert([
                'id' => $playlistId,
                'user_id' => $userId,
                'name' => $name,
                'description' => $description,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $revision++;
            $this->appendPlaylistChange($connection, $userId, $revision, $playlistId);

            return $this->operationResult($operationId, 'applied');
        }

        if ($action === 'playlist.delete') {
            if ($playlist === null) {
                return $this->operationResult($operationId, 'applied', ['code' => 'already_deleted']);
            }

            $items = $connection->table('user_playlist_items')
                ->where('playlist_id', $playlistId)
                ->orderBy('position')
                ->get();

            $revision++;
            foreach ($items as $item) {
                $payload = $this->itemPayload($item);
                $this->appendChange(
                    $connection,
                    $userId,
                    $revision,
                    'item',
                    $this->itemKey($playlistId, (string) $item->media_type, (int) $item->media_id),
                    $payload,
                    true,
                );
            }

            $this->appendChange(
                $connection,
                $userId,
                $revision,
                'playlist',
                $playlistId,
                $this->playlistPayload($playlist),
                true,
            );
            $connection->table('user_playlists')
                ->where('user_id', $userId)
                ->where('id', $playlistId)
                ->delete();

            return $this->operationResult($operationId, 'applied');
        }

        if ($playlist === null) {
            return $this->operationResult($operationId, 'rejected', ['code' => 'playlist_not_found']);
        }

        if ($action === 'playlist.update') {
            $conflict = $this->checkVersion($operation, $playlist, $operationId);
            if ($conflict !== null) {
                return $conflict;
            }

            $changes = [];
            if (array_key_exists('name', $operation)) {
                $name = trim((string) $operation['name']);
                if ($name === '' || mb_strlen($name) > 80) {
                    return $this->operationResult($operationId, 'rejected', ['code' => 'invalid_name']);
                }
                $changes['name'] = $name;
            }
            if (array_key_exists('description', $operation)) {
                $changes['description'] = $this->cleanDescription($operation['description']);
            }
            if ($changes === []) {
                return $this->operationResult($operationId, 'rejected', ['code' => 'empty_update']);
            }

            $changes['version'] = (int) $playlist->version + 1;
            $changes['updated_at'] = now();
            $connection->table('user_playlists')
                ->where('user_id', $userId)
                ->where('id', $playlistId)
                ->update($changes);

            $revision++;
            $fresh = $connection->table('user_playlists')
                ->where('user_id', $userId)
                ->where('id', $playlistId)
                ->first();
            $this->appendPlaylistChange($connection, $userId, $revision, $playlistId, $fresh);

            return $this->operationResult($operationId, 'applied', [
                'version' => (int) $fresh->version,
            ]);
        }

        if ($action === 'playlist.reorder') {
            $conflict = $this->checkVersion($operation, $playlist, $operationId);
            if ($conflict !== null) {
                return $conflict;
            }

            if (! array_key_exists('items', $operation) || ! is_array($operation['items'])) {
                return $this->operationResult($operationId, 'rejected', ['code' => 'invalid_order']);
            }

            $currentItems = $connection->table('user_playlist_items')
                ->where('playlist_id', $playlistId)
                ->orderBy('position')
                ->orderBy('media_type')
                ->orderBy('media_id')
                ->get();

            $positions = [];
            foreach ($currentItems as $item) {
                $positions[$item->media_type.':'.$item->media_id] = $item;
            }

            $ordered = [];
            $seen = [];
            foreach ($operation['items'] ?? [] as $requested) {
                $key = (string) $requested['media_type'].':'.(int) $requested['media_id'];
                if (isset($positions[$key]) && ! isset($seen[$key])) {
                    $ordered[] = $positions[$key];
                    $seen[$key] = true;
                }
            }
            foreach ($currentItems as $item) {
                $key = $item->media_type.':'.$item->media_id;
                if (! isset($seen[$key])) {
                    $ordered[] = $item;
                }
            }

            $revision++;
            foreach ($ordered as $position => $item) {
                if ((int) $item->position === $position) {
                    continue;
                }
                $connection->table('user_playlist_items')
                    ->where('playlist_id', $playlistId)
                    ->where('media_type', $item->media_type)
                    ->where('media_id', $item->media_id)
                    ->update(['position' => $position, 'updated_at' => now()]);
                $item->position = $position;
                $this->appendItemChange($connection, $userId, $revision, $item);
            }

            $connection->table('user_playlists')
                ->where('user_id', $userId)
                ->where('id', $playlistId)
                ->update([
                    'version' => (int) $playlist->version + 1,
                    'updated_at' => now(),
                ]);
            $fresh = $connection->table('user_playlists')
                ->where('user_id', $userId)
                ->where('id', $playlistId)
                ->first();
            $this->appendPlaylistChange($connection, $userId, $revision, $playlistId, $fresh);

            return $this->operationResult($operationId, 'applied', [
                'version' => (int) $fresh->version,
            ]);
        }

        if ($action === 'item.add') {
            $validatedItem = $this->itemFields($operation, $operationId);
            if (isset($validatedItem['error'])) {
                return $validatedItem['error'];
            }

            $alreadyExists = $connection->table('user_playlist_items')
                ->where('playlist_id', $playlistId)
                ->where('media_type', $validatedItem['media_type'])
                ->where('media_id', $validatedItem['media_id'])
                ->exists();
            if ($alreadyExists) {
                return $this->operationResult($operationId, 'applied', ['code' => 'already_exists']);
            }

            $itemCount = $connection->table('user_playlist_items')
                ->where('playlist_id', $playlistId)
                ->count();
            if ($itemCount >= self::MAX_ITEMS) {
                return $this->operationResult($operationId, 'rejected', ['code' => 'playlist_item_limit']);
            }

            $lastPosition = $connection->table('user_playlist_items')
                ->where('playlist_id', $playlistId)
                ->max('position');
            $item = [
                'playlist_id' => $playlistId,
                ...$validatedItem,
                'position' => $lastPosition === null ? 0 : ((int) $lastPosition + 1),
                'added_at' => now()->utc()->toISOString(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $connection->table('user_playlist_items')->insert($item);
            $revision++;
            $itemObject = (object) $item;
            $this->appendItemChange($connection, $userId, $revision, $itemObject);

            return $this->operationResult($operationId, 'applied');
        }

        if ($action === 'item.remove') {
            if (! in_array($operation['media_type'] ?? null, ['movie', 'tv'], true)
                || ! isset($operation['media_id'])
                || (int) $operation['media_id'] < 1) {
                return $this->operationResult($operationId, 'rejected', ['code' => 'invalid_media']);
            }

            $item = $connection->table('user_playlist_items')
                ->where('playlist_id', $playlistId)
                ->where('media_type', $operation['media_type'])
                ->where('media_id', (int) $operation['media_id'])
                ->first();
            if ($item === null) {
                return $this->operationResult($operationId, 'applied', ['code' => 'already_removed']);
            }

            $connection->table('user_playlist_items')
                ->where('playlist_id', $playlistId)
                ->where('media_type', $operation['media_type'])
                ->where('media_id', (int) $operation['media_id'])
                ->delete();
            $revision++;
            $this->appendChange(
                $connection,
                $userId,
                $revision,
                'item',
                $this->itemKey($playlistId, (string) $item->media_type, (int) $item->media_id),
                $this->itemPayload($item),
                true,
            );

            // Compact positions after a removal and publish each changed row
            // in the same revision so other devices see the new order.
            $remainingItems = $connection->table('user_playlist_items')
                ->where('playlist_id', $playlistId)
                ->orderBy('position')
                ->orderBy('media_type')
                ->orderBy('media_id')
                ->get();
            foreach ($remainingItems as $position => $remainingItem) {
                if ((int) $remainingItem->position === $position) {
                    continue;
                }

                $connection->table('user_playlist_items')
                    ->where('playlist_id', $playlistId)
                    ->where('media_type', $remainingItem->media_type)
                    ->where('media_id', $remainingItem->media_id)
                    ->update(['position' => $position, 'updated_at' => now()]);
                $remainingItem->position = $position;
                $this->appendItemChange($connection, $userId, $revision, $remainingItem);
            }

            return $this->operationResult($operationId, 'applied');
        }

        return $this->operationResult($operationId, 'rejected', ['code' => 'unsupported_action']);
    }

    private function checkVersion(array $operation, object $playlist, string $operationId): ?array
    {
        $baseVersion = isset($operation['base_version']) ? (int) $operation['base_version'] : 0;
        if ($baseVersion !== (int) $playlist->version) {
            return $this->operationResult($operationId, 'rejected', [
                'code' => 'version_conflict',
                'current_version' => (int) $playlist->version,
            ]);
        }

        return null;
    }

    private function itemFields(array $operation, string $operationId): array
    {
        if (! in_array($operation['media_type'] ?? null, ['movie', 'tv'], true)
            || ! isset($operation['media_id'])
            || (int) $operation['media_id'] < 1) {
            return ['error' => $this->operationResult($operationId, 'rejected', ['code' => 'invalid_media'])];
        }

        $title = trim((string) ($operation['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 500) {
            return ['error' => $this->operationResult($operationId, 'rejected', ['code' => 'invalid_title'])];
        }

        return [
            'media_type' => (string) $operation['media_type'],
            'media_id' => (int) $operation['media_id'],
            'title' => $title,
            'poster_path' => $operation['poster_path'] ?? null,
            'backdrop_path' => $operation['backdrop_path'] ?? null,
            'vote_average' => isset($operation['vote_average']) ? (float) $operation['vote_average'] : null,
            'release_date' => $operation['release_date'] ?? null,
            'genre_ids' => $this->encode($this->normalizeGenreIds($operation['genre_ids'] ?? [])),
        ];
    }

    private function cleanDescription(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $value = trim((string) $description);
        return $value === '' ? null : $value;
    }

    private function normalizeGenreIds(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $id): bool => $id > 0,
        )));
    }

    private function operationResult(string $operationId, string $status, array $extra = []): array
    {
        return [
            'operation_id' => $operationId,
            'status' => $status,
            ...$extra,
        ];
    }

    private function appendPlaylistChange($connection, int $userId, int $revision, string $playlistId, ?object $row = null): void
    {
        $row ??= $connection->table('user_playlists')
            ->where('user_id', $userId)
            ->where('id', $playlistId)
            ->first();

        if ($row === null) {
            return;
        }

        $this->appendChange(
            $connection,
            $userId,
            $revision,
            'playlist',
            $playlistId,
            $this->playlistPayload($row),
            false,
        );
    }

    private function appendItemChange($connection, int $userId, int $revision, object $row): void
    {
        $this->appendChange(
            $connection,
            $userId,
            $revision,
            'item',
            $this->itemKey((string) $row->playlist_id, (string) $row->media_type, (int) $row->media_id),
            $this->itemPayload($row),
            false,
        );
    }

    private function appendChange(
        $connection,
        int $userId,
        int $revision,
        string $entityType,
        string $entityKey,
        array $payload,
        bool $deleted,
    ): void {
        $connection->table('user_playlist_changes')->insert([
            'user_id' => $userId,
            'revision' => $revision,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'payload' => $this->encode($payload),
            'deleted' => $deleted,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function playlistPayload(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'description' => $row->description,
            'version' => (int) $row->version,
            'created_at' => (string) $row->created_at,
        ];
    }

    private function itemPayload(object $row): array
    {
        $genreIds = $row->genre_ids ?? [];
        if (is_string($genreIds)) {
            $decoded = json_decode($genreIds, true);
            $genreIds = is_array($decoded) ? $decoded : [];
        }

        return [
            'playlist_id' => (string) $row->playlist_id,
            'media_type' => (string) $row->media_type,
            'media_id' => (int) $row->media_id,
            'position' => (int) $row->position,
            'title' => (string) ($row->title ?? ''),
            'poster_path' => $row->poster_path,
            'backdrop_path' => $row->backdrop_path,
            'vote_average' => $row->vote_average === null ? null : (float) $row->vote_average,
            'release_date' => $row->release_date,
            'genre_ids' => $genreIds,
            'added_at' => $row->added_at,
        ];
    }

    private function itemKey(string $playlistId, string $mediaType, int $mediaId): string
    {
        return $playlistId.':'.$mediaType.':'.$mediaId;
    }

    private function changePage(
        $connection,
        int $userId,
        int $sinceRevision,
        int $untilRevision,
        bool $pageRequest,
        ?int $cursor,
    ): array {
        // The client accumulates playlist records from the first response and
        // only appends item rows from subsequent pages. Return the latest
        // changed state for each playlist in the first response (at most 200),
        // while item snapshots use the numeric cursor for bounded paging.
        $playlistQuery = $connection->table('user_playlist_changes as c')
            ->where('c.user_id', $userId)
            ->where('c.entity_type', 'playlist')
            ->where('c.revision', '<=', $untilRevision);

        if ($sinceRevision > 0) {
            $playlistQuery->where('c.revision', '>', $sinceRevision);
        }

        $playlistQuery->whereRaw(
            'c.id = (SELECT MAX(c2.id) FROM user_playlist_changes c2 '.
            'WHERE c2.user_id = c.user_id AND c2.entity_type = c.entity_type '.
            'AND c2.entity_key = c.entity_key AND c2.revision > ? AND c2.revision <= ?)',
            [$sinceRevision, $untilRevision],
        );

        $playlists = $pageRequest
            ? collect()
            : $playlistQuery->orderBy('c.id')->get();

        $itemQuery = $connection->table('user_playlist_changes as c')
            ->where('c.user_id', $userId)
            ->where('c.entity_type', 'item')
            ->where('c.revision', '<=', $untilRevision);

        if ($sinceRevision > 0) {
            $itemQuery->where('c.revision', '>', $sinceRevision);
        }

        $itemQuery->whereRaw(
            'c.id = (SELECT MAX(c2.id) FROM user_playlist_changes c2 '.
            'WHERE c2.user_id = c.user_id AND c2.entity_type = c.entity_type '.
            'AND c2.entity_key = c.entity_key AND c2.revision > ? AND c2.revision <= ?)',
            [$sinceRevision, $untilRevision],
        );

        if ($cursor !== null) {
            $itemQuery->where('c.id', '>', $cursor);
        }

        return [
            'playlists' => $playlists,
            'items' => $itemQuery->orderBy('c.id')->limit(self::PAGE_SIZE + 1)->get(),
        ];
    }

    private function fingerprint(array $operation): string
    {
        $canonical = $this->canonicalize($operation);
        return hash('sha256', $this->encode($canonical));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @throws JsonException
     */
    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
