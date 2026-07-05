<?php

namespace App\Services\Express\Phases;

use App\Models\PipelineRun;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Express\ExpressHalt;
use App\Services\Express\GateRunner;
use Illuminate\Support\Str;

/**
 * G-1: which of the source's tools answer the request, what gets an honest
 * proxy, and what can't be answered at all. This is rule 1d-fit as a gate —
 * the ONE place a run may stop to talk to the user: when the request's core
 * is unanswerable, building filler is forbidden and the halt message proposes
 * what the data CAN answer instead.
 */
class FitCheckPhase implements ExpressPhase
{
    private const MAX_TOOLS = 4;

    public function __construct(private readonly GateRunner $gates) {}

    public function name(): string
    {
        return 'fit_check';
    }

    public function announce(ExpressContext $context): string
    {
        return '🧭 Comparando tu pedido contra lo que la fuente puede responder…';
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        $catalog = collect($context->catalogTools)->map(fn (array $t): array => [
            'name' => $t['name'],
            'description' => $t['description'] ?? '',
            'arguments' => $t['arguments'] ?? [],
        ])->values()->all();

        $result = $this->gates->run(
            $run,
            'fit_check',
            <<<'TXT'
Eres el fit-check de un constructor de dashboards. Recibes el PEDIDO del
usuario y el CATÁLOGO de tools de datos de su conexión. Decide qué tools leer
(las mínimas que respondan el pedido, máx 4, prefiere agregados sobre crudos),
qué piezas pedidas no existen pero tienen un proxy honesto (dilo), y qué
piezas no se pueden responder con estos datos. core_unanswerable=true SOLO si
el TEMA CENTRAL del pedido no se puede responder en absoluto — en ese caso
llena alternatives con 1-2 dashboards que estos datos SÍ responden. Nunca
inventes tools que no estén en el catálogo.
TXT,
            json_encode(['pedido' => $context->prompt, 'catalogo' => $catalog], JSON_UNESCAPED_UNICODE),
            fn ($schema) => [
                'tools' => $schema->array()->description('Nombres exactos de tools del catálogo a leer (1-4).'),
                'substitutions' => $schema->array()->description('[{asked, using, reason}] proxies honestos.'),
                'unanswerable' => $schema->array()->description('[{asked, reason}] piezas pedidas sin respuesta en estos datos.'),
                'core_unanswerable' => $schema->boolean(),
                'alternatives' => $schema->array()->description('Si core_unanswerable: 1-2 dashboards que SÍ se pueden construir.'),
            ],
            fn () => $this->heuristicDefault($context),
            $context->user,
            $context->modelOverride,
        );

        $out = $result['output'];

        if (($out['core_unanswerable'] ?? false) === true) {
            $alternatives = collect($out['alternatives'] ?? [])
                ->map(fn ($a) => is_string($a) ? $a : json_encode($a, JSON_UNESCAPED_UNICODE))
                ->implode("\n- ");

            throw new ExpressHalt(
                'halted_unanswerable',
                "Esta conexión no puede responder el tema central de tu pedido.\n\nLo que sus datos SÍ pueden responder:\n- {$alternatives}\n\nDime cuál construyo (o conecta otra fuente).",
            );
        }

        $known = array_column($context->catalogTools, 'name');
        $chosen = collect($out['tools'] ?? [])
            ->filter(fn ($t) => is_string($t) && in_array($t, $known, true))
            ->unique()->take(self::MAX_TOOLS)->values()->all();

        $context->chosenTools = $chosen !== [] ? $chosen : $this->heuristicDefault($context)['tools'];
        $context->substitutions = array_values(array_filter($out['substitutions'] ?? [], 'is_array'));
        $context->unanswerable = array_values(array_filter($out['unanswerable'] ?? [], 'is_array'));

        foreach ($context->substitutions as $sub) {
            $context->note('Sustitución: '.($sub['asked'] ?? '?').' → '.($sub['using'] ?? '?').' ('.($sub['reason'] ?? '').')');
        }
        foreach ($context->unanswerable as $miss) {
            $context->note('No respondible con esta fuente: '.($miss['asked'] ?? '?').' ('.($miss['reason'] ?? '').')');
        }
    }

    /**
     * Keyword-overlap fallback: score each catalog tool by request-word hits
     * on its name+description; take the scorers, else the first listish tool.
     *
     * @return array{tools: list<string>, substitutions: array, unanswerable: array, core_unanswerable: bool}
     */
    private function heuristicDefault(ExpressContext $context): array
    {
        $words = collect(preg_split('/[^a-z0-9áéíóúñ]+/i', Str::lower(Str::ascii($context->prompt))))
            ->filter(fn ($w) => mb_strlen((string) $w) > 3)
            ->unique()->values();

        $scored = collect($context->catalogTools)->map(function (array $tool) use ($words): array {
            $haystack = Str::lower(Str::ascii(($tool['name'] ?? '').' '.($tool['description'] ?? '')));
            $score = $words->filter(fn ($w) => str_contains($haystack, (string) $w))->count();

            return ['name' => $tool['name'], 'score' => $score];
        })->sortByDesc('score')->values();

        $tools = $scored->where('score', '>', 0)->take(self::MAX_TOOLS)->pluck('name')->all();

        if ($tools === []) {
            $listish = collect($context->catalogTools)
                ->first(fn (array $t) => preg_match('/list|search|get|series|breakdown|report/i', (string) $t['name']) === 1);
            $tools = array_values(array_filter([($listish ?? $context->catalogTools[0] ?? [])['name'] ?? null]));
        }

        return ['tools' => $tools, 'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false];
    }
}
