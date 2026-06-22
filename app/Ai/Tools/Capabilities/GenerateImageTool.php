<?php

namespace App\Ai\Tools\Capabilities;

use App\Models\User;
use App\Services\Ai\AiCapabilities;
use App\Services\Ai\OpenRouterClient;
use App\Services\CloudProviderService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Image;
use Laravel\Ai\Tools\Request as AiRequest;
use Stringable;

/**
 * Hands off image generation to the model configured under admin AI > Defaults →
 * "Image generation". Stores the result on the caller's tenant disk and returns
 * a reference the agent can surface to the user.
 */
class GenerateImageTool implements ToolContract
{
    public function __construct(
        private User $user,
        private AiCapabilities $capabilities,
        private CloudProviderService $cloud,
        private OpenRouterClient $openRouter,
    ) {}

    public function description(): Stringable|string
    {
        return 'Generate an image from a text description using the workspace\'s configured image model. Use when the user asks to create, draw, or design an image. Returns the stored image location.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()->description('A detailed description of the image to generate.')->required(),
            'aspect' => $schema->string()->enum(['square', 'portrait', 'landscape'])->description('Aspect ratio (default square).'),
            'quality' => $schema->string()->enum(['low', 'medium', 'high'])->description('Render quality (default medium).'),
        ];
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $args = $request->toArray();
        $prompt = trim((string) ($args['prompt'] ?? ''));
        if ($prompt === '') {
            return 'Error: provide a prompt describing the image.';
        }

        $handler = $this->capabilities->resolve('image_generation');
        if ($handler === null) {
            return 'Error: no image-generation model is configured. Set one in admin AI > Defaults → Image generation.';
        }

        try {
            $disk = $this->cloud->diskForOwnerOrFallback($this->user->organization_id, $this->user->id);
            $path = 'ai/generated/images/'.Str::ulid().'.png';

            if ($handler['driver'] === 'openrouter') {
                // OpenRouter returns generated images via chat completions with
                // modalities=["image","text"]; the image is a base64 data URL.
                $aspect = (string) ($args['aspect'] ?? 'square');
                $promptText = $aspect === 'square' ? $prompt : "{$prompt}\n(Aspect ratio: {$aspect}.)";

                $response = $this->openRouter->chat(
                    $this->user,
                    $handler['model'],
                    [OpenRouterClient::textBlock($promptText)],
                    ['modalities' => ['image', 'text']],
                );

                $dataUrl = OpenRouterClient::firstImageDataUrl($response);
                if ($dataUrl === null) {
                    return 'Error: the model returned no image. Pick an OpenRouter model with image output.';
                }
                $disk->put($path, $this->decodeDataUrl($dataUrl));
            } else {
                $pending = Image::of($prompt);
                $pending = match ($args['aspect'] ?? 'square') {
                    'portrait' => $pending->portrait(),
                    'landscape' => $pending->landscape(),
                    default => $pending->square(),
                };
                if (is_string($args['quality'] ?? null)) {
                    $pending = $pending->quality($args['quality']);
                }

                $image = $pending->generate($handler['provider'], $handler['model']);
                $disk->put($path, (string) $image);
            }

            return "Image generated with {$handler['model']} and stored at: ".$this->urlOrPath($disk, $path);
        } catch (\Throwable $e) {
            return 'Error generating the image: '.$e->getMessage();
        }
    }

    /** Decode a `data:...;base64,XXXX` URL to raw bytes (tolerates a bare base64 string). */
    private function decodeDataUrl(string $dataUrl): string
    {
        $comma = strpos($dataUrl, ',');
        $base64 = $comma !== false ? substr($dataUrl, $comma + 1) : $dataUrl;

        return base64_decode($base64) ?: '';
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
