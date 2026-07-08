<?php

namespace App\Services\Apps;

use App\Ai\ChatAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Support\Apps\AppNaming;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

/**
 * Names an app from its first builder prompt using the short-summary model (the
 * same cheap model that titles chats). Kept tight (a few-word name, low timeout)
 * so it fits inside the first message's request; any failure or empty response
 * degrades to the deterministic {@see AppNaming::nameFromPrompt} heuristic, so a
 * slow/unavailable model never blocks naming.
 */
class AppNamer
{
    /** Seconds — well under the builder's client-side send timeout. */
    private const TIMEOUT = 7;

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
    ) {}

    public function nameFromPrompt(string $prompt, ?User $user): string
    {
        $fallback = AppNaming::nameFromPrompt($prompt) ?? AppNaming::UNTITLED;
        $clean = trim((string) preg_replace('/\s+/', ' ', strip_tags($prompt)));
        if ($clean === '') {
            return $fallback;
        }

        try {
            $model = $this->aiDefaults->model('summary_short');
            $agent = new ChatAgent(
                instructions: 'Generas nombres MUY cortos para una app o dashboard, en el idioma del pedido. Responde SOLO con el nombre de 3 a 6 palabras — sin comillas, sin punto final, sin prefijos ni explicación.',
                messages: [],
                tools: [],
            );
            $response = $agent->prompt(
                Str::limit($clean, 1000),
                provider: $this->resolveProvider($model, $user),
                model: $model,
                timeout: self::TIMEOUT,
            );
            $name = $this->normalize((string) ($response->text ?? ''));

            return $name !== '' ? $name : $fallback;
        } catch (\Throwable $e) {
            Log::warning('App naming: short-summary model failed', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    private function resolveProvider(string $model, ?User $user): Lab
    {
        if ($user === null) {
            return Lab::Anthropic;
        }
        $this->providers->applyRuntimeConfig($user);

        return $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;
    }

    /** Strip tags, wrapping quotes and trailing punctuation; cap at 60 chars. */
    private function normalize(string $text): string
    {
        $name = trim(strip_tags($text));
        $name = trim($name, " \t\n\r\0\x0B\"'`.");

        return Str::limit($name, 60, '');
    }
}
