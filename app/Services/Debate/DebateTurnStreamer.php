<?php

namespace App\Services\Debate;

use App\Ai\DebateAgent;
use App\Events\Debate\DebateTurnChunk;
use App\Events\Debate\DebateTurnComplete;
use App\Events\Debate\DebateTurnError;
use App\Models\DebateTurn;
use App\Services\AiProviderService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;

/**
 * Streams a single debate turn (a participant argument or the moderator
 * synthesis) from the configured provider, broadcasting each token over Reverb.
 * Adapted from ChatAiService::streamMessage but scoped to one turn — history is
 * baked into the prompt by the orchestrator, so this only drives the stream.
 */
class DebateTurnStreamer
{
    /** Cache key prefix for the cooperative stop flag the worker polls. */
    public const STOP_CACHE_PREFIX = 'debate-stop:';

    public function __construct(
        private readonly AiProviderService $providers,
    ) {}

    /**
     * Stream one turn to completion. Returns the refreshed turn. When the
     * participant is backed by an agent, $tools carries the agent's SDK tools so
     * the model can call them mid-stream.
     *
     * @param  array<int, object>  $tools
     */
    public function stream(DebateTurn $turn, string $instructions, string $prompt, string $model, array $tools = []): DebateTurn
    {
        set_time_limit(0);

        $debate = $turn->debate;
        $user = $debate->user;
        $buffer = '';
        $startedAt = microtime(true);

        try {
            $provider = Lab::Anthropic;
            if ($user !== null) {
                $this->providers->applyRuntimeConfig($user);
                $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;
            }

            $agent = new DebateAgent(
                instructions: $instructions,
                messages: [],
                tools: $tools,
            );

            $turn->update(['status' => 'streaming', 'model' => $model]);

            Log::info('Debate turn streaming', [
                'debate_id' => $debate->id,
                'turn_id' => $turn->id,
                'role' => $turn->role,
                'model' => $model,
            ]);

            $stream = $agent->stream($prompt, provider: $provider, model: $model);

            $stopKey = self::STOP_CACHE_PREFIX.$debate->id;
            $sawText = false;
            $deltaCount = 0;
            foreach ($stream as $event) {
                if (($deltaCount % 16) === 0 && Cache::get($stopKey)) {
                    break;
                }

                if ($event instanceof TextStart) {
                    if ($sawText && $buffer !== '' && ! str_ends_with($buffer, "\n")) {
                        $buffer .= "\n\n";
                        $this->safeBroadcast(fn () => DebateTurnChunk::dispatch($debate->id, $turn->id, "\n\n"));
                    }

                    continue;
                }

                if ($event instanceof TextDelta && $event->delta !== '') {
                    $sawText = true;
                    $deltaCount++;
                    $buffer .= $event->delta;
                    $this->safeBroadcast(fn () => DebateTurnChunk::dispatch($debate->id, $turn->id, $event->delta));
                }
            }

            $turn->update([
                'content' => $buffer,
                'model' => $model,
                'stance_summary' => self::parseStance($buffer),
                'status' => 'complete',
            ]);

            $this->safeBroadcast(fn () => DebateTurnComplete::dispatch($turn->refresh()));

            Log::info('Debate turn finished', [
                'debate_id' => $debate->id,
                'turn_id' => $turn->id,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'response_length' => strlen($buffer),
            ]);

            return $turn;
        } catch (\Throwable $e) {
            $providerError = null;
            if ($e instanceof RequestException && $e->response !== null) {
                $body = json_decode($e->response->body(), true);
                $providerError = $body['error']['message'] ?? mb_substr($e->response->body(), 0, 500);
            }

            $errMsg = mb_substr($providerError ?? $e->getMessage(), 0, 1500);

            Log::error('Debate turn stream failed', [
                'debate_id' => $debate->id,
                'turn_id' => $turn->id,
                'error_class' => $e::class,
                'error' => $e->getMessage(),
                'provider_error' => $providerError,
                'model' => $model,
            ]);

            $turn->update([
                'content' => $buffer !== '' ? $buffer : null,
                'status' => 'error',
                'error' => $errMsg,
            ]);

            $this->safeBroadcast(fn () => DebateTurnError::dispatch($debate->id, $turn->id, $errMsg));

            return $turn;
        }
    }

    /**
     * Extract the one-line `**Position:**` a debater is asked to lead with, so
     * the UI can show a stance chip. Falls back to the first sentence.
     */
    public static function parseStance(string $content): ?string
    {
        if (trim($content) === '') {
            return null;
        }

        // Match the English marker plus its common translations (es/pt/fr/it/de),
        // in case a model localizes the label despite instructions.
        if (preg_match('/\*{0,2}\s*(?:position|postura|posici[oó]n|posi[çc][aã]o|posizione|standpunkt)\s*\*{0,2}\s*:\s*(.+)/iu', $content, $m)) {
            $stance = trim(strip_tags($m[1]));
            $stance = trim($stance, " \t\n\r*_");
            if ($stance !== '') {
                return Str::limit($stance, 200, '');
            }
        }

        $firstLine = trim(strip_tags(strtok($content, "\n") ?: ''));
        $firstLine = trim($firstLine, " \t\r*_#");

        return $firstLine !== '' ? Str::limit($firstLine, 200, '') : null;
    }

    /**
     * Broadcasts go to Reverb over HTTP. If Reverb is down we must not crash
     * the job — the turn is already persisted, so a refresh recovers it.
     */
    private function safeBroadcast(\Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            static $lastWarn = 0;
            if (microtime(true) - $lastWarn > 1) {
                Log::warning('Debate broadcast failed (continuing)', ['error' => $e->getMessage()]);
                $lastWarn = microtime(true);
            }
        }
    }
}
