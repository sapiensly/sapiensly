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
        return 'Comparando tu pedido contra lo que la fuente puede responder…';
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
            json_encode(['pedido' => $context->prompt], JSON_UNESCAPED_UNICODE),
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
            $context,
            // The catalog is the gate's STABLE bulk (~10k tokens, identical
            // across both attempts and across builds on the same connection) —
            // as stable context it rides the system prompt and gets the
            // Anthropic cache marker instead of being re-read cold per call.
            stableContext: 'CATÁLOGO DE TOOLS DE LA CONEXIÓN (JSON):'."\n"
                .json_encode(['catalogo' => $catalog], JSON_UNESCAPED_UNICODE),
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

        // Deterministic backstop #0 — the model ignored on-topic tools. When the
        // catalog names tools after a DISCRIMINATING prompt topic (the source
        // exposes get-nps-* tools for an "nps" request) but the model chose NONE
        // of them, prefer those tools. The inverse of the off-topic halt: a slow
        // model that answered but picked the wrong subject is corrected, not
        // trusted (observed: GLM built a ticket-VOLUME board for "dashboard de
        // nps" while 7 nps tools sat unused). No-op when the model already
        // covered the subject, or when a defaulted run's keyword scorer already
        // picked the on-topic tools.
        $this->preferOnTopicTools($context, $noRows);

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
        } else {
            // Deterministic backstop #1b — the gate SKIPPED the mandatory
            // mapping (observed: a 44-token answer with no pieces built a
            // "finance" board from delivery data). Derive the pieces from the
            // request itself and apply the same majority rule.
            $this->haltIfDerivedPiecesUnmapped($context);
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
     * When the model returns NO piece mapping, split the request into
     * comma/«y»-separated fragments and treat each multi-word fragment as an
     * asked piece: one none of whose topic words appears in any chosen tool
     * is unanswered, and an unmapped majority halts — the same rule as the
     * declared mapping. Deliberately conservative: fragments need ≥2 topic
     * words to count (single words are qualifiers, not data asks) and the
     * check needs ≥2 such fragments, so a vague one-line prompt is exempt.
     */
    private function haltIfDerivedPiecesUnmapped(ExpressContext $context): void
    {
        $fragments = collect(preg_split('/[,;:.]|\s+y\s+|\s+e\s+/iu', Str::lower(Str::ascii($context->prompt))) ?: [])
            ->map(fn ($f) => trim((string) $f))
            ->map(fn (string $f): array => ['text' => $f, 'words' => $this->topicWords($f)])
            ->filter(fn (array $f): bool => $f['words']->count() >= 2)
            ->values();
        if ($fragments->count() < 2) {
            return;
        }

        $byName = collect($context->catalogTools)->keyBy('name');
        $haystack = collect($context->chosenTools)
            ->map(fn (string $n) => $byName->get($n))
            ->filter()
            ->map(fn (array $t): string => Str::lower(Str::ascii(($t['name'] ?? '').' '.($t['description'] ?? ''))))
            ->implode(' ');
        // Stem-tolerant both ways: "producto" matches "productos" in the tool,
        // and the tool's "detractor" matches "detractores" in the prompt.
        $haystackWords = collect(preg_split('/[^a-z0-9]+/', $haystack) ?: [])
            ->filter(fn ($w) => mb_strlen((string) $w) >= 4)->unique()->values();
        $covers = fn (string $w): bool => str_contains($haystack, $w)
            || $haystackWords->contains(fn (string $hw): bool => str_contains($w, $hw));

        $unmapped = $fragments->reject(fn (array $f): bool => $f['words']->contains(fn ($w) => $covers((string) $w)));

        if ($unmapped->count() * 2 > $fragments->count()) {
            throw new ExpressHalt(
                'halted_unanswerable',
                'Esta conexión no puede responder la mayor parte de tu pedido (sin datos para: '.$unmapped->pluck('text')->implode(', ').').

Los datos disponibles cubren: '.$this->sourceDomains($context).'.

Dime qué construyo sobre eso (o conecta otra fuente).',
            );
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
        $stop = [
            'crea', 'dashboard', 'tablero', 'reporte', 'quiero', 'necesito', 'para', 'con', 'una', 'las', 'los', 'del', 'que', 'como', 'por', 'sus', 'este', 'esta', 'analisis', 'analizar', 'analiza', 'grafica', 'graficas', 'kpis', 'kpi', 'insights', 'filtro', 'fecha', 'datos', 'reales', 'vista', 'ejecutiva', 'ejecutivo', 'build', 'create', 'make', 'the', 'and', 'for',
            // Analytical phrasing, not data asks — "métricas clave, tendencias
            // y desgloses relevantes" describes ANY dashboard; these words must
            // not count as topical evidence for or against a source.
            'muestrame', 'muestra', 'dame', 'hazme', 'metricas', 'metrica', 'clave', 'tendencia', 'tendencias', 'evolucion', 'semanal', 'mensual', 'diario', 'diaria', 'desglose', 'desgloses', 'dimension', 'dimensiones', 'relevantes', 'principales', 'conclusiones', 'recomendaciones', 'acciones', 'tomar', 'donde', 'concentran', 'problemas', 'oportunidades', 'diagnostico', 'resumen', 'general', 'picos', 'caidas', 'tiempo', 'periodo',
        ];

        // >= 3, not > 3: three-letter acronyms ARE the topic in this domain
        // (nps, otd, sla, ots) — dropping them made the overlap backstop kill
        // a legitimate NPS build.
        return collect(preg_split('/[^a-z0-9áéíóúñ]+/i', Str::lower(Str::ascii($prompt))))
            ->filter(fn ($w) => mb_strlen((string) $w) >= 3 && ! in_array((string) $w, $stop, true))
            ->unique()->values();
    }

    /**
     * Topic words that actually DISCRIMINATE one tool from another. A word in
     * (nearly) EVERY catalog tool is a namespace/org term, not a topic — "yuhu"
     * in "get-nps-yuhu-tool" AND "get-tickets-yuhu-tool" tells you nothing about
     * which to read. Dropping those before scoring stops the fallback padding an
     * NPS board with ticket tools that only matched the org name. If every word
     * is ubiquitous (a single-domain source), keep the full set rather than
     * scoring on nothing.
     *
     * @return Collection<int, string>
     */
    private function discriminatingTopicWords(ExpressContext $context)
    {
        $words = $this->topicWords($context->prompt);
        $tools = collect($context->catalogTools);
        $n = $tools->count();
        if ($n < 3 || $words->isEmpty()) {
            return $words; // too few tools to judge ubiquity meaningfully
        }

        $haystacks = $tools
            ->map(fn (array $t): string => Str::lower(Str::ascii(($t['name'] ?? '').' '.($t['description'] ?? ''))))
            ->all();

        $discriminating = $words->filter(function (string $word) use ($haystacks, $n): bool {
            $hits = 0;
            foreach ($haystacks as $haystack) {
                if (str_contains($haystack, $word)) {
                    $hits++;
                }
            }

            return $hits / $n <= 0.7; // drop words present in >70% of tools
        })->values();

        return $discriminating->isNotEmpty() ? $discriminating : $words;
    }

    /**
     * PRIORITISE the tools a prompt names as its subject over mixing in
     * off-subject ones. When the catalog NAMES tools after a prompt subject
     * (get-nps-* for "nps"), the chosen set is reordered so those dedicated tools
     * lead and fill the slots first — the model's subject picks, then MORE
     * dedicated subject tools, and only leftover room (subject tools exhausted)
     * keeps the model's off-subject picks. So an "nps" board fills with get-nps-*
     * before any ticket tool; mixing is allowed, but only after the subject is
     * covered. The model's tool COUNT (breadth) is preserved — never expanded.
     *
     * Everything keys off tool NAMES, never descriptions: a subject word counts
     * only if it NAMES some tool (not ~all — that's the org term), so a ticket
     * tool that merely MENTIONS nps in its description (because it returns an nps
     * field) is not treated as a dedicated nps tool.
     *
     * @param  list<string>  $noRows  tools known to return no rows (excluded)
     */
    private function preferOnTopicTools(ExpressContext $context, array $noRows): void
    {
        if ($context->chosenTools === []) {
            return;
        }
        $words = $this->topicWords($context->prompt);
        if ($words->isEmpty()) {
            return;
        }

        $known = array_values(array_diff(array_column($context->catalogTools, 'name'), $noRows));
        $names = collect($known)->map(fn (string $name): string => Str::lower(Str::ascii($name)))->all();
        $count = count($names);
        if ($count === 0) {
            return;
        }

        // Subject words that NAME some (but not ~all) tools: "nps" names the
        // get-nps-* tools; "yuhu" names every tool (the org term) and is dropped;
        // "informacion" names none and is dropped.
        $subjects = $words->filter(function (string $word) use ($names, $count): bool {
            $hits = collect($names)->filter(fn (string $n): bool => str_contains($n, $word))->count();

            return $hits > 0 && $hits / $count <= 0.7;
        })->values();
        if ($subjects->isEmpty()) {
            return; // no subject is a dedicated tool here — nothing to prioritise
        }

        $isSubject = fn (string $name): bool => $subjects->contains(
            fn (string $w): bool => str_contains(Str::lower(Str::ascii($name)), $w),
        );

        $chosenSubject = collect($context->chosenTools)->filter($isSubject)->values();
        $chosenOther = collect($context->chosenTools)->reject($isSubject)->values();
        $otherSubject = collect($known)->filter($isSubject)
            ->reject(fn (string $n): bool => $chosenSubject->contains($n))->values();

        // Fill with subject tools first (chosen, then the rest the source offers),
        // and only then the model's off-subject picks — capped at its own count.
        $target = min(count($context->chosenTools), self::MAX_TOOLS);
        $prioritised = $chosenSubject->merge($otherSubject)->merge($chosenOther)
            ->unique()->take($target)->values()->all();

        $changed = array_diff($prioritised, $context->chosenTools) !== []
            || array_diff($context->chosenTools, $prioritised) !== [];
        $context->chosenTools = $prioritised;
        if ($changed) {
            $context->note('El pedido es sobre «'.$subjects->implode(', ').'»; se priorizaron los tools dedicados sobre mezclar otros: '.implode(', ', $prioritised).'.');
        }
    }

    /** Human list of what the source's tools are about, from their names. */
    private function sourceDomains(ExpressContext $context): string
    {
        $generic = ['tool', 'get', 'list', 'search', 'global', 'with', 'status', 'report', 'metrics', 'user', 'profile', 'current', 'compare', 'live', 'dimension', 'time', 'series', 'daily', 'weekly', 'overview', 'contact', 'promise', 'by'];

        return collect($context->catalogTools)
            ->flatMap(fn (array $t) => preg_split('/[-_]/', (string) $t['name']))
            ->filter(fn ($w) => mb_strlen((string) $w) >= 3 && ! in_array($w, $generic, true))
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
        // Conservative by design — this runs when the gate DEFAULTED (the model
        // didn't answer), so score on DISCRIMINATING words only: better a small
        // on-topic board than one padded with off-domain tools that merely share
        // the org name (observed: a defaulted fit_check dragging ticket tools
        // into an NPS build).
        $words = $this->discriminatingTopicWords($context);

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
