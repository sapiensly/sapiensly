<?php

namespace App\Ai\Tools\Capabilities;

use App\Models\User;
use App\Services\Ai\AiCapabilities;
use App\Services\Ai\OpenRouterClient;
use App\Services\CloudProviderService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Audio;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Enums\Lab;
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
        private OpenRouterClient $openRouter,
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
            'instructions' => $schema->string()->description('Optional style/language/accent coaching, e.g. "Mexican Spanish, warm and casual".'),
        ];
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $args = $request->toArray();
        $text = trim((string) ($args['text'] ?? ''));
        if ($text === '') {
            return 'Error: provide the text to synthesize.';
        }
        $voice = is_string($args['voice'] ?? null) ? (string) $args['voice'] : '';
        $instructions = is_string($args['instructions'] ?? null) ? (string) $args['instructions'] : '';

        $handler = $this->capabilities->resolve('speech_generation');
        if ($handler === null) {
            return 'Error: no speech-generation model is configured. Set one in admin AI > Defaults → Speech generation.';
        }

        try {
            $audio = $this->synthesize($handler, $text, $voice, $instructions);
            if ($audio === null) {
                return "Error: the model returned no audio. Pick a model with audio output for {$handler['model']}.";
            }

            $disk = $this->cloud->diskForOwnerOrFallback($this->user->organization_id, $this->user->id);
            $path = 'ai/generated/audio/'.Str::ulid().'.'.$audio['ext'];
            $disk->put($path, $audio['bytes']);

            return "Speech synthesized with {$handler['model']} and stored at: ".$this->urlOrPath($disk, $path);
        } catch (\Throwable $e) {
            return 'Error synthesizing speech: '.$e->getMessage();
        }
    }

    /**
     * Generate audio via OpenRouter (streamed chat completions → WAV) or the SDK
     * Audio class (mp3), depending on the configured provider.
     *
     * @param  array{model: string, driver: string, provider: Lab}  $handler
     * @return array{bytes: string, ext: string}|null
     */
    private function synthesize(array $handler, string $text, string $voice, string $instructions): ?array
    {
        if ($handler['driver'] === 'openrouter') {
            $prompt = $instructions !== '' ? $instructions."\n\n".$text : $text;
            $audio = $this->openRouter->audio($this->user, $handler['model'], [
                OpenRouterClient::textBlock($prompt),
            ], ['voice' => $voice !== '' ? $voice : 'alloy']);

            return $audio !== null ? ['bytes' => $audio['bytes'], 'ext' => 'wav'] : null;
        }

        $pending = Audio::of($text);
        if ($voice !== '') {
            $pending = $pending->voice($voice);
        }
        if ($instructions !== '') {
            $pending = $pending->instructions($instructions);
        }

        return ['bytes' => (string) $pending->generate($handler['provider'], $handler['model']), 'ext' => 'mp3'];
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
