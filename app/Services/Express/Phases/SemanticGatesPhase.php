<?php

namespace App\Services\Express\Phases;

use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Models\PipelineRun;
use App\Services\Ai\AiDefaults;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Express\GateRunner;
use App\Services\Express\LabelGrounding;
use App\Services\Manifest\AppScaffolder;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Support\Str;

/**
 * G-2: the three semantic gates — the ONLY places the user's model shapes the
 * dashboard. (a) overrides to the suggested spec (best-of-2 with a
 * deterministic compile+lint judge for slow-class models), (b) voice (title +
 * purpose), (c) insight bodies narrated from the computed facts. Every gate
 * degrades to the suggestion's defaults; a hung model costs 45s, never the
 * build.
 */
class SemanticGatesPhase implements ExpressPhase
{
    public function __construct(
        private readonly GateRunner $gates,
        private readonly AppScaffolder $scaffolder,
        private readonly AiDefaults $aiDefaults,
    ) {}

    public function name(): string
    {
        return 'semantic_gates';
    }

    public function announce(ExpressContext $context): string
    {
        return 'Ajustando el spec y redactando los insights…';
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        $spec = $context->spec ?? [];

        // Economy: the deterministic fit was unambiguous, so the suggestion IS
        // the page — the suggester already writes factual insight bodies and
        // an honest title, and semanticEnriched stays false so the refine pass
        // skips itself. Recorded per gate so telemetry says WHY no model ran.
        if ($context->economyMode) {
            $context->semantic['overrides'] = [];
            $context->semantic['voice'] = [
                'title' => (string) ($spec['title'] ?? 'Dashboard'),
                'purpose' => '',
            ];
            $context->semantic['insights'] = array_values($spec['insights'] ?? []);
            foreach (['spec_overrides', 'voice_insights'] as $gate) {
                $run->recordGate($gate, [
                    'model' => null,
                    'latency_ms' => 0,
                    'fallback_used' => false,
                    'tokens' => null,
                    'error' => null,
                    'economy' => true,
                ]);
            }

            return;
        }

        // --- G-2a: overrides -------------------------------------------------
        $overridesInstructions = <<<'TXT'
Revisas el SPEC SUGERIDO de un dashboard contra el PEDIDO del usuario. Devuelve
SOLO los cambios necesarios (accept=true y overrides={} si la sugerencia ya
responde el pedido). overrides puede traer: title, kpis, charts (listas
COMPLETAS que reemplazan la parte), date_field_id. Solo usa field_ids que
existan en el spec sugerido, y CONSERVA el object_slug de cada kpi/chart que
lo traiga — ese bloque lee OTRO objeto y sin su slug apunta al equivocado.
La sugerencia es el PISO de calidad, no un borrador: puedes afinar títulos,
añadir o reordenar, pero NUNCA reducir el número de gráficas o KPIs, NUNCA
eliminar una forma presente (gauge, pareto, donut, funnel, heatmap…), y NUNCA
etiquetar una gráfica con una dimensión (causas, motivos, categorías…) que
sus datos no traen. Un override que recorta se rechaza entero.
TXT;
        $overridesSchema = fn ($schema) => [
            'accept' => $schema->boolean(),
            'overrides' => $schema->object()->description('Partes a reemplazar: title?, kpis?, charts?, date_field_id?.'),
        ];
        $overridesPrompt = json_encode([
            'pedido' => $context->prompt,
            'spec_sugerido' => $spec,
            'sustituciones_ya_declaradas' => $context->substitutions,
        ], JSON_UNESCAPED_UNICODE);
        $overridesDefault = ['accept' => true, 'overrides' => []];

        // Early-exit best-of-N: judge each candidate AS IT ARRIVES — an
        // accepted suggestion or a compiling delta skips the second model
        // call entirely (each one costs 20-45s on a slow model).
        $attempts = (bool) config('express.spec_overrides', false)
            ? ($this->isSlowClass($context) ? 2 : 1)
            : 0; // retired by default: the floor + grounded verifier cover it
        $chosen = [];
        $rejections = [];
        for ($i = 0; $i < $attempts; $i++) {
            $result = $this->gates->run(
                $run, $i > 0 ? "spec_overrides_{$i}" : 'spec_overrides',
                $overridesInstructions, $overridesPrompt, $overridesSchema, $overridesDefault,
                $context->user, $context->modelOverride, $context,
            );
            $overrides = is_array($result['output']['overrides'] ?? null) ? $result['output']['overrides'] : [];
            if ($overrides === []) {
                break; // suggestion accepted as-is
            }
            $breach = $this->fidelityBreach($context, $overrides);
            if ($breach !== null) {
                $rejections[] = $breach;

                continue;
            }
            if (! $this->overridesCompile($context, $overrides)) {
                $rejections[] = 'no compila / lints';

                continue;
            }
            $chosen = $overrides;
            break;
        }
        $context->semantic['overrides'] = $chosen;
        // The decision itself is telemetry: two prod audits had to infer from
        // page archaeology whether the override shipped or died. A disabled
        // gate says so instead of leaving a hole.
        $run->recordGate('spec_overrides', ($run->gates['spec_overrides'] ?? [])
            + array_filter([
                'applied' => $chosen !== [],
                'skipped' => $attempts === 0 ? 'disabled' : null,
                'rejections' => $rejections !== [] ? $rejections : null,
            ], fn ($v) => $v !== null));
        if ($chosen !== []) {
            $context->semanticEnriched = true;
        }

        // --- G-2b+c fused: voice AND insights in ONE call — a whole slow-model
        // round-trip saved versus separate gates.
        $suggested = array_values($spec['insights'] ?? []);
        $voiceInsights = $this->gates->run(
            $run, 'voice_insights',
            <<<'TXT'
Dos tareas sobre un dashboard, en el idioma del pedido. (1) VOZ: escribe title
(máximo 8 palabras, lenguaje de negocio — NUNCA nombres técnicos de tools,
endpoints ni placeholders) y purpose (UNA sola oración: audiencia + preguntas
que responde, unidas con dos puntos y sin punto intermedio — p. ej. «Para
líderes del CSC: responde cuánto volumen entra, qué está rezagado y cuánto
tarda resolverse»).
(2) INSIGHTS: redacta los body de las tarjetas usando SOLO los HECHOS
COMPUTADOS (números reales) — mantén variant y title de cada tarjeta sugerida
(puedes afinar el title), body con una conclusión concreta y accionable (1-2
frases, con el número). Nada de datos inventados. Devuelve exactamente el
mismo número de tarjetas.
TXT,
            json_encode([
                'pedido' => $context->prompt,
                'titulo_sugerido' => $spec['title'] ?? '',
                'tarjetas_sugeridas' => $suggested,
                'hechos_computados' => $context->facts,
            ], JSON_UNESCAPED_UNICODE),
            fn ($schema) => [
                'title' => $schema->string(),
                'purpose' => $schema->string(),
                'insights' => $schema->array()->description('[{variant, title, body}] mismo orden y cantidad.'),
            ],
            fn () => [
                'title' => (string) ($spec['title'] ?? 'Dashboard'),
                'purpose' => '',
                // The suggested cards already carry factual bodies — the
                // suggester narrates them from the computed facts at
                // suggest-time (FactNarrator), so the banked deterministic
                // page has real numbers whether or not this gate answers.
                'insights' => $suggested,
            ],
            $context->user, $context->modelOverride, $context,
        );

        $context->semantic['voice'] = [
            'title' => (string) ($voiceInsights['output']['title'] ?? ($spec['title'] ?? 'Dashboard')),
            'purpose' => (string) ($voiceInsights['output']['purpose'] ?? ''),
        ];
        $bodies = array_values(array_filter($voiceInsights['output']['insights'] ?? [], 'is_array'));
        $modelWroteInsights = count($bodies) === count($suggested) && $suggested !== [];
        $context->semantic['insights'] = $modelWroteInsights
            ? $this->mergeInsights($suggested, $bodies)
            : $suggested;

        // A real voice line or model-narrated insight bodies also count as
        // enrichment worth a second (refined) version over the deterministic one.
        if (! $voiceInsights['fallback_used'] && ($modelWroteInsights
            || trim((string) $context->semantic['voice']['purpose']) !== ''
            || trim((string) $context->semantic['voice']['title']) !== trim((string) ($spec['title'] ?? '')))) {
            $context->semanticEnriched = true;
        }
    }

