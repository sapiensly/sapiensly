<?php

namespace App\Services\Express\Phases;

use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Models\PipelineRun;
use App\Services\Ai\AiDefaults;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Express\GateRunner;
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

        // --- G-2a: overrides -------------------------------------------------
        $overridesInstructions = <<<'TXT'
Revisas el SPEC SUGERIDO de un dashboard contra el PEDIDO del usuario. Devuelve
SOLO los cambios necesarios (accept=true y overrides={} si la sugerencia ya
responde el pedido). overrides puede traer: title, kpis, charts (listas
COMPLETAS que reemplazan la parte), date_field_id. Solo usa field_ids que
existan en el spec sugerido, y CONSERVA el object_slug de cada kpi/chart que
lo traiga — ese bloque lee OTRO objeto y sin su slug apunta al equivocado.
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
        $attempts = $this->isSlowClass($context) ? 2 : 1;
        $chosen = [];
        for ($i = 0; $i < $attempts; $i++) {
            $result = $this->gates->run(
                $run, $i > 0 ? "spec_overrides_{$i}" : 'spec_overrides',
                $overridesInstructions, $overridesPrompt, $overridesSchema, $overridesDefault,
                $context->user, $context->modelOverride,
            );
            $overrides = is_array($result['output']['overrides'] ?? null) ? $result['output']['overrides'] : [];
            if ($overrides === []) {
                break; // suggestion accepted as-is
            }
            if ($this->overridesCompile($context, $overrides)) {
                $chosen = $overrides;
                break;
            }
        }
        $context->semantic['overrides'] = $chosen;

        // --- G-2b+c fused: voice AND insights in ONE call — a whole slow-model
        // round-trip saved versus separate gates.
        $suggested = array_values($spec['insights'] ?? []);
        $voiceInsights = $this->gates->run(
            $run, 'voice_insights',
            <<<'TXT'
Dos tareas sobre un dashboard, en el idioma del pedido. (1) VOZ: escribe title
(máximo 8 palabras, lenguaje de negocio — NUNCA nombres técnicos de tools,
endpoints ni placeholders) y purpose (1 frase: audiencia + preguntas que
responde).
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
                'insights' => $this->factualFallbackInsights($suggested, $context->facts),
            ],
            $context->user, $context->modelOverride,
        );

        $context->semantic['voice'] = [
            'title' => (string) ($voiceInsights['output']['title'] ?? ($spec['title'] ?? 'Dashboard')),
            'purpose' => (string) ($voiceInsights['output']['purpose'] ?? ''),
        ];
        $bodies = array_values(array_filter($voiceInsights['output']['insights'] ?? [], 'is_array'));
        $context->semantic['insights'] = count($bodies) === count($suggested) && $suggested !== []
            ? $this->mergeInsights($suggested, $bodies)
            : $suggested;
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
     * Fallback insight bodies: the suggester's scaffolds with the real numbers
     * interpolated — factual even without a model.
     *
     * @param  list<array<string, mixed>>  $suggested
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    private function factualFallbackInsights(array $suggested, array $facts): array
    {
        $factLine = 'Registros analizados: '.($facts['row_count'] ?? 0).'.';
        $top = collect($facts['top_values'] ?? [])->first();
        if (is_array($top)) {
            $factLine .= " Valor dominante: {$top['top']} ({$top['share_pct']}%).";
        }

        return array_map(function (array $card) use ($factLine): array {
            $card['body'] = trim(($card['body'] ?? '').' '.$factLine);

            return $card;
        }, $suggested);
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
