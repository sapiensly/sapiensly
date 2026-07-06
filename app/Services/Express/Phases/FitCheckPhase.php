<?php

namespace App\Services\Express\Phases;

use App\Models\PipelineRun;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Express\ExpressHalt;
use App\Services\Express\GateRunner;
use Illuminate\Support\Collection;
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
        // Tools observed to return NO rows (summary-only) are excluded from
        // the menu entirely — a repeat pick is a wasted acquisition.
        $noRows = collect($context->knownShapes)
            ->filter(fn (array $shape): bool => ($shape['fields'] ?? null) === [])
            ->keys()->all();

        $catalog = collect($context->catalogTools)
            ->reject(fn (array $t): bool => in_array($t['name'], $noRows, true))
            ->map(fn (array $t): array => [
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
(las mínimas que respondan el pedido, máx 4). REGLAS DE ELECCIÓN: (1) SOLO
tools cuyo DOMINIO coincide con el tema del pedido — para un pedido de tickets
elige tools de tickets, nunca de sellers/entregas/otros dominios aunque suenen
útiles; (2) si el pedido menciona series por semana/tiempo, INCLUYE el tool de
serie temporal; (3) prefiere tools que devuelven LISTAS de filas (series,
breakdowns por dimensión) sobre resúmenes de totales; (4) menos es más — 2
tools correctos superan a 4 dudosos. OBLIGATORIO: desglosa el
pedido en sus piezas (cada KPI/gráfica/dimensión pedida) y mapea CADA una en
`pieces` al tool exacto que la responde, o null (con proxy solo si existe una
métrica sustituta REAL). Declara además qué piezas tienen proxy honesto y qué
piezas no se pueden responder.
core_unanswerable=true SOLO si el TEMA CENTRAL del pedido no se puede
responder en absoluto — llena alternatives con 1-2 dashboards que estos datos
SÍ responden. Nunca inventes tools que no estén en el catálogo.
TXT,
            json_encode(['pedido' => $context->prompt, 'catalogo' => $catalog], JSON_UNESCAPED_UNICODE),
            fn ($schema) => [
                'tools' => $schema->array()->description('Nombres exactos de tools del catálogo a leer (1-4).'),
                'pieces' => $schema->array()->description('OBLIGATORIO: una entrada por CADA pieza pedida: [{asked, tool: nombre exacto del tool que la responde o null, proxy: métrica sustituta honesta si tool es null y existe una}].'),
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
                ->map(function ($a): string {
                    if (is_string($a)) {
                        return $a;
                    }
                    if (is_array($a)) {
                        // Models answer objects with varying keys — surface the
                        // human parts, never raw JSON.
                        $label = collect(['dashboard', 'label', 'title', 'nombre'])
                            ->map(fn ($k) => $a[$k] ?? null)->filter()->first();
                        $why = collect(['relevancia', 'reason', 'descripcion', 'description'])
                            ->map(fn ($k) => $a[$k] ?? null)->filter()->first();

                        return trim(($label ?? '').($why ? ' — '.$why : '')) ?: json_encode($a, JSON_UNESCAPED_UNICODE);
                    }

                    return (string) $a;
                })
                ->implode("\n- ");

            throw new ExpressHalt(
                'halted_unanswerable',
                "Esta conexión no puede responder el tema central de tu pedido.\n\nLo que sus datos SÍ pueden responder:\n- {$alternatives}\n\nDime cuál construyo (o conecta otra fuente).",
            );
        }

        $known = array_diff(array_column($context->catalogTools, 'name'), $noRows);
        $chosen = collect($out['tools'] ?? [])
            ->filter(fn ($t) => is_string($t) && in_array($t, $known, true))
            ->unique()->take(self::MAX_TOOLS)->values()->all();

        $context->chosenTools = $chosen !== [] ? $chosen : $this->heuristicDefault($context)['tools'];
        $context->substitutions = array_values(array_filter($out['substitutions'] ?? [], 'is_array'));
        $context->unanswerable = array_values(array_filter($out['unanswerable'] ?? [], 'is_array'));

        // Deterministic backstop #1 — the piece mapping decides, not the bool.
        // Trusting core_unanswerable alone proved stochastic: the same model
        // halted one run and built a "financial" board from delivery data the
        // next, declaring zero substitutions. Pieces the model itself could
        // not map to any tool (and gave no proxy for) are unanswerable by its
        // own account; a majority of them means the CORE is unanswerable.
        $pieces = array_values(array_filter($out['pieces'] ?? [], 'is_array'));
        if ($pieces !== []) {
            $unmapped = [];
            foreach ($pieces as $piece) {
                $tool = trim((string) ($piece['tool'] ?? ''));
                $proxy = trim((string) ($piece['proxy'] ?? ''));
                if ($tool !== '' && in_array($tool, $known, true)) {
                    continue;
                }
                if ($proxy !== '') {
                    $context->substitutions[] = [
                        'asked' => (string) ($piece['asked'] ?? '?'),
                        'using' => $proxy,
                        'reason' => 'proxy declarado por el fit-check',
                    ];

                    continue;
                }
                $unmapped[] = (string) ($piece['asked'] ?? '?');
                $context->unanswerable[] = [
                    'asked' => (string) ($piece['asked'] ?? '?'),
                    'reason' => 'ningún tool de la fuente la cubre',
                ];
            }

            if (count($unmapped) * 2 > count($pieces)) {
                throw new ExpressHalt(
                    'halted_unanswerable',
                    'Esta conexión no puede responder la mayor parte de tu pedido (sin datos para: '.implode(', ', $unmapped).').

Los datos disponibles cubren: '.$this->sourceDomains($context).'.

Dime qué construyo sobre eso (o conecta otra fuente).',
                );
            }
        }

        // Deterministic backstop #2 — zero topical overlap. If NONE of the
        // chosen tools shares a single topic word with the request, the model
        // is building filler from another domain (observed: an OTD/delivery
        // tool powering a "financial projection" board). Halt honestly.
        if ($context->chosenTools !== [] && $this->allChosenOffTopic($context)) {
            throw new ExpressHalt(
                'halted_unanswerable',
                'Los datos de esta conexión no tratan el tema de tu pedido.

Lo que sí cubren: '.$this->sourceDomains($context).'.

Dime qué construyo sobre eso (o conecta otra fuente).',
            );
        }

        foreach ($context->substitutions as $sub) {
            $context->note('Sustitución: '.($sub['asked'] ?? '?').' → '.($sub['using'] ?? '?').' ('.($sub['reason'] ?? '').')');
        }
        foreach ($context->unanswerable as $miss) {
            $asked = trim((string) ($miss['asked'] ?? ''));
            if ($asked === '' || $asked === '?') {
                continue; // a nameless miss is noise, not information
            }
            $context->note('No respondible con esta fuente: '.$asked.' ('.($miss['reason'] ?? '').')');
        }
    }

    /**
     * True when every chosen tool scores ZERO topic-word overlap with the
     * request (name + description) — the signature of an off-domain build.
     */
    private function allChosenOffTopic(ExpressContext $context): bool
    {
        $words = $this->topicWords($context->prompt);
        if ($words->isEmpty()) {
            return false;
        }

        $byName = collect($context->catalogTools)->keyBy('name');
        foreach ($context->chosenTools as $name) {
            $tool = $byName->get($name);
            if ($tool === null) {
                continue;
            }
            $haystack = Str::lower(Str::ascii(($tool['name'] ?? '').' '.($tool['description'] ?? '')));
            if ($words->contains(fn ($w) => str_contains($haystack, (string) $w))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Collection<int, string>
     */
    private function topicWords(string $prompt)
    {
        $stop = ['crea', 'dashboard', 'tablero', 'reporte', 'quiero', 'necesito', 'para', 'con', 'una', 'las', 'los', 'del', 'que', 'analisis', 'analizar', 'grafica', 'graficas', 'kpis', 'insights', 'filtro', 'fecha', 'datos', 'reales', 'vista', 'ejecutiva', 'build', 'create', 'make'];

        return collect(preg_split('/[^a-z0-9áéíóúñ]+/i', Str::lower(Str::ascii($prompt))))
            ->filter(fn ($w) => mb_strlen((string) $w) > 3 && ! in_array((string) $w, $stop, true))
            ->unique()->values();
    }

    /** Human list of what the source's tools are about, from their names. */
    private function sourceDomains(ExpressContext $context): string
    {
        return collect($context->catalogTools)
            ->flatMap(fn (array $t) => preg_split('/[-_]/', (string) $t['name']))
            ->filter(fn ($w) => mb_strlen((string) $w) > 3 && ! in_array($w, ['tool', 'get', 'list', 'search', 'global', 'with', 'status', 'report', 'metrics'], true))
            ->unique()->take(10)->implode(', ');
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

        $noRows = collect($context->knownShapes)
            ->filter(fn (array $shape): bool => ($shape['fields'] ?? null) === [])
            ->keys()->all();

        $scored = collect($context->catalogTools)
            ->reject(fn (array $t): bool => in_array($t['name'], $noRows, true))
            ->map(function (array $tool) use ($words): array {
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
