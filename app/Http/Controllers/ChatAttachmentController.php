<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\UploadChatAttachmentRequest;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Services\Storage\TenantStorage;
use App\Support\Storage\TenantPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Upload/serve for chat attachments. Bytes always land on the configured
 * cloud disk (TenantStorage → global S3); we never fall back to local so
 * files survive deploys/scale events.
 */
class ChatAttachmentController extends Controller
{
    public function __construct(private readonly TenantStorage $tenantStorage) {}

    public function upload(UploadChatAttachmentRequest $request, Chat $chat): JsonResponse
    {
        $uploaded = $request->file('file');
        if ($uploaded === null) {
            throw new HttpException(400, 'No file provided.');
        }

        // Resolve the cloud disk up front — throws (→ 503) if no S3 is wired.
        // Owner-aware: prefers the chat owner's org/personal CloudProvider.
        $diskName = $this->tenantStorage->diskNameForOwner(
            $chat->organization_id,
            $chat->user_id,
        );

        $attachment = new ChatAttachment([
            'chat_id' => $chat->id,
            'user_id' => $request->user()->id,
            'organization_id' => $chat->organization_id,
            'disk' => $diskName,
            'original_name' => $uploaded->getClientOriginalName(),
            'mime' => $uploaded->getClientMimeType(),
            'size_bytes' => $uploaded->getSize(),
        ]);
        $attachment->id = ChatAttachment::generatePrefixedUlid();

        $ext = preg_replace('/[^a-zA-Z0-9]/', '', (string) pathinfo($uploaded->getClientOriginalName(), PATHINFO_EXTENSION));
        $relativePath = TenantPath::scope(
            $chat->organization_id,
            $chat->user_id,
            "chat_uploads/{$chat->id}/{$attachment->id}".($ext !== '' ? '.'.strtolower($ext) : ''),
        );

        Storage::disk($diskName)->putFileAs(
            dirname($relativePath),
            $uploaded,
            basename($relativePath),
        );

        $attachment->storage_path = $relativePath;
        $attachment->save();

        return new JsonResponse([
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size_bytes' => $attachment->size_bytes,
            'url' => route('chat.attachments.show', ['chat' => $chat->id, 'attachment' => $attachment->id]),
        ], 201);
    }

    public function show(Request $request, Chat $chat, ChatAttachment $attachment): StreamedResponse
    {
        if ($chat->user_id !== $request->user()->id || $attachment->chat_id !== $chat->id) {
            throw new NotFoundHttpException('Attachment not found.');
        }

        $disk = $this->tenantStorage->diskFromName($attachment->disk);
        if (! $disk->exists($attachment->storage_path)) {
            throw new NotFoundHttpException('Attachment is missing on disk.');
        }

        return $disk->response(
            $attachment->storage_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime ?: 'application/octet-stream'],
        );
    }
}