    /**
     * The deterministic suggestion is the FLOOR, not a draft (prod
     * plr_01kx7adw2z: a wholesale charts override dropped the asked gauge and
     * both distribution donuts, and relabeled a category breakdown as "Causas
     * Raíz"). An override may refine and add; one that shrinks a section,
     * loses a suggested chart form, or claims a dimension its data does not
     * carry is rejected whole — the suggestion ships instead.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fidelityBreach(ExpressContext $context, array $overrides): ?string
    {
        foreach (['charts', 'kpis'] as $section) {
            if (! array_key_exists($section, $overrides)) {
                continue;
            }
            $suggested = collect($context->spec[$section] ?? [])
                ->filter(fn ($c): bool => is_array($c))->values();
            $proposed = collect(is_array($overrides[$section]) ? $overrides[$section] : [])
                ->filter(fn ($c): bool => is_array($c))->values();
            if ($proposed->count() < $suggested->count()) {
                return "recorta {$section}: ".$proposed->count().' < '.$suggested->count();
            }
            if ($section !== 'charts') {
                continue;
            }
            $countsOf = fn ($list) => $list->countBy(
                fn (array $c): string => (string) ($c['chart_type'] ?? 'bar'),
            );
            $have = $countsOf($proposed);
            foreach ($countsOf($suggested) as $type => $needed) {
                if ((int) ($have[$type] ?? 0) < (int) $needed) {
                    return "pierde forma: {$type}";
                }
            }
            foreach ($proposed as $chart) {
                if (! $this->chartLabelGrounded($context, $chart)) {
                    return 'label sin sustento: «'.(string) ($chart['label'] ?? '').'»';
                }
            }
        }

        return null;
    }

    /**
     * A label claiming a dimension ("Top 8 Causas Raíz") must point at data
     * that carries it — the observed failure showed category keys wearing a
     * causes title. Lexicon variants bridge ES↔EN (causas↔cause); a 5-char
     * stem covers inflections the lexicon lacks.
     *
     * @param  array<string, mixed>  $chart
     */
    private function chartLabelGrounded(ExpressContext $context, array $chart): bool
    {
        $slug = (string) ($chart['object_slug'] ?? ($context->spec['object_slug'] ?? ''));
        $object = collect($context->objects)->firstWhere('slug', $slug);

        return LabelGrounding::grounded((string) ($chart['label'] ?? ''), is_array($object) ? $object : null);
    }

