<?php

namespace App\Services\Analyst;

use App\Ai\ExpressGateAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiUsageRecorder;
use App\Services\AiProviderService;
use App\Support\Ai\FactGuard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;

/**
 * The AI narrative pass over the recommender's deterministic output: ONE bounded
 * structured call that reorders the recommendations by relevance and rewords
 * each title/why in the business's own voice. It REFINES, never replaces —
 * every rewrite must keep the number the deterministic «why» carried, or that
 * card falls straight back to its grounded text; a model failure or timeout
 * passes the whole list through untouched. Gated behind
 * `express.analyst_narration` so a panel open never pays model latency until
 * it's proven on real boards.
 */
class RecommendationNarrator
{
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
        private readonly AiUsageRecorder $usage,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $recs  the presented recommendation DTOs
     * @param  array{sector: string, label: string}  $domain
     * @return list<array<string, mixed>>
     */
    public function narrate(array $recs, array $domain, ?User $user, string $lang, ?string $appId = null): array
    {
        if ($recs === [] || $user === null || ! config('express.analyst_narration', false)) {
            return $recs;
        }

        try {
            $rewritten = $this->askModel($recs, $domain, $user, $lang, $appId);
        } catch (\Throwable $e) {
            Log::info('Analyst narration fell back to deterministic text', ['error' => $e->getMessage()]);

            return $recs;
        }
        if ($rewritten === null) {
            return $recs;
        }

        // Apply refinements card-by-card, then reorder by the model's ranking.
        $byId = collect($recs)->keyBy('id');
        $applied = $byId->map(function (array $rec) use ($rewritten): array {
            $edit = $rewritten[$rec['id']] ?? null;
            if ($edit === null) {
                return $rec;
            }
            // A rewrite that drops a grounding number is a hallucination, so the
            // deterministic text stands. The title was accepted unchecked — a
            // title can carry a figure too ("Backlog: 412 abiertos"), and there
            // was no reason for it to be the one string a model could move.
            foreach (['title', 'why'] as $field) {
                $safe = FactGuard::safeRewrite((string) $rec[$field], $edit[$field] ?? null);
                if ($safe !== null) {
                    $rec[$field] = $safe;
                }
            }

            return $rec;
        });

        $order = collect($rewritten)->keys();
        $ranked = $order
            ->map(fn ($id) => $applied[$id] ?? null)
            ->filter()
            ->concat($applied->reject(fn ($r) => $order->contains($r['id'])))
            ->values();

        return $ranked->all();
    }

    /**
     * @param  list<array<string, mixed>>  $recs
     * @param  array{sector: string, label: string}  $domain
     * @return array<string, array{title: string, why: string}>|null keyed by id, in the model's order
     */
    private function askModel(array $recs, array $domain, User $user, string $lang, ?string $appId): ?array
    {
        $es = $lang !== 'en';
        $lines = collect($recs)->map(fn (array $r) => "- id={$r['id']} · {$r['kicker']} · «{$r['title']}» — {$r['why']}")->implode("\n");
        $instructions = $es
            ? "Eres un analista de BI para el dominio: {$domain['label']}. Te doy análisis ya derivados de datos reales. Reordénalos por relevancia para ese negocio y reescribe cada `title` (máx. 6 palabras) y `why` (máx. 24 palabras) en voz ejecutiva y clara. REGLA DURA: conserva EXACTAMENTE los números que trae cada `why` — no inventes cifras ni cambies las existentes. Devuelve los mismos ids."
            : "You are a BI analyst for the domain: {$domain['label']}. Here are analyses already derived from real data. Reorder them by relevance to that business and rewrite each `title` (max 6 words) and `why` (max 24 words) in a crisp executive voice. HARD RULE: keep EXACTLY the numbers each `why` carries — never invent or change figures. Return the same ids.";

        $schema = fn (JsonSchema $s): array => [
            'recommendations' => $s->array()->items(
                $s->object([
                    'id' => $s->string()->required(),
                    'title' => $s->string()->required(),
                    'why' => $s->string()->required(),
                ])
            )->required(),
        ];

        $model = null;
        $provider = Lab::Anthropic;
        try {
            $model = $this->aiDefaults->model('builder');
            $this->providers->applyRuntimeConfig($user);
            $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;
        } catch (\Throwable) {
            // best-effort resolution — fall through with Anthropic default
        }

        $agent = new ExpressGateAgent($instructions, $schema);
        $response = $agent->prompt(
            ($es ? "Análisis:\n" : "Analyses:\n").$lines,
            provider: $provider,
            model: $model,
            timeout: self::TIMEOUT_SECONDS,
        );

        try {
            $this->usage->record('express', $model, $user, $user->organization_id, $response->usage ?? null, appId: $appId);
        } catch (\Throwable) {
            // usage accounting is best-effort
        }

        $decoded = $response instanceof Arrayable ? $response->toArray() : null;
        $items = is_array($decoded) ? ($decoded['recommendations'] ?? null) : null;
        if (! is_array($items)) {
            return null;
        }

        $out = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $out[(string) $item['id']] = ['title' => (string) ($item['title'] ?? ''), 'why' => (string) ($item['why'] ?? '')];
            }
        }

        return $out === [] ? null : $out;
    }
}
