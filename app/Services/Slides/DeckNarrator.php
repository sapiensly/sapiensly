<?php

namespace App\Services\Slides;

use App\Ai\BuilderAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiSpendGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\AiProviderService;
use App\Support\Ai\FactGuard;
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
     * The only fields a narrative pass was invited to touch — the prose the
     * prompt names: a chart's takeaway, a big_number's context/delta, a metric's
     * delta. Everything else on a slide is data.
     *
     * @var list<string>
     */
    private const PROSE_FIELDS = ['takeaway', 'context', 'delta'];

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

            $operations = $this->faithfulOperations(
                (array) ($decoded['operations'] ?? []),
                $manifest,
            );

            // The summary is newly authored prose about the refresh, so there is
            // no original sentence to preserve — the only defensible test is that
            // every figure in it came from the data it is describing.
            $summary = is_string($decoded['summary'] ?? null) ? trim($decoded['summary']) : null;
            if ($summary !== null && $summary !== '' && ! FactGuard::onlyKnownNumbers(
                $summary,
                (string) json_encode([$oldDigest, $newDigest], JSON_UNESCAPED_UNICODE),
            )) {
                Log::warning('Deck narrative summary stated a figure the data does not contain — dropped', [
                    'deck' => $manifest['title'] ?? null,
                ]);
                $summary = null;
            }

            return ['summary' => $summary !== '' ? $summary : null, 'operations' => $operations];
        } catch (\Throwable $e) {
            Log::warning('Deck narrative pass failed (refresh continues without prose)', [
                'error' => $e->getMessage(),
            ]);

            return ['summary' => null, 'operations' => []];
        }
    }

    /**
     * The replace operations that only rewrote prose — the rest are dropped.
     *
     * The model hands back a WHOLE slide object, so it can rewrite a big_number's
     * value, a chart's series or a label as easily as the takeaway it was asked
     * to fix. The prompt says "NEVER touch labels/series/values", and nothing made
     * that true: the editor downstream validates a slide's structure, not its
     * fidelity to the one it replaces. So a refreshed deck could quietly ship a
     * figure the data never produced — in a deck whose whole promise is that its
     * numbers are live.
     *
     * @param  list<mixed>  $operations
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function faithfulOperations(array $operations, array $manifest): array
    {
        $slides = is_array($manifest['slides'] ?? null) ? array_values($manifest['slides']) : [];

        $kept = [];
        foreach ($operations as $op) {
            if (! is_array($op) || ($op['op'] ?? null) !== 'replace') {
                continue;
            }
            $index = $op['index'] ?? null;
            $slide = $op['slide'] ?? null;
            if (! is_numeric($index) || ! is_array($slide)) {
                continue;
            }
            $original = $slides[(int) $index] ?? null;
            if (! is_array($original)) {
                continue; // an index that isn't a slide is not an edit
            }

            if (! $this->rewroteOnlyProse($original, $slide)) {
                Log::warning('Deck narrative op changed more than prose — dropped', [
                    'slide_index' => (int) $index,
                    'layout' => $original['layout'] ?? null,
                ]);

                continue;
            }

            $kept[] = $op;
        }

        return $kept;
    }

    /**
     * Did this slide come back with only its prose changed?
     *
     * A `metrics` slide carries prose INSIDE its items (each metric's delta), so
     * the items are compared element by element before the flat check — otherwise
     * a legitimate delta fix would read as a changed `items` array and be thrown
     * away.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $rewritten
     */
    private function rewroteOnlyProse(array $original, array $rewritten): bool
    {
        $exempt = self::PROSE_FIELDS;

        $originalItems = $original['items'] ?? null;
        $rewrittenItems = $rewritten['items'] ?? null;

        if (is_array($originalItems) || is_array($rewrittenItems)) {
            if (! is_array($originalItems) || ! is_array($rewrittenItems)
                || count($originalItems) !== count($rewrittenItems)) {
                return false; // adding or dropping a metric is not a rewrite
            }
            foreach ($originalItems as $i => $item) {
                $new = $rewrittenItems[$i] ?? null;
                if (! is_array($item) || ! is_array($new)
                    || ! FactGuard::keepsValues($item, $new, self::PROSE_FIELDS)) {
                    return false;
                }
            }
            $exempt[] = 'items'; // compared element-wise above
        }

        return FactGuard::keepsValues($original, $rewritten, $exempt);
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