    /**
     * Deterministic judge for ONE candidate: its overrides must still compile
     * and pass the dashboard lints. Plausible-but-broken deltas die here, not
     * on the user's screen.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function overridesCompile(ExpressContext $context, array $overrides): bool
    {
        $primary = collect($context->objects)->firstWhere('slug', $context->spec['object_slug'] ?? '');

        return $primary !== null && $this->compiles($context, $primary, $overrides);
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $overrides
     */
    private function compiles(ExpressContext $context, array $primary, array $overrides): bool
    {
        try {
            $args = array_merge($context->spec, $overrides, ['object_slug' => $primary['slug']]);
            $extras = collect($context->objects)
                ->filter(fn ($o) => is_array($o) && ($o['slug'] ?? null) !== $primary['slug'])
                ->values()->all();
            $built = $this->scaffolder->buildDashboardFromSpec(
                $args, $primary, [],
                ColorPalette::fromAccent(OrganizationBrand::DEFAULT_ACCENT),
                'es', $extras,
            );

            if (($built['ok'] ?? false) !== true) {
                return false;
            }

            return PlanDashboardTool::lint($built['purpose'], $built['plan_rows'])['ok'];
        } catch (\Throwable) {
            return false;
        }
    }

    private function isSlowClass(ExpressContext $context): bool
    {
        try {
            $model = Str::lower($this->aiDefaults->model('builder', $context->modelOverride));
        } catch (\Throwable) {
            $model = Str::lower((string) $context->modelOverride);
        }

        foreach (config('express.slow_models', []) as $needle) {
            if ($needle !== '' && str_contains($model, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $suggested
     * @param  list<array<string, mixed>>  $written
     * @return list<array<string, mixed>>
     */
    private function mergeInsights(array $suggested, array $written): array
    {
        return collect($suggested)->map(function (array $card, int $i) use ($written): array {
            $card['title'] = (string) ($written[$i]['title'] ?? $card['title']);
            $card['body'] = (string) ($written[$i]['body'] ?? $card['body'] ?? '');

            return $card;
        })->values()->all();
    }
}
