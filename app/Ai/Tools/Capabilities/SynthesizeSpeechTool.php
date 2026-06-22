<?php

namespace App\Ai\Tools\Capabilities;

use App\Models\User;
use App\Services\Ai\AiCapabilities;
use App\Services\CloudProviderService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Audio;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request as AiRequest;
use Stringable;

/**
 * Hands off text-to-speech to the model configured under admin AI > Defaults →
 * "Speech generation". Stores the audio on the caller's tenant disk.
 */
class SynthesizeSpeechTool implements ToolContract
{
    public function __construct(
        private User $user,
        private AiCapabilities $capabilities,
        private CloudProviderService $cloud,
    ) {}

    public function description(): Stringable|string
    {
        return 'Synthesize spoken audio from text using the workspace\'s configured speech model. Use when the user asks to read something aloud or produce a voice/audio version. Returns the stored audio location.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The text to speak.')->required(),
            'voice' => $schema->string()->description('Optional voice id or name supported by the provider.'),
        ];
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $args = $request->toArray();
        $text = trim((string) ($args['text'] ?? ''));
        if ($text === '') {
            return 'Error: provide the text to synthesize.';
        }

        $handler = $this->capabilities->resolve('speech_generation');
        if ($handler === null) {
            return 'Error: no speech-generation model is configured. Set one in admin AI > Defaults → Speech generation.';
        }

        if ($handler['driver'] === 'openrouter') {
            return 'Error: text-to-speech is not available through OpenRouter (it has no audio-output endpoint). Configure a direct provider (OpenAI or ElevenLabs) in admin AI > Defaults → Speech generation.';
        }

        try {
            $pending = Audio::of($text);
            if (is_string($args['voice'] ?? null) && $args['voice'] !== '') {
                $pending = $pending->voice($args['voice']);
            }

            $audio = $pending->generate($handler['provider'], $handler['model']);

            $disk = $this->cloud->diskForOwnerOrFallback($this->user->organization_id, $this->user->id);
            $path = 'ai/generated/audio/'.Str::ulid().'.mp3';
            $disk->put($path, (string) $audio);

            return "Speech synthesized with {$handler['model']} and stored at: ".$this->urlOrPath($disk, $path);
        } catch (\Throwable $e) {
            return 'Error synthesizing speech: '.$e->getMessage();
        }
    }

    private function urlOrPath(Filesystem $disk, string $path): string
    {
        try {
            return $disk->url($path);
        } catch (\Throwable) {
            return $path;
        }
    }
}
