<?php

namespace App\Ai\Tools\Capabilities;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Services\Ai\AiCapabilities;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Files;
use Laravel\Ai\Tools\Request as AiRequest;
use Stringable;

/**
 * Extracts the text from a PDF or image attachment in the current turn by handing
 * it to the vision model configured under admin AI > Defaults → "OCR pdf" / "OCR
 * image". Fills the gap where smalot/pdfparser yields nothing (scanned PDFs).
 */
class OcrDocumentTool implements ToolContract
{
    private const INSTRUCTIONS = 'You are an OCR engine. Extract ALL text from the attached document or image verbatim, preserving reading order and structure (headings, lists, tables as best you can). Output only the extracted text, no commentary.';

    public function __construct(
        private ?ChatMessage $placeholder,
        private AiCapabilities $capabilities,
    ) {}

    public function description(): Stringable|string
    {
        return 'Extract the text from a PDF or image the user attached in this conversation, using the workspace\'s configured OCR/vision model. Use for scanned PDFs or images where the text is not selectable.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $attachment = $this->latestDocumentAttachment();
        if ($attachment === null) {
            return 'Error: no PDF or image attachment was found in this conversation. Ask the user to attach one.';
        }

        $isImage = $attachment->isImage();
        $handler = $this->capabilities->resolve($isImage ? 'ocr_image' : 'ocr_pdf')
            ?? $this->capabilities->resolve($isImage ? 'ocr_pdf' : 'ocr_image');

        if ($handler === null) {
            return 'Error: no OCR/vision model is configured. Set one in admin AI > Defaults → OCR pdf / OCR image.';
        }

        try {
            $file = $isImage
                ? Files\Image::fromStorage($attachment->storage_path, $attachment->disk)
                : Files\Document::fromStorage($attachment->storage_path, $attachment->disk);

            $response = (new AnonymousAgent(self::INSTRUCTIONS, [], []))->prompt(
                'Extract all text from the attached file.',
                attachments: [$file],
                provider: $handler['provider'],
                model: $handler['model'],
            );

            return "Extracted text ({$handler['model']}):\n".$response->text;
        } catch (\Throwable $e) {
            return 'Error extracting text: '.$e->getMessage();
        }
    }

    private function latestDocumentAttachment(): ?ChatAttachment
    {
        if ($this->placeholder === null) {
            return null;
        }

        $userMessage = $this->placeholder->chat->messages()
            ->where('role', 'user')
            ->where('id', '!=', $this->placeholder->id)
            ->orderByDesc('created_at')
            ->first();

        // Prefer a non-audio attachment (PDF or image); OCR doesn't apply to audio.
        return $userMessage?->attachments->first(
            fn (ChatAttachment $a) => ! $a->isAudio(),
        );
    }
}
