<?php

namespace App\Services\Express\Phases;

use App\Models\BuilderMessage;
use App\Models\PipelineRun;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\DomainLexicon;
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

    /** Extra enum cuts per run — each is a full acquisition, so keep it tight. */
    private const MAX_ENUM_CUTS = 2;

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

        $out = $this->economyFitSuffices($context) ? $this->economyOut($context, $run) : null;

        // The interpreter: the ask's words didn't map onto the catalog — ONE
        // small call translates the intent into the factory's vocabulary
        // (form enriched, facts never invented; shown to the user in the
        // report), then the deterministic signal gets a second chance. The
        // translation is ADOPTED only when that second check validates it —
        // a translation the signal can't ground (meta-commentary, invented
        // domains) is discarded whole: nothing shown, nothing reported, and
        // the model fit judges the user's ORIGINAL words.
        if ($out === null && (bool) config('express.economy', false)) {
            $translated = $this->interpretAsk($context, $run, $catalog);
            if ($translated !== null) {
                $context->interpretedPrompt = $translated;
                if ($this->economyFitSuffices($context)) {
                    $context->progress('Interpretando tu pedido… → «'.$translated.'»');
                    $out = $this->economyOut($context, $run);
                } else {
                    $context->interpretedPrompt = null;
                }
            }
        }

        if ($out === null) {
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
                json_encode(['pedido' => $context->analysisPrompt()], JSON_UNESCAPED_UNICODE),
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
        }

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

        // Enum-aware cuts: the ask can name ANOTHER value of an argument the
        // chosen tool already declares (its input_schema enum) — a second
        // dimension of the same data. Deterministic, so it enriches BOTH the
        // economy path and a model answer.
        $context->chosenCuts = $this->enumCuts($context);
        foreach ($context->chosenCuts as $cut) {
            $context->note('Corte adicional: '.$cut['tool'].' con '.json_encode($cut['arguments'], JSON_UNESCAPED_UNICODE));
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
            ->map(fn (array $t): string => $this->toolText($t))
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
        // Same lexicon the picker used: a tool chosen via "quejas→complaints"
        // must not be vetoed by the raw Spanish words failing the same match.
        $words = DomainLexicon::expand($this->topicWords($context->analysisPrompt()));
        if ($words->isEmpty()) {
            return false;
        }

        $byName = collect($context->catalogTools)->keyBy('name');
        foreach ($context->chosenTools as $name) {
            $tool = $byName->get($name);
            if ($tool === null) {
                continue;
            }
            $haystack = $this->toolText($tool);
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
        $words = $this->topicWords($context->analysisPrompt());
        $tools = collect($context->catalogTools);
        $n = $tools->count();
        if ($n < 3 || $words->isEmpty()) {
            return $words; // too few tools to judge ubiquity meaningfully
        }

        $haystacks = $tools
            ->map(fn (array $t): string => $this->toolText($t))
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
        $words = $this->topicWords($context->analysisPrompt());
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
     * The ask can name a value of an enum argument a chosen tool already
     * declares — a SECOND cut of the same data sitting one argument away.
     * Observed (prod yuhuticket): "distribución de motivos y la causa raíz"
     * chose get-tickets-by-dimension (default dimension: category), and the
     * CAUSE cut — listed in that tool's own input_schema enum — took a
     * human-driven agent session to discover. Match the discriminating topic
     * words against enum values (shared 4-char stem covers causa↔cause) and
     * acquire each named non-default value as an extra cut, capped.
     *
     * @return list<array{tool: string, arguments: array<string, string>, cut: string}>
     */
    private function enumCuts(ExpressContext $context): array
    {
        $words = DomainLexicon::expand($this->discriminatingTopicWords($context));
        if ($words->isEmpty()) {
            return [];
        }

        $cuts = [];
        foreach ($context->chosenTools as $toolName) {
            $tool = collect($context->catalogTools)->firstWhere('name', $toolName);
            $props = is_array($tool['input_schema']['properties'] ?? null) ? $tool['input_schema']['properties'] : [];
            $defaults = is_array($tool['arguments'] ?? null) ? $tool['arguments'] : [];

            foreach ($props as $arg => $schema) {
                $enum = array_values(array_filter((array) ($schema['enum'] ?? []), 'is_string'));
                if (count($enum) < 2) {
                    continue;
                }
                $rawDefault = $defaults[$arg] ?? ($schema['default'] ?? '');
                // An authored default can be an ARRAY (multi-value args) —
                // treat it as "no single default": every named enum value is
                // then a legitimate extra cut. Casting it crashed a prod run.
                $default = is_scalar($rawDefault) ? (string) $rawDefault : '';

                foreach ($enum as $value) {
                    if (strcasecmp($value, $default) === 0) {
                        continue; // the default cut is already acquired via chosenTools
                    }
                    if ($this->wordsNameValue($words, $value)) {
                        $cuts[] = ['tool' => (string) $toolName, 'arguments' => [(string) $arg => $value], 'cut' => $value];
                    }
                }
            }
        }

        return array_slice($cuts, 0, self::MAX_ENUM_CUTS);
    }

    /**
     * Economy's honesty backstop: with no model to declare substitutions, say
     * plainly which asked topics the BUILT board does not cover — available
     * in a tool the fit did not choose ("pídelo aparte"), or nowhere in this
     * source. Concept-wise lexicon matching, same as the fit signal.
     *
     * @param  list<string>  $chosenTools
     */
    private function auditCoverage(ExpressContext $context, array $chosenTools): void
    {
        $concepts = $this->discriminatingTopicWords($context)
            ->reject(fn (string $w): bool => preg_match(self::FORM_WORDS, $w) === 1)
            ->values();
        if ($concepts->isEmpty()) {
            return;
        }

        $byName = collect($context->catalogTools)->keyBy('name');
        $hayOf = fn (array $t): string => $this->toolText($t);
        $chosenHay = collect($chosenTools)
            ->map(fn (string $n): string => $hayOf($byName->get($n) ?? []))
            ->implode(' ');

        foreach ($concepts as $concept) {
            $variants = DomainLexicon::expand(collect([$concept]));
            $inChosen = $variants->contains(fn (string $v): bool => str_contains($chosenHay, $v));
            if ($inChosen) {
                continue;
            }
            $elsewhere = collect($context->catalogTools)->first(
                fn (array $t): bool => $variants->contains(fn (string $v): bool => str_contains($hayOf($t), $v)),
            );
            $context->coverageNotes[] = $elsewhere !== null
                ? "**{$concept}** quedó fuera de este tablero — lo cubre `{$elsewhere['name']}`; pídelo aparte y lo agrego."
                : "**{$concept}** no aparece en los datos de esta conexión.";
        }
    }

    /**
     * Scalar-safe "name + description" text of a catalog tool — MCP servers
     * occasionally ship structured descriptions, and concatenating an array
     * is a fatal mid-pipeline.
     *
     * @param  array<string, mixed>  $tool
     */
    private function toolText(array $tool): string
    {
        $name = is_scalar($tool['name'] ?? null) ? (string) $tool['name'] : '';
        $description = is_scalar($tool['description'] ?? null) ? (string) $tool['description'] : json_encode($tool['description'] ?? '');

        return Str::lower(Str::ascii($name.' '.$description));
    }

    /**
     * Does any discriminating word NAME this enum value? Exact, containment,
     * or a shared 4-character stem — the cross-language cases this exists for
     * (causa↔cause, prioridad↔priority) share their stem.
     *
     * @param  Collection<int, string>  $words
     */
    private function wordsNameValue(Collection $words, string $value): bool
    {
        $v = Str::lower(Str::ascii($value));
        if (mb_strlen($v) < 3) {
            return false;
        }

        return $words->contains(function (string $w) use ($v): bool {
            if ($w === $v || str_contains($v, $w) || str_contains($w, $v)) {
                return true;
            }

            return mb_strlen($w) >= 4 && mb_strlen($v) >= 4 && substr($w, 0, 4) === substr($v, 0, 4);
        });
    }

    /**
     * FORM words name how to draw, not what to read — "pareto de causas"
     * asks for causes IN pareto form, and requiring "pareto" to match a tool
     * would defeat the signal for exactly the asks the intent vocabulary (and
     * the interpreter) serve. Filtered out of the coverage checks only; the
     * suggester still reads them from the prompt.
     */
    private const FORM_WORDS = '/^(pareto|acumulad\w*|concentraci\w*|top\d*|ranking|mayores|principales|distribuci\w*|proporci\w*|participaci\w*|reparto|share|compar\w*|versus|vs|embudo|funnel|heatmap|mapa|calor|meta|objetivo|target|goal|ejecutiv\w*|executive|overview|resumen|detalle|grafic\w*|chart\w*)$/iu';

    /**
     * The economy path's single exit: record the gate as an economy skip,
     * take the deterministic fit, audit what the chosen tools do not cover.
     *
     * @return array{tools: list<string>, substitutions: array, unanswerable: array, core_unanswerable: bool}
     */
    private function economyOut(ExpressContext $context, PipelineRun $run): array
    {
        $context->economyMode = true;
        $run->recordGate('fit_check', [
            'model' => null,
            'latency_ms' => 0,
            'fallback_used' => false,
            'tokens' => null,
            'error' => null,
            'economy' => true,
        ]);
        $out = $this->heuristicDefault($context);
        $this->auditCoverage($context, $out['tools'] ?? []);

        return $out;
    }

    /**
     * The INTERPRETER gate: one small call that translates a vague ask into
     * the factory's precise vocabulary — enriching the FORM (pareto, top,
     * tendencia, embudo…), never the FACTS (no targets, numbers or dimensions
     * the user didn't say), constrained to the catalog's actual domains. When
     * the ask arrived through a builder conversation the user's PREVIOUS
     * turns ride along — their own words ("quiero un dashboard no un app"
     * carries no topic; the turn before it named the tickets), so the
     * translation can recover the topic without inventing anything. Returns
     * the candidate translation or null; the CALLER adopts it only if the
     * deterministic signal validates it.
     */
    private function interpretAsk(ExpressContext $context, PipelineRun $run, array $catalog): ?string
    {
        $domains = collect($catalog)
            ->map(fn (array $t): string => $t['name'].' — '.Str::limit((string) ($t['description'] ?? ''), 90, '…'))
            ->take(30)->implode("\n");

        $result = $this->gates->run(
            $run,
            'interpret',
            <<<'TXT'
Traduces el PEDIDO difuso de un dashboard al vocabulario preciso del
constructor, en el idioma del usuario. REGLAS: (1) enriquece la FORMA, nunca
los HECHOS — jamás inventes metas, números, umbrales ni dimensiones que el
usuario no dijo; los mensajes_previos_del_usuario son palabras del usuario y
cuentan: úsalos para recuperar el TEMA cuando el pedido actual no lo trae;
(2) usa SOLO los dominios presentes en el catálogo — no prometas datos que no
existen; (3) cuando la intención lo amerite usa estas palabras de forma:
pareto / % acumulado, top N, distribución, compara, embudo, mapa de calor,
tendencia semanal/diaria/mensual, resumen ejecutivo; (4) responde UNA sola
frase imperativa («crea un dashboard de …»); (5) si el pedido ya es preciso,
devuélvelo tal cual. PROHIBIDO devolver comentarios, evaluaciones o
descripciones del pedido («el pedido es demasiado difuso», «no especifica…»):
pedido_interpretado es SIEMPRE una orden de dashboard construible — si ni con
los mensajes previos puedes formular una fiel al usuario, devuelve ''.
TXT,
            json_encode([
                'pedido' => $context->prompt,
                'mensajes_previos_del_usuario' => $this->previousUserTurns($run, $context->prompt),
            ], JSON_UNESCAPED_UNICODE),
            fn ($schema) => [
                'pedido_interpretado' => $schema->string()->description('El pedido reescrito como orden imperativa de dashboard, una sola frase, mismo idioma — o cadena vacía. Nunca una evaluación del pedido.'),
            ],
            ['pedido_interpretado' => ''],
            $context->user,
            $context->modelOverride,
            $context,
            stableContext: 'DOMINIOS DEL CATÁLOGO:'."\n".$domains,
        );

        $translated = trim((string) ($result['output']['pedido_interpretado'] ?? ''));
        if ($result['fallback_used'] || $translated === '' || Str::lower($translated) === Str::lower(trim($context->prompt))) {
            return null;
        }

        return Str::limit($translated, 400, '');
    }

    /**
     * The user's most recent turns in the builder conversation this run came
     * from (excluding the triggering prompt itself) — the interpreter's only
     * extra context, and still exclusively the user's own words.
     *
     * @return list<string>
     */
    private function previousUserTurns(PipelineRun $run, string $prompt): array
    {
        if ($run->conversation_id === null) {
            return [];
        }

        return BuilderMessage::query()
            ->where('conversation_id', $run->conversation_id)
            ->where('role', 'user')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(6)->pluck('content')
            ->map(fn ($c): string => trim((string) $c))
            ->reject(fn (string $c): bool => $c === '' || $c === trim($prompt))
            ->take(3)->reverse()->values()->all();
    }

    /**
     * Economy mode's gate-skip signal: the deterministic fit SUFFICES when
     * every discriminating topic word of the ask hits at least one catalog
     * tool (no potentially-unanswerable piece — the honest-halt case stays
     * with the model) and the on-topic tool set is small enough to take
     * whole (no ambiguity for a model to arbitrate). A/B observed: on such
     * asks the gated and ungated builds are near-identical, so the ~2-60s
     * and the paid calls buy nothing.
     */
    private function economyFitSuffices(ExpressContext $context): bool
    {
        if (! (bool) config('express.economy', false)) {
            return false;
        }

        $concepts = $this->discriminatingTopicWords($context)
            ->reject(fn (string $w): bool => preg_match(self::FORM_WORDS, $w) === 1)
            ->values();
        if ($concepts->isEmpty()) {
            return false; // nothing to match on — let the model read the ask
        }

        $haystacks = collect($context->catalogTools)
            ->map(fn (array $t): string => $this->toolText($t));

        // Concept-wise: a topic is COVERED when the word OR any of its lexicon
        // translations hits ("quejas" is covered via "complaints"); an
        // uncovered concept is the possible-unanswerable case only the model
        // can halt honestly.
        $variantsOf = fn (string $w) => DomainLexicon::expand(collect([$w]));
        $unmatched = $concepts->filter(
            fn (string $w): bool => ! $haystacks->contains(
                fn (string $h): bool => $variantsOf($w)->contains(fn (string $v): bool => str_contains($h, $v)),
            ),
        );
        $allVariants = DomainLexicon::expand($concepts);
        $onTopic = $haystacks->filter(
            fn (string $h): bool => $allVariants->contains(fn (string $w): bool => str_contains($h, $w)),
        )->count();

        return $unmatched->isEmpty() && $onTopic > 0 && $onTopic <= self::MAX_TOOLS;
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
        // Flat lexicon expansion: scoring counts hits, so translations only
        // ever ADD signal ("quejas" scores via "complaints").
        $words = DomainLexicon::expand($this->discriminatingTopicWords($context));

        $noRows = collect($context->knownShapes)
            ->filter(fn (array $shape): bool => ($shape['fields'] ?? null) === [])
            ->keys()->all();

        $scored = collect($context->catalogTools)
            ->reject(fn (array $t): bool => in_array($t['name'], $noRows, true))
            ->map(function (array $tool) use ($words): array {
                $haystack = $this->toolText($tool);
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
