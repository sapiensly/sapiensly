<?php

namespace App\Ai\Tools\Capabilities;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Services\Ai\AiCapabilities;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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

        try {
            $pending = Transcription::fromStorage($attachment->storage_path, $attachment->disk);
            if (is_string(($lang = $request->toArray()['language'] ?? null)) && $lang !== '') {
                $pending = $pending->language($lang);
            }

            $response = $pending->generate($handler['provider'], $handler['model']);

            return "Transcript ({$handler['model']}):\n".$response->text;
        } catch (\Throwable $e) {
            return 'Error transcribing the audio: '.$e->getMessage();
        }
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
