<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Services\Storage\TenantStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;

/**
 * Channel-agnostic helpers for chatbot conversation attachments: classifying a
 * file, extracting its text, and turning a stored file into the shape the
 * bot-flow runtime / agents (and the Laravel AI SDK) consume. Shared by the web
 * widget and WhatsApp so both surface uploaded files to the flow identically.
 */
class ConversationAttachmentService
{
    /** Cap stored extracted text so a huge PDF can't bloat a row or a prompt. */
    public const MAX_EXTRACTED_CHARS = 200_000;

    public function __construct(
        private DocumentParserService $parser,
        private TenantStorage $tenantStorage,
    ) {}

    /** Coarse bucket used for routing + SDK conversion. */
    public function kindForMime(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'document',
        };
    }

    /**
     * Extract readable text from a locally-accessible file. Returns null for
     * images/audio/unparseable types or on failure — extraction is best-effort
     * and must never break an upload.
     */
    public function extractText(string $localPath, string $mime): ?string
    {
        $type = DocumentType::fromMime($mime);
        if ($type === null || in_array($type, [DocumentType::Url, DocumentType::Artifact], true)) {
            return null;
        }

        try {
            $text = trim($this->parser->parseFile($localPath, $type));
        } catch (\Throwable $e) {
            Log::warning('Conversation attachment: text extraction failed', [
                'mime' => $mime,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $text === '' ? null : Str::limit($text, self::MAX_EXTRACTED_CHARS, '');
    }

    /**
     * Normalized descriptor consumed by the bot-flow runtime, condition routing
     * and agent context — independent of which channel stored the file.
     *
     * @return array{id: string, original_name: string, mime: string, kind: string, disk: string, storage_path: string, extracted_text: ?string}
     */
    public function descriptor(
        string $id,
        string $originalName,
        string $mime,
        string $disk,
        string $storagePath,
        ?string $extractedText,
    ): array {
        return [
            'id' => $id,
            'original_name' => $originalName,
            'mime' => $mime,
            'kind' => $this->kindForMime($mime),
            'disk' => $disk,
            'storage_path' => $storagePath,
            'extracted_text' => $extractedText,
        ];
    }

    /**
     * Turn a descriptor into a Laravel AI SDK stored file, ensuring the
     * (possibly per-tenant) disk is registered in this process first so it
     * resolves on read.
     *
     * @param  array{mime: string, disk: string, storage_path: string}  $descriptor
     */
    public function toStoredFile(array $descriptor): StoredImage|StoredAudio|StoredDocument
    {
        $disk = $this->tenantStorage->ensureRegistered($descriptor['disk']);
        $path = $descriptor['storage_path'];

        return match ($this->kindForMime($descriptor['mime'])) {
            'image' => new StoredImage($path, $disk),
            'audio' => new StoredAudio($path, $disk),
            default => new StoredDocument($path, $disk),
        };
    }

    /**
     * A short, model-facing context block summarizing the document attachments
     * in a turn (name + extracted text). Images are omitted — they go to the
     * model as StoredImage. Returns '' when there's nothing to add.
     *
     * @param  array<int, array{original_name: string, kind: string, extracted_text: ?string}>  $descriptors
     */
    public function documentContext(array $descriptors): string
    {
        $blocks = [];
        foreach ($descriptors as $d) {
            if (($d['kind'] ?? null) === 'image') {
                continue;
            }
            $text = trim((string) ($d['extracted_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $blocks[] = "--- File: {$d['original_name']} ---\n{$text}";
        }

        if ($blocks === []) {
            return '';
        }

        return "The user attached the following file(s). Use their content to answer:\n\n".implode("\n\n", $blocks);
    }
}
