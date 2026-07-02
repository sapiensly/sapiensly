<?php

namespace App\Services\Slides;

use App\Ai\BuilderAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiSpendGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\AiProviderService;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;

/**
 * The narrative half of a Living Deck refresh: when the live data changed,
 * re-plotting the charts is trivial — but the deck's PROSE would now lie
 * (a takeaway saying "churn is down" over a chart where it went up). One
 * cheap LLM call compares the old vs new data digests and returns:
 *
 *  - `summary`: 2-3 sentences of "what changed" for the version history, and
 *  - `operations`: surgical replace ops fixing ONLY data-dependent copy
 *    (chart takeaways, big-number context/delta, metric deltas).
 *
 * Strictly best-effort: any failure returns a null summary and no ops — the
 * refresh still versions the new data, just without prose.
 */
class DeckNarrator
{
    private const PROMPT = <<<'PROMPT'
        You maintain a business slide deck whose data-bound values were just refreshed. Compare the OLD vs NEW data below and respond with ONLY a JSON object (no markdown fence, no commentary):

        {"summary": "...", "operations": [...]}

        - `summary`: 2-3 sentences, in the deck's own language, saying what changed in the data (name the metrics and the direction, with numbers). This is shown in the deck's version history.
        - `operations`: OPTIONAL surgical edits — ONLY when a slide's prose now contradicts the new data (a chart `takeaway`, a big_number `context`/`delta`, a metric `delta`). Each is {"op":"replace","index":<0-based slide index>,"slide":{...the FULL corrected slide object...}}. Copy the slide exactly and change only the stale text; respect the original language and keep copy tight. NEVER touch labels/series/values of live-bound slides (the platform refreshes those), never add or remove slides, and return [] when no prose is stale.
        PROMPT;

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
    ) {}

    /**
     * @param  array<string, mixed>  $oldDigest
     * @param  array<string, mixed>  $newDigest
     * @param  array<string, mixed>  $manifest
     * @return array{summary: string|null, operations: list<array<string, mixed>>}
     */
    public function narrate(array $oldDigest, array $newDigest, array $manifest, User $owner): array
    {
        try {
            $model = $this->aiDefaults->model('builder');
            $this->providers->applyRuntimeConfig($owner);
            $provider = $this->providers->resolveProviderForCatalogModel($model, $owner) ?? Lab::Anthropic;

            app(AiSpendGuard::class)->assertWithinBudget($owner, $owner->organization_id, $model);

            $agent = new BuilderAgent(instructions: self::PROMPT, messages: [], tools: []);
            $agent->forModel($model);

            $payload = json_encode([
                'deck' => $manifest,
                'old_data' => $oldDigest,
                'new_data' => $newDigest,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $response = $agent->prompt(
                (string) $payload,
                provider: $provider,
                model: $model,
                timeout: (int) config('ai.request_timeout', 180),
            );

            app(AiUsageRecorder::class)->record('builder', $model, $owner, $owner->organization_id, $response->usage ?? null);

            $decoded = $this->extractJson((string) $response);
            if (! is_array($decoded)) {
                return ['summary' => null, 'operations' => []];
            }

            $operations = array_values(array_filter(
                (array) ($decoded['operations'] ?? []),
                fn ($op) => is_array($op) && ($op['op'] ?? null) === 'replace',
            ));

            $summary = is_string($decoded['summary'] ?? null) ? trim($decoded['summary']) : null;

            return ['summary' => $summary !== '' ? $summary : null, 'operations' => $operations];
        } catch (\Throwable $e) {
            Log::warning('Deck narrative pass failed (refresh continues without prose)', [
                'error' => $e->getMessage(),
            ]);

            return ['summary' => null, 'operations' => []];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
