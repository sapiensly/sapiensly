<?php

namespace App\Http\Controllers\Api\Widget;

use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\UploadWidgetAttachmentRequest;
use App\Models\Chatbot;
use App\Models\WidgetAttachment;
use App\Models\WidgetConversation;
use App\Services\ConversationAttachmentService;
use App\Services\Storage\TenantStorage;
use App\Support\Storage\TenantPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Upload/serve for files a visitor attaches to a widget conversation. Bytes
 * land on the chatbot owner's cloud disk (TenantStorage → tenant provider →
 * global S3, never local). Documents have their text extracted on upload so the
 * bot flow + agents can use the content. Mirrors {@see ChatAttachmentController}.
 */
class AttachmentController extends Controller
{
    public function __construct(
        private readonly TenantStorage $tenantStorage,
        private readonly ConversationAttachmentService $attachments,
    ) {}

    public function store(UploadWidgetAttachmentRequest $request, string $conversation): JsonResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        $widgetConversation = $this->resolveConversation($chatbot, $conversation);

        $uploaded = $request->file('file');
        if ($uploaded === null) {
            throw new HttpException(400, 'No file provided.');
        }

        // Resolve the cloud disk up front — throws (→ 503) if none is wired.
        $diskName = $this->tenantStorage->diskNameForOwner(
            $chatbot->organization_id,
            $chatbot->user_id,
        );

        $mime = $uploaded->getClientMimeType();

        $attachment = new WidgetAttachment([
            'widget_conversation_id' => $widgetConversation->id,
            'organization_id' => $chatbot->organization_id,
            'user_id' => $chatbot->user_id,
            'disk' => $diskName,
            'original_name' => $uploaded->getClientOriginalName(),
            'mime' => $mime,
            'size_bytes' => $uploaded->getSize(),
            'kind' => $this->attachments->kindForMime($mime),
            'extracted_text' => $this->attachments->extractText($uploaded->getRealPath(), $mime),
        ]);
        $attachment->id = WidgetAttachment::generatePrefixedUlid();

        $ext = preg_replace('/[^a-zA-Z0-9]/', '', (string) pathinfo($uploaded->getClientOriginalName(), PATHINFO_EXTENSION));
        $relativePath = TenantPath::scope(
            $chatbot->organization_id,
            $chatbot->user_id,
            "widget_uploads/{$widgetConversation->id}/{$attachment->id}".($ext !== '' ? '.'.strtolower($ext) : ''),
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
            'kind' => $attachment->kind,
            'size_bytes' => $attachment->size_bytes,
            'url' => route('widget.conversations.attachments.show', [
                'conversation' => $widgetConversation->id,
                'attachment' => $attachment->id,
            ]),
        ], 201);
    }

    public function show(Request $request, string $conversation, string $attachment): StreamedResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        $widgetConversation = $this->resolveConversation($chatbot, $conversation);

        $row = WidgetAttachment::where('widget_conversation_id', $widgetConversation->id)
            ->where('id', $attachment)
            ->first();

        if (! $row) {
            throw new NotFoundHttpException('Attachment not found.');
        }

        $disk = $this->tenantStorage->diskFromName($row->disk);
        if (! $disk->exists($row->storage_path)) {
            throw new NotFoundHttpException('Attachment is missing on disk.');
        }

        return $disk->response(
            $row->storage_path,
            $row->original_name,
            ['Content-Type' => $row->mime ?: 'application/octet-stream'],
        );
    }

    private function resolveConversation(Chatbot $chatbot, string $conversation): WidgetConversation
    {
        $widgetConversation = WidgetConversation::where('chatbot_id', $chatbot->id)
            ->where('id', $conversation)
            ->first();

        if (! $widgetConversation) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        return $widgetConversation;
    }
}
