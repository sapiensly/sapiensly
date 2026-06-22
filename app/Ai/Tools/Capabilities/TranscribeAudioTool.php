<?php

namespace App\Ai\Tools\Capabilities;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Ai\AiCapabilities;
use App\Services\Ai\OpenRouterClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request as AiRequest;
use Laravel\Ai\Transcription;
use Stringable;

/**
 * Transcribes an audio attachment from the current turn using the model
 * configured under admin AI > Defaults → "Audio recognition".
 */
class TranscribeAudioTool implements ToolContract
{
    public function __construct(
        private ?ChatMessage $placeholder,
        private AiCapabilities $capabilities,
        private User $user,
        private OpenRouterClient $openRouter,
    ) {}

    public function description(): Stringable|string
    {
        return 'Transcribe the audio the user attached in this conversation to text, using the workspace\'s configured speech-to-text model. Use when the user attaches audio and asks what was said or to transcribe it.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'language' => $schema->string()->description('Optional ISO language hint (e.g. "en", "es").'),
        ];
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $attachment = $this->latestAudioAttachment();
        if ($attachment === null) {
            return 'Error: no audio attachment was found in this conversation. Ask the user to attach an audio file.';
        }

        $handler = $this->capabilities->resolve('audio_recognition');
        if ($handler === null) {
            return 'Error: no audio-recognition model is configured. Set one in admin AI > Defaults → Audio recognition.';
        }

        $lang = $request->toArray()['language'] ?? null;

        try {
            if ($handler['driver'] === 'openrouter') {
                return $this->transcribeViaOpenRouter($attachment, $handler['model'], is_string($lang) ? $lang : null);
            }

            $pending = Transcription::fromStorage($attachment->storage_path, $attachment->disk);
            if (is_string($lang) && $lang !== '') {
                $pending = $pending->language($lang);
            }

            $response = $pending->generate($handler['provider'], $handler['model']);

            return "Transcript ({$handler['model']}):\n".$response->text;
        } catch (\Throwable $e) {
            return 'Error transcribing the audio: '.$e->getMessage();
        }
    }

    /**
     * Transcribe via OpenRouter's multimodal chat completions: the audio rides in
     * an `input_audio` block (base64 + format) and we ask the model to transcribe.
     */
    private function transcribeViaOpenRouter(ChatAttachment $attachment, string $model, ?string $lang): string
    {
        $bytes = Storage::disk($attachment->disk)->get($attachment->storage_path);
        $format = $this->audioFormat((string) $attachment->mime, $attachment->storage_path);

        $instruction = 'Transcribe the attached audio verbatim. Output only the transcript text.';
        if ($lang !== null && $lang !== '') {
            $instruction .= " The audio language is {$lang}.";
        }

        $response = $this->openRouter->chat($this->user, $model, [
            OpenRouterClient::textBlock($instruction),
            OpenRouterClient::audioBlock(base64_encode((string) $bytes), $format),
        ]);

        return "Transcript ({$model}):\n".OpenRouterClient::text($response);
    }

    /** OpenRouter expects a short format token (e.g. "mp3", "wav") rather than a mime. */
    private function audioFormat(string $mime, string $path): string
    {
        return match (true) {
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'flac') => 'flac',
            str_contains($mime, 'm4a'), str_contains($mime, 'mp4') => 'm4a',
            default => strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'mp3',
        };
    }

    private function latestAudioAttachment(): ?ChatAttachment
    {
        if ($this->placeholder === null) {
            return null;
        }

        $userMessage = $this->placeholder->chat->messages()
            ->where('role', 'user')
            ->where('id', '!=', $this->placeholder->id)
            ->orderByDesc('created_at')
            ->first();

        return $userMessage?->attachments->first(fn (ChatAttachment $a) => $a->isAudio());
    }
}
