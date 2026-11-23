<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Storage\PrivateObjectStorage;
use App\Http\Controllers\Controller;
use App\Jobs\DeletePrivateObjectJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class UserMediaController extends Controller
{
    public function __construct(private readonly PrivateObjectStorage $storage) {}

    public function store(Request $request): JsonResponse
    {
        $purpose = (string) $request->input('purpose');
        $fileRules = $purpose === 'avatar'
            ? [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'dimensions:max_width=4096,max_height=4096',
                'max:5120',
            ]
            : [
                'required',
                'file',
                'mimes:pdf,txt',
                'mimetypes:application/pdf,text/plain',
                'max:10240',
            ];

        $request->validate([
            'purpose' => ['required', 'string', 'in:avatar,attachment'],
            'file' => $fileRules,
        ]);

        $file = $request->file('file');
        $mimeType = (string) $file->getMimeType();
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            default => throw new \RuntimeException('The validated media type is not supported.'),
        };

        $mediaId = (string) Str::uuid();
        $objectKey = sprintf(
            'users/%d/media/%s.%s',
            (int) $request->user()->getKey(),
            $mediaId,
            $extension,
        );
        $contents = $file->get();
        $originalName = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?? 'upload';
        $originalName = trim(mb_substr($originalName, 0, 180));
        $originalName = $originalName !== '' ? $originalName : 'upload';

        $this->storage->put($objectKey, $contents);

        try {
            $media = DB::transaction(function () use ($request, $purpose, $objectKey, $originalName, $mimeType, $contents, $mediaId) {
                return $request->user()->media()->create([
                    'id' => $mediaId,
                    'purpose' => $purpose,
                    'object_key' => $objectKey,
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                    'size_bytes' => strlen($contents),
                    'checksum_sha256' => hash('sha256', $contents),
                ]);
            });
        } catch (Throwable $exception) {
            try {
                $this->storage->delete($objectKey);
            } catch (Throwable $cleanupException) {
                Log::warning('RAVEN23 private media upload compensation failed', [
                    'media_id' => $mediaId,
                    'exception_type' => get_class($cleanupException),
                ]);
            }

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'media' => $this->serializeMedia($media),
        ], 201);
    }

    public function show(Request $request, string $mediaId): Response|JsonResponse
    {
        $media = $request->user()->media()->whereKey($mediaId)->firstOrFail();
        $contents = $this->storage->get($media->object_key);

        if ($contents === null) {
            return response()->json([
                'success' => false,
                'message' => 'The requested media file was not found.',
            ], 404);
        }

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $media->original_name) ?: 'download';
        $disposition = $media->purpose === 'avatar' ? 'inline' : 'attachment';

        return response($contents)
            ->header('Content-Type', $media->mime_type)
            ->header('Content-Length', (string) strlen($contents))
            ->header('Content-Disposition', $disposition.'; filename="'.$filename.'"')
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function destroy(Request $request, string $mediaId): JsonResponse
    {
        DB::transaction(function () use ($request, $mediaId): void {
            $media = $request->user()->media()
                ->whereKey($mediaId)
                ->lockForUpdate()
                ->firstOrFail();

            DeletePrivateObjectJob::dispatch($media->object_key);
            $media->delete();
        });

        return response()->json([
            'success' => true,
            'deletion_queued' => true,
        ], 202);
    }

    private function serializeMedia($media): array
    {
        return [
            'id' => (string) $media->getKey(),
            'purpose' => $media->purpose,
            'original_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'size_bytes' => (int) $media->size_bytes,
            'checksum_sha256' => $media->checksum_sha256,
            'created_at' => optional($media->created_at)->toISOString(),
            'download_url' => route('api.v1.user.media.show', ['media' => $media->getKey()]),
        ];
    }
}
