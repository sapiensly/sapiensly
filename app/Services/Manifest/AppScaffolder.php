<?php

namespace App\Services\Manifest;

use App\Ai\ChatAgent;
use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiUsageRecorder;
use App\Services\AiProviderService;
use App\Services\Express\SemanticProfile;
use App\Support\Icons\IconCatalog;
use App\Support\Locale\Inflector;
use App\Support\Locale\SemanticLexicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

/**
 * Turns a natural-language app description into a complete, valid App manifest
 * in ONE step — the alternative to create_app + a long chain of hand-written
 * RFC 6902 patches, which the model frequently gets wrong.
 *
 * The model only produces a small, constrained spec (objects + their fields);
 * the manifest itself — ids, a CRUD page per object (heading + "new" button +
 * create modal/form + table) and the wiring between them — is assembled
 * deterministically here, so the result ALWAYS passes validation. The author
 * then refines it on the canvas or via propose_change.
 */
class AppScaffolder
{
    /** Hard caps so one scaffold can never balloon into an unmanageable manifest. */
    private const MAX_OBJECTS = 6;

    /**
     * Figures the description can ask for by name. Past four the dashboard
     * stops leading with an answer and becomes a wall of numbers.
     */
    private const MAX_FOCUS_MEASURES = 4;

    /**
     * A real fact table legitimately runs wide (a weekly-ops object had 21 columns).
     * The old cap of 12 silently dropped the rest, so an add_object that asked for 21
     * fields saved 12 with a success response and no warning — the surviving loop only
     * noted coercions for fields it kept. Raised to a bound no honest object hits, and
     * normalizeFields now emits a coercion when it truncates so it can never be silent;
     * the typed add_object entry validates `max` and errors instead of dropping.
     */
    private const MAX_FIELDS_PER_OBJECT = 40;

    private const MAX_OPTIONS = 8;

    /**
     * Rows a list page loads, and how many it shows at once.
     *
     * The ceiling is what the browser sorts and searches over, so it is set
     * where a person's whole object usually fits; past it the table says so
     * rather than pretending the page is the object.
     */
    private const LIST_PAGE_ROW_LIMIT = 400;

    private const LIST_PAGE_SIZE = 25;

    private const MAX_LINKS = 8;

    /**
     * Field types the model may request; anything else is coerced to `string`.
     *
     * email/url/phone belong here because they need nothing but the base
     * id/slug/name/type — exactly like `string`, which is what they used to be
     * silently downgraded to. That downgrade cost every generated app its
     * contact-data validation: the SYSTEM prompt below asks the model for them
     * ("they validate the format and render the right input"), the model DID
     * answer `"type":"email"`, and this list threw it away — so a help desk
     * accepted "esto-no-es-un-correo" as the requester's address. Every
     * generative path funnels through here (scaffold_app over MCP and the
     * in-app builder's cold start both call normalizeSpec), so one omission
     * disabled the type across the product.
     */
    private const ALLOWED_TYPES = [
        'string', 'email', 'url', 'phone', 'long_text', 'number', 'currency',
        'boolean', 'date', 'datetime', 'single_select', 'multi_select', 'rating',
    ];

    /**
     * The full field type set the typed add_field tool accepts (beyond the scaffold
     * subset above): the advanced types that previously forced raw RFC 6902 patches.
     */
    private const TYPED_FIELD_TYPES = [
        'string', 'email', 'url', 'phone', 'long_text', 'number', 'currency',
        'boolean', 'date', 'datetime', 'single_select', 'multi_select', 'rating',
        'slider', 'date_range', 'file', 'rich_text', 'relation', 'formula',
        'lookup', 'rollup',
    ];

    /**
     * Optional base props (from field_base) accepted on any field via `config`.
     */
    private const BASE_OPTIONAL_PROPS = [
        'description', 'required', 'unique', 'indexed', 'readonly', 'hidden', 'help_text',
    ];

    /**
     * Type-specific props copied from a field's `config` into its definition. The
     * manifest validator enforces required/typed correctness on the result; this
     * just whitelists what each type may carry so a typed add_field is as capable
     * as a hand-written patch.
     *
     * Every prop here is one `list_available_field_types` ALREADY promises the
     * model — that catalog is the contract, and a prop it advertises which this
     * map omits is not an unsupported feature, it is a request accepted, dropped
     * and reported as done. Keep the two in step: adding a prop to the catalog
     * without adding it here re-creates exactly that.
     *
     * @var array<string, list<string>>
     */
    private const FIELD_CONFIG_PROPS = [
        // `capture` is the whole point of the mobile-capture fields, and it was
        // missing here on both types that take it — so a typed add_field could
        // not produce a scannable, camera or signature field at all, only a raw
        // RFC 6902 patch could. A build asked to make a SKU scannable therefore
        // got a plain text box and reported success.
        'string' => ['min_length', 'max_length', 'pattern', 'default', 'capture'],
        'long_text' => ['max_length', 'default', 'capture'],
        // These four had no entry at all, so they carried base props only — the
        // catalog offers each of them a default, and the text-like three a
        // max_length. The contact picker is a capture on both of the two that
        // hold something a phone's address book already knows.
        'email' => ['default', 'max_length', 'capture'],
        'url' => ['default', 'max_length'],
        'phone' => ['default', 'max_length', 'capture'],
        'color' => ['default'],
        // `display` is a real rendering switch the runtime reads (a boolean as a
        // toggle, a select as a radio group), advertised in the catalog and
        // unreachable through the typed path until now.
        'single_select' => ['default', 'display'],
        'multi_select' => ['default'],
        'number' => ['min', 'max', 'precision', 'format', 'default', 'capture'],
        'currency' => ['currency_code', 'min', 'max', 'default'],
        'boolean' => ['default', 'display'],
        'date' => ['default'],
        'datetime' => ['default'],
        'rating' => ['max', 'default', 'icon'],
        'slider' => ['min', 'max', 'step', 'default', 'format', 'currency_code'],
        'date_range' => ['include_time', 'default'],
        // A point has nothing to configure except WHEN it is taken.
        'geo' => ['capture'],
        'file' => ['max_size_mb', 'mime_types', 'capture', 'stamp'],
        'rich_text' => ['default', 'max_length'],
        'relation' => ['target_object_id', 'cardinality', 'on_delete', 'inverse_field_id'],
        'formula' => ['expression', 'return_type', 'currency_code'],
        'lookup' => ['via_relation_field_id', 'target_field_id'],
        'rollup' => ['via_relation_field_id', 'aggregator', 'target_field_id', 'filter'],
    ];

    /** Read-only computed types — shown in tables, never in create forms. */
    private const DERIVED_TYPES = ['rollup', 'lookup', 'formula'];

    /** Max charts of each kind (breakdown / trend / value-bar) on the scaffolded dashboard. */
    private const DASHBOARD_CHART_CAP = 4;

    /**
     * A dashboard is an editorial artifact, so it gets a budget.
     *
     * Generated per object × per metric it came to 19 blocks for a six-object
     * app — ten KPIs, five charts, four sparklines, some four thousand pixels
     * of scrolling before a single record existed. Nobody would lay that out on
     * purpose. These are the number of things worth putting on a first screen.
     */
    private const DASHBOARD_BREAKDOWN_CAP = 2;

    private const DASHBOARD_TREND_CAP = 1;

    private const DASHBOARD_VALUE_BAR_CAP = 1;

    /** The app description is one line in a list of apps; past this it is truncated with an ellipsis. */
    private const MAX_SUMMARY_LENGTH = 180;

    /** Max KPI cards in the dashboard's opening metric_grid — the headline few, not a wall. */
    private const DASHBOARD_KPI_CAP = 5;

    /** Rows a dashboard chart/sparkline loads so its client-side buckets reflect a real trend. */
    private const DASHBOARD_ROW_LIMIT = 500;

    /**
     * A restrained, readable palette assigned (by position) to single/multi-select
     * options so status chips and kanban columns are colour-coded out of the box
     * instead of all-grey.
     */
    private const OPTION_COLORS = ['#0ea5e9', '#f59e0b', '#16a34a', '#8b5cf6', '#ef4444', '#14b8a6', '#ec4899', '#64748b'];

    private const SYSTEM = <<<'SYS'
        You design simple internal business apps as a set of data objects (like database tables) with fields, and the links between them.
        Given a description, respond with ONLY a single minified JSON object — no markdown, no code fences, no commentary — using exactly this schema:
        {"summary":string,"objects":[{"name":string,"slug":string,"fields":[{"name":string,"slug":string,"type":"string"|"email"|"url"|"phone"|"long_text"|"number"|"currency"|"boolean"|"date"|"datetime"|"single_select"|"multi_select"|"rating","options":[{"value":string,"label":string}]|null}]}],"links":[{"from":string,"to":string,"name":string,"type":"belongs_to"|"many_to_many"}]|null,"focus":{"objects":[string],"measures":[{"object":string,"field":string,"aggregation":"sum"|"avg"|"count"}]}|null}
        Rules:
        - summary: ONE short sentence, at most 180 characters, answering only WHAT this app is for — the job it does and for whom, in the language of the description. Not how it does it, not what it stores, no feature list, no "this app allows you to". "Tracks rental contracts, their payments and the incidents on each property." is the whole shape of it. The screens, the fields and the automation are documented elsewhere; this is the line someone reads in a list of apps to know which one to open.
        - objects: the main entities the app tracks (e.g. for a content engine: Ideas, Drafts, Published). At most 6. Each needs a human `name` and a snake_case `slug`.
        - fields: the columns of each object. At most 12 per object. Each needs a `name`, a snake_case `slug`, and a `type`. Give every object a short text title/name field FIRST.
        - STAY GROUNDED: only include fields the description actually implies or that are obviously essential to the entity. Do NOT pad objects with invented or generic extra fields — fewer, relevant fields beat a long speculative list.
        - type: use "string" for short text, "email"/"url"/"phone" for those contact fields (they validate the format and render the right input), "long_text" for paragraphs, "number" for quantities/counts, "currency" for money/prices/amounts, "boolean" for yes/no, "date"/"datetime" for dates, "single_select"/"multi_select" for a fixed set of choices, "rating" for 1-5 stars. There is NO id/foreign-key type — never add a field to hold another object's id or name; express that as a link.
        - options: REQUIRED and non-empty ONLY for single_select / multi_select (each option a short `value` slug + a human `label`); use null for every other type. Add a status/stage single_select whenever the entity moves through states (it becomes a board) — e.g. order: pending/preparing/served/paid.
        - links: relationships between objects. Default `type` "belongs_to" means "a <from> belongs to one <to>" (e.g. {"from":"drafts","to":"ideas","name":"idea"} = each Draft belongs to one Idea). Use `type` "many_to_many" for a symmetric link where BOTH sides hold many (e.g. {"from":"scenes","to":"cast","type":"many_to_many"} = a scene features many cast AND a cast appears in many scenes) — give it once per pair, it builds a picker on both. `from`/`to` are object slugs; `name` is the human label on the <from> side. Use null when there are no relationships. At most 8.
        - NEVER restate a relationship as a field: do not add a string/number field that holds a related record's name or id (e.g. on a line item do NOT add a "product"/"category" text field) — model it with a link instead. The relation, its picker, child counts and totals are generated for you.
        - Model line-item / amount structures as a parent with a child linked to it (e.g. an order/ticket with its line items, each line a currency field): the child's amount then rolls up to a total on the parent automatically. Do not add a manual "total" field to the parent — it is derived.
        - focus: what the person asked to SEE, which is not the same as what the app stores. Read the description for the questions it wants answered ("I need to know what is owed this month", "which incidents are still open") and name them here. `objects` lists the object slugs those questions are about, most important first — the dashboard is built around the first one and spends its charts in this order. `measures` names the specific figures asked for: `object` and `field` are slugs YOU defined above (a `field` must exist on that `object`), `aggregation` is "sum"/"avg" for a number or currency field and "count" for how many records. At most 4 measures. Use null when the description only describes what to store and asks for nothing in particular — do not invent an interest the description does not show.
        - Write names/labels in the SAME language as the description — and that includes every `slug`, which is a snake_case echo of the name beside it, never a translation of it. A Spanish app is served at /duenos and /mascotas; "Dueños" with the slug "owner" puts English in the URL of an app that has none anywhere else.
        SYS;

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
        private readonly SemanticProfile $semantics = new SemanticProfile,
    ) {}

    /**
     * Build a complete manifest: start from the app's initial (empty but valid)
     * manifest, then fold in the objects + CRUD pages derived from the model's
     * spec. The returned manifest is assembled to always be schema-valid.
     *
     * @param  array<string, mixed>  $baseManifest  the app's initial manifest (schema_version, id, slug, name, version, permissions, settings)
     * @param  list<string>  $coercions  Out: every change made to the model's spec to keep it valid. The caller is expected to SHOW these — a scaffold that quietly downgrades a field leaves the author believing they got what they asked for.
     * @return array<string, mixed>
     *
     * @throws ScaffoldFailedException when the model that designs the app cannot be reached
     */
    public function scaffold(array $baseManifest, string $description, ?User $user = null, array &$coercions = []): array
    {
        $appId = ($id = (string) ($baseManifest['id'] ?? '')) !== '' ? $id : null;
        $spec = $this->extractSpec($description, $user, $coercions, $appId);
        $spec['request'] = $description;

        return $this->assemble($baseManifest, $spec);
    }

    /**
     * @param  list<string>  $coercions  Out: notes for any change made to stay valid.
     * @param  string|null  $appId  The app this spend belongs to, so it lands in get_build_cost.
     * @return array{objects: array<int, array{name: string, slug: string, fields: array<int, array<string, mixed>>}>}
     */
    private function extractSpec(string $description, ?User $user, array &$coercions = [], ?string $appId = null): array
    {
        // Load the tenant's AI provider credentials into runtime config, the same
        // as every other AI service does (ChatAiService, BuilderAiService,
        // GateRunner, …). The web request path gets this from the
        // InjectAiProviderConfig middleware, but the MCP route's middleware stack
        // does NOT — so without this call scaffold_app works from the in-app
        // builder yet SILENTLY fails over MCP (no API key on Lab::Anthropic → the
        // model call throws → caught below → an empty, object-less app).
        if ($user !== null) {
            $this->providers->applyRuntimeConfig($user);
        }

        $model = $this->aiDefaults->model('flows');
        $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;

        try {
            $agent = new ChatAgent(instructions: self::SYSTEM, messages: [], tools: []);
            $response = $agent->prompt(Str::limit($description, 2000), provider: $provider, model: $model, timeout: (int) config('ai.request_timeout', 180));

            // Creating an app is a billable model call like any other, and it was
            // the only one in the product that reached nobody: not the tenant
            // meter, not the platform ledger, not get_build_cost — which reported
            // $0 for every scaffolded app while promising "every model call
            // tagged with this app". An org with its AI budget spent could still
            // scaffold, because nothing counted this. Best-effort, like every
            // other call site: accounting must not cost the caller their app.
            try {
                app(AiUsageRecorder::class)->record(
                    'scaffold', $model, $user, $user?->organization_id,
                    $response->usage ?? null,
                    appId: $appId,
                );
            } catch (\Throwable) {
                // Usage accounting is best-effort.
            }

            $spec = $this->normalizeSpec($this->decodeJson((string) ($response->text ?? '')), $coercions);
        } catch (\Throwable $e) {
            Log::warning('App scaffold: model call failed', ['error' => $e->getMessage()]);

            // Loudly. Swallowing this returned an object-less spec, and the
            // caller went on to save it: "app created" with nothing in it, the
            // real cause visible only in the log. That is precisely how a
            // missing API key on the MCP route shipped as a feature that
            // "worked" — see the credentials comment above, which exists
            // because of it. An app the user has to discover is empty is worse
            // than an error that says why.
            throw new ScaffoldFailedException(
                'the model that designs the app could not be reached: '.$e->getMessage(),
                previous: $e,
            );
        }

        // An app with no objects is not an app.
        //
        // The catch above refuses when the model cannot be REACHED, and this
        // used to be treated as the opposite case: a model that ANSWERS "there
        // is nothing here" has answered, so the empty app shipped. From the
        // caller's side the distinction does not survive contact — a benchmark
        // run described a dental clinic and got back an app with zero objects,
        // no dashboard, and not one word about it, reported as created.
        //
        // Outside the try on purpose, or the catch relabels it "the model could
        // not be reached", which is the one thing it was not.
        if ($spec['objects'] === []) {
            throw new ScaffoldFailedException(
                'the model did not describe a single object for this app. Its '
                .'reply could not be read as a design — try again, or name the '
                .'entities the app should hold more plainly.',
            );
        }

        return $spec;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $json = trim($raw);
        $json = (string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $json);
        if (preg_match('/\{.*\}/s', $json, $m)) {
            $json = $m[0];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Coerce a loose {objects, links} spec (from the model, or the in-app
     * scaffold tool) into the normalized shape `assemble()` consumes — slugs
     * derived + unique, types coerced, options normalized, links validated.
     * Public so the in-app builder tool can scaffold a full app (relations +
     * derived economics + recipe screens) from the same pipeline as MCP.
     *
     * @param  array<string, mixed>|null  $decoded
     * @param  list<string>  $coercions  Out: notes for every downgrade applied here.
     * @return array{objects: array<int, array{name: string, slug: string, fields: array<int, array<string, mixed>>}>, links: array<int, array{from: string, to: string, name: ?string}>}
     */
    public function normalizeSpec(?array $decoded, array &$coercions = []): array
    {
        $rawObjects = is_array($decoded['objects'] ?? null) ? $decoded['objects'] : [];

        // Over the cap, the extras are dropped — say so. Fields already report
        // their own truncation a few lines down, and an object going missing is
        // the more consequential of the two: a whole entity the description
        // asked for, and every field on it, gone from a result that otherwise
        // reads as a success.
        if (count($rawObjects) > self::MAX_OBJECTS) {
            $dropped = array_slice($rawObjects, self::MAX_OBJECTS);
            $names = array_values(array_filter(array_map(
                fn ($o): string => is_array($o) ? trim((string) ($o['name'] ?? '')) : '',
                $dropped,
            )));
            $coercions[] = count($rawObjects).' objects were described, over the '
                .self::MAX_OBJECTS.' limit — '
                .(count($names) > 0 ? '"'.implode('", "', $names).'" were' : count($dropped).' were')
                .' left out, with every field on them. Add them with add_object, or fold their fields into an object that stayed.';
        }

        $objects = [];
        $usedObjectSlugs = [];
        foreach (array_slice($rawObjects, 0, self::MAX_OBJECTS) as $i => $object) {
            if (! is_array($object)) {
                continue;
            }

            $name = trim((string) ($object['name'] ?? '')) ?: ('Object '.($i + 1));
            $slug = $this->uniqueSlug($object['slug'] ?? $name, $usedObjectSlugs, 'object_'.($i + 1));
            $usedObjectSlugs[] = $slug;

            $fields = $this->normalizeFields(is_array($object['fields'] ?? null) ? $object['fields'] : [], $coercions);
            if ($fields === []) {
                // Never emit a field-less object — the table/form would be empty.
                $fields[] = ['name' => 'Name', 'slug' => 'name', 'type' => 'string', 'options' => null];
            }

            $objects[] = ['name' => $name, 'slug' => $slug, 'fields' => $fields];
        }

        $links = $this->normalizeLinks($decoded['links'] ?? null, $usedObjectSlugs, $coercions);
        $this->dropRestatedRelations($objects, $links, $coercions);

        return [
            'summary' => $this->normalizeSummary($decoded['summary'] ?? null),
            'objects' => $objects,
            'links' => $links,
            'focus' => $this->normalizeFocus($decoded['focus'] ?? null, $objects, $coercions),
        ];
    }

    /**
     * The one line that answers "what is this app for?".
     *
     * The app's description used to BE the brief — the whole two thousand
     * characters someone typed to have it built, headings and bullet lists and
     * all, printed under the app's name in every list. That text is an
     * instruction, not a description: it says what to build, at the length it
     * takes to build it. What the reader of a list needs is the one sentence
     * that tells them which app to open.
     *
     * Kept to a sentence here rather than trusted to the prompt: a model asked
     * for one short sentence returns three about a third of the time, and this
     * is rendered in a fixed-height card.
     */
    private function normalizeSummary(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        // One paragraph: a model that answered in several returns the first.
        $summary = trim((string) preg_replace('/\s*\n\s*\n.*$/su', '', trim($raw)));
        $summary = trim((string) preg_replace('/\s+/u', ' ', $summary));

        if ($summary === '') {
            return null;
        }

        if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            // Cut at the last sentence that fits, so the line still ends
            // somewhere a person would end it; failing that, at a word.
            $head = mb_substr($summary, 0, self::MAX_SUMMARY_LENGTH);
            $cut = max(mb_strrpos($head, '. ') ?: 0, mb_strrpos($head, '.') === mb_strlen($head) - 1 ? mb_strlen($head) - 1 : 0);
            $summary = $cut > self::MAX_SUMMARY_LENGTH / 2
                ? rtrim(mb_substr($head, 0, $cut + 1))
                : rtrim(mb_substr($head, 0, (int) (mb_strrpos($head, ' ') ?: self::MAX_SUMMARY_LENGTH))).'…';
        }

        return $summary;
    }

    /**
     * What the app is for, when the model did not say.
     *
     * Names the objects it holds, in the app's language. Mechanical, and
     * deliberately so — it is the floor under a missing summary, not a
     * substitute for one, and a mechanical sentence still beats printing the
     * two-thousand-character brief.
     *
     * @param  array<int, array{name: string}>  $objects
     */
    private function summaryFromObjects(array $objects, string $lang): string
    {
        $names = array_slice(array_values(array_filter(array_map(
            fn (array $o): string => trim((string) ($o['name'] ?? '')),
            $objects,
        ))), 0, 4);

        if ($names === []) {
            return '';
        }

        $and = ['en' => 'and', 'es' => 'y', 'pt' => 'e', 'fr' => 'et'][$lang] ?? 'and';
        $list = count($names) === 1
            ? $names[0]
            : implode(', ', array_slice($names, 0, -1)).' '.$and.' '.end($names);

        $template = [
            'en' => 'Keeps track of {list}.',
            'es' => 'Lleva el control de {list}.',
            'pt' => 'Controla {list}.',
            'fr' => 'Assure le suivi de {list}.',
        ][$lang] ?? 'Keeps track of {list}.';

        return str_replace('{list}', $list, $template);
    }

    /**
     * A relationship stated twice: once as a link, once as a text field holding
     * the related record's name.
     *
     * The prompt forbids it and the model does it anyway — an application to a
     * vacancy came back with a `candidato` link AND a `candidato_nombre`
     * string, which the relation field then collided with: two columns headed
     * "Candidato", one of them a copy that goes stale the moment the candidate
     * is renamed. Only the exact restatements are cut (the link's own name, or
     * that name with a naming suffix), so a genuinely different field that
     * merely starts with the same word survives.
     *
     * @param  list<array{name: string, slug: string, fields: list<array<string, mixed>>}>  $objects
     * @param  list<array{from: string, to: string, name: ?string}>  $links
     * @param  list<string>  $coercions
     */
    private function dropRestatedRelations(array &$objects, array $links, array &$coercions): void
    {
        $suffixes = ['', '_nombre', '_name', '_nome', '_nom', '_id', '_titulo', '_title', '_clave', '_key'];

        foreach ($links as $link) {
            $base = Str::snake(Str::ascii((string) ($link['name'] ?? '')));
            if ($base === '') {
                continue;
            }
            $restatements = array_map(fn (string $s): string => $base.$s, $suffixes);

            foreach ($objects as $oi => $object) {
                if ($object['slug'] !== $link['from']) {
                    continue;
                }
                foreach ($object['fields'] as $fi => $field) {
                    // Only scalars: a real relation field is not a restatement.
                    if (! in_array($field['type'] ?? null, ['string', 'number'], true)) {
                        continue;
                    }
                    if (! in_array((string) $field['slug'], $restatements, true)) {
                        continue;
                    }

                    unset($objects[$oi]['fields'][$fi]);
                    $coercions[] = sprintf(
                        '"%s" on %s repeated the "%s" link as a text field — dropped, because the link already shows that record and a copy of its name goes stale the moment it is renamed.',
                        $field['name'] ?? $field['slug'], $object['name'], $link['name'],
                    );
                }
                $objects[$oi]['fields'] = array_values($objects[$oi]['fields']);
            }
        }
    }

    /**
     * What the description asked to SEE, kept only where it resolves.
     *
     * The dashboard used to be derived from structure alone, so a request for
     * "what is owed this month and which incidents are open" produced one that
     * mentioned neither Pagos nor Incidencias — every object got a count, and
     * the charts, which are capped, were spent on whatever came first. Reading
     * intent is the one part of this the model is better at than a rule.
     *
     * Composition stays here, and so does the truth: a focus naming an object
     * or field that does not exist is dropped and reported, never guessed at.
     * A model that hallucinates a `monto_total` it never defined must not
     * silently produce a KPI over nothing.
     *
     * @param  list<array{name: string, slug: string, fields: list<array<string, mixed>>}>  $objects
     * @param  list<string>  $coercions
     * @return array{objects: list<string>, measures: list<array{object: string, field: string, aggregation: string}>}
     */
    private function normalizeFocus(mixed $raw, array $objects, array &$coercions): array
    {
        $empty = ['objects' => [], 'measures' => []];
        if (! is_array($raw)) {
            return $empty;
        }

        $fieldsBySlug = [];
        foreach ($objects as $object) {
            $fieldsBySlug[$object['slug']] = collect($object['fields'])
                ->keyBy(fn (array $f): string => (string) $f['slug'])
                ->all();
        }

        $focusObjects = [];
        foreach (is_array($raw['objects'] ?? null) ? $raw['objects'] : [] as $slug) {
            $slug = is_string($slug) ? $slug : '';
            if (isset($fieldsBySlug[$slug]) && ! in_array($slug, $focusObjects, true)) {
                $focusObjects[] = $slug;
            }
        }

        $measures = [];
        $unresolved = [];
        foreach (is_array($raw['measures'] ?? null) ? $raw['measures'] : [] as $measure) {
            if (! is_array($measure) || count($measures) >= self::MAX_FOCUS_MEASURES) {
                continue;
            }

            $objectSlug = trim((string) ($measure['object'] ?? ''));
            $fieldSlug = trim((string) ($measure['field'] ?? ''));
            $aggregation = trim((string) ($measure['aggregation'] ?? 'count'));
            $field = $fieldsBySlug[$objectSlug][$fieldSlug] ?? null;

            if ($field === null) {
                $unresolved[] = $objectSlug.'.'.$fieldSlug;

                continue;
            }
            if (! in_array($aggregation, ['sum', 'avg', 'count'], true)) {
                $aggregation = 'count';
            }
            // Summing a name is not a figure. Demote rather than drop: the
            // reader still asked about this field, and "how many" is an
            // answerable version of the question.
            if (in_array($aggregation, ['sum', 'avg'], true)
                && ! in_array($field['type'] ?? null, ['number', 'currency', 'rating'], true)) {
                $aggregation = 'count';
            }

            $measures[] = ['object' => $objectSlug, 'field' => $fieldSlug, 'aggregation' => $aggregation];
        }

        if ($unresolved !== []) {
            $coercions[] = 'The dashboard was asked to show '.implode(', ', $unresolved)
                .', which no object defines — left out rather than guessed at.';
        }

        return ['objects' => $focusObjects, 'measures' => $measures];
    }

    /**
     * Keep only links whose endpoints are real, distinct objects.
     *
     * @param  array<int, string>  $objectSlugs
     * @return array<int, array{from: string, to: string, name: ?string}>
     */
    private function normalizeLinks(mixed $rawLinks, array $objectSlugs, array &$coercions = []): array
    {
        if (! is_array($rawLinks)) {
            return [];
        }

        // A relationship dropped in silence is a whole side of the model gone:
        // no picker on the form, no children on the detail page, no rollup —
        // and the objects both still there, so nothing looks missing.
        if (count($rawLinks) > self::MAX_LINKS) {
            $dropped = array_slice($rawLinks, self::MAX_LINKS);
            $named = array_values(array_filter(array_map(
                fn ($l): string => is_array($l)
                    ? trim((string) ($l['from'] ?? '')).' → '.trim((string) ($l['to'] ?? ''))
                    : '',
                $dropped,
            )));
            $coercions[] = sprintf(
                '%d relationships were described, over the %d limit — %s left out. Add them with add_relation.',
                count($rawLinks),
                self::MAX_LINKS,
                $named !== [] ? implode(', ', $named).' were' : count($dropped).' were',
            );
        }

        $links = [];
        $seen = [];
        foreach (array_slice($rawLinks, 0, self::MAX_LINKS) as $link) {
            if (! is_array($link)) {
                continue;
            }
            $from = $this->toSlug((string) ($link['from'] ?? ''));
            $to = $this->toSlug((string) ($link['to'] ?? ''));

            // A link is belongs-to by default; the model marks a symmetric link
            // `many_to_many` (a scene features many cast; a cast appears in many
            // scenes). Any other value falls back to belongs-to.
            $rawType = $this->toSlug((string) ($link['type'] ?? $link['cardinality'] ?? ''));
            $isM2M = in_array($rawType, ['many_to_many', 'manytomany', 'm2m'], true);

            // Belongs-to is directional (from→to ≠ to→from); many-to-many is
            // symmetric, so dedup it on the unordered pair to avoid a double link.
            $key = $isM2M ? 'm2m:'.min($from, $to).'|'.max($from, $to) : $from.'->'.$to;
            if ($from === $to || ! in_array($from, $objectSlugs, true) || ! in_array($to, $objectSlugs, true) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $name = trim((string) ($link['name'] ?? ''));
            $links[] = [
                'from' => $from,
                'to' => $to,
                'name' => $name !== '' ? $name : null,
                'type' => $isM2M ? 'many_to_many' : 'belongs_to',
            ];
        }

        return $links;
    }

    /**
     * Coerce one loose field spec into the normalized, always-valid shape,
     * keeping its slug unique against $takenSlugs. Used when adding a single
     * field to an existing object.
     *
     * Unlike normalizeFields() (the scaffold/add_object path, restricted to the
     * basic type subset), this is the typed add_field path: it accepts the full
     * field type set and preserves a `config` bag of type-specific props. A select
     * with no options still degrades to plain text, and an unrecognised type still
     * falls back to `string` — the validator catches malformed advanced configs.
     *
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  $takenSlugs
     * @param  list<string>  $coercions  Out: notes for any change made to stay valid.
     * @return array{name: string, slug: string, type: string, options: array<int, array{value: string, label: string}>|null, config: array<string, mixed>|null}|null
     */
    public function normalizeField(array $field, array $takenSlugs = [], array &$coercions = []): ?array
    {
        $name = trim((string) ($field['name'] ?? ''));
        if ($name === '') {
            $name = 'Field';
        }
        $requestedSlug = isset($field['slug']) ? (string) $field['slug'] : null;
        $slug = $this->uniqueSlug($requestedSlug ?? $name, $takenSlugs, 'field');
        if ($requestedSlug !== null && $requestedSlug !== '' && $slug !== $requestedSlug) {
            $coercions[] = "field \"{$name}\": slug adjusted to \"{$slug}\".";
        }

        $requestedType = (string) ($field['type'] ?? 'string');
        $type = in_array($requestedType, self::TYPED_FIELD_TYPES, true) ? $requestedType : 'string';
        if ($type !== $requestedType) {
            $coercions[] = "field \"{$name}\": type \"{$requestedType}\" is not a known field type — used \"string\".";
        }

        $options = null;
        if (in_array($type, ['single_select', 'multi_select'], true)) {
            $options = $this->normalizeOptions($field['options'] ?? null, $name, $coercions);
            if ($options === []) {
                // A select with no options is invalid — degrade to free text.
                $coercions[] = "field \"{$name}\": {$type} needs a non-empty options array — used plain text instead.";
                $type = 'string';
                $options = null;
            }
        }

        $config = is_array($field['config'] ?? null) ? $field['config'] : null;

        return ['name' => $name, 'slug' => $slug, 'type' => $type, 'options' => $options, 'config' => $config];
    }

    /**
     * @param  array<int, mixed>  $rawFields
     * @param  list<string>  $coercions  Out: human-readable notes for each spec the
     *                                   scaffolder had to change to stay valid.
     * @return array<int, array{name: string, slug: string, type: string, options: array<int, array{value: string, label: string}>|null}>
     */
    public function normalizeFields(array $rawFields, array &$coercions = []): array
    {
        $fields = [];
        $usedSlugs = [];
        if (count($rawFields) > self::MAX_FIELDS_PER_OBJECT) {
            $dropped = count($rawFields) - self::MAX_FIELDS_PER_OBJECT;
            $coercions[] = 'object has '.count($rawFields).' fields, over the '.self::MAX_FIELDS_PER_OBJECT." limit — the last {$dropped} were dropped. Split them across a second object or remove some.";
        }
        foreach (array_slice($rawFields, 0, self::MAX_FIELDS_PER_OBJECT) as $i => $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? '')) ?: ('Field '.($i + 1));
            $slug = $this->uniqueSlug($field['slug'] ?? $name, $usedSlugs, 'field_'.($i + 1));

            $requestedType = (string) ($field['type'] ?? 'string');
            $type = in_array($requestedType, self::ALLOWED_TYPES, true) ? $requestedType : 'string';
            if ($type !== $requestedType) {
                $coercions[] = "field \"{$name}\": type \"{$requestedType}\" is not available here — used \"string\".";
            }

            $options = null;
            if (in_array($type, ['single_select', 'multi_select'], true)) {
                $options = $this->normalizeOptions($field['options'] ?? null, $name, $coercions);
                if ($options === []) {
                    // A select with no options is invalid — degrade to free text.
                    $coercions[] = "field \"{$name}\": {$type} needs a non-empty options array — used plain text instead.";
                    $type = 'string';
                    $options = null;
                }
            }

            // This path is deliberately the BASIC type subset, so a `config`
            // bag reaches it only by mistake — and dropping one in silence is
            // how an app ends up with a plain `sku` when a scannable one was
            // asked for. Every run of one field-service brief ended with the
            // closing critic reporting exactly that.
            if (is_array($field['config'] ?? null) && $field['config'] !== []) {
                $coercions[] = "field \"{$name}\": settings like ".implode('/', array_slice(array_keys($field['config']), 0, 3))
                    .' are not applied when an object is created — add the field, then use add_field to set them.';
            }

            $usedSlugs[] = $slug;
            $fields[] = ['name' => $name, 'slug' => $slug, 'type' => $type, 'options' => $options];
        }

        return $fields;
    }

    /**
     * @param  list<string>  $coercions  Out: a note when choices had to be dropped.
     * @return array<int, array{value: string, label: string}>
     */
    private function normalizeOptions(mixed $options, string $fieldName = '', array &$coercions = []): array
    {
        if (! is_array($options)) {
            return [];
        }

        // A ninth state on a select is not a rare edge — "reportada, en
        // revisión, asignada, en reparación, esperando refacción, resuelta,
        // cerrada, cancelada, reabierta" is an ordinary lifecycle. Losing one
        // in silence means records can never reach it and nobody is told why.
        if (count($options) > self::MAX_OPTIONS) {
            $dropped = array_slice($options, self::MAX_OPTIONS);
            $labels = array_values(array_filter(array_map(
                fn ($o): string => is_string($o) ? $o : (is_array($o) ? trim((string) ($o['label'] ?? $o['value'] ?? '')) : ''),
                $dropped,
            )));
            $coercions[] = sprintf(
                'field "%s" listed %d choices, over the %d limit — %s left out. Add them with add_field, or fold them into the ones that stayed.',
                $fieldName !== '' ? $fieldName : 'select',
                count($options),
                self::MAX_OPTIONS,
                $labels !== [] ? '"'.implode('", "', $labels).'" were' : count($dropped).' were',
            );
        }

        $normalized = [];
        $usedValues = [];
        foreach (array_slice($options, 0, self::MAX_OPTIONS) as $i => $option) {
            // Accept a plain string ("Activo") or a {value,label} object.
            if (is_string($option)) {
                $option = ['label' => $option, 'value' => $option];
            }
            if (! is_array($option)) {
                continue;
            }
            $label = trim((string) ($option['label'] ?? $option['value'] ?? '')) ?: ('Option '.($i + 1));
            $value = $this->uniqueSlug($option['value'] ?? $label, $usedValues, 'option_'.($i + 1));
            $usedValues[] = $value;
            $normalized[] = ['value' => $value, 'label' => $label];
        }

        return $normalized;
    }

    /**
     * Slugify to ^[a-z][a-z0-9_]*$, keeping it unique within $taken.
     *
     * @param  array<int, string>  $taken
     */
    private function uniqueSlug(mixed $raw, array $taken, string $fallback): string
    {
        $slug = $this->toSlug((string) $raw);
        if ($slug === '') {
            $slug = $this->toSlug($fallback) ?: 'field';
        }
        $slug = Str::limit($slug, 50, '');

        $base = $slug;
        $n = 2;
        while (in_array($slug, $taken, true)) {
            $slug = Str::limit($base, 47, '').'_'.$n++;
        }

        return $slug;
    }

    /**
     * Slugify to ^[a-z][a-z0-9_]*$ (empty string if nothing usable remains).
     */
    private function toSlug(string $raw): string
    {
        // Transliterate accents to ASCII first (Str::ascii) so "garantías" →
        // "garantias", not "garant_as" (the í would otherwise collapse to _).
        $slug = trim((string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower(Str::ascii($raw))), '_');
        if ($slug !== '' && ! preg_match('/^[a-z]/', $slug)) {
            $slug = 'f_'.$slug;
        }

        return $slug;
    }

    /**
     * Deterministically assemble objects, the belongs-to relations between them,
     * a CRUD page each (with a kanban board when the object has a status field),
     * and a dashboard landing page, into the base manifest.
     *
     * @param  array<string, mixed>  $base
     * @param  array{objects: array<int, array{name: string, slug: string, fields: array<int, array<string, mixed>>}>, links?: array<int, array{from: string, to: string, name: ?string}>}  $spec
     * @return array<string, mixed>
     */
    public function assemble(array $base, array $spec): array
    {
        $currency = (string) ($base['settings']['default_currency'] ?? 'MXN');
        $lang = self::langForLocale($base['settings']['default_locale'] ?? null);
        // Absent on a spec assembled by hand (the in-app builder's cold start,
        // the templates, every existing test) — those keep the structural
        // dashboard they had.
        $focus = is_array($spec['focus'] ?? null)
            ? $spec['focus'] + ['objects' => [], 'measures' => []]
            : ['objects' => [], 'measures' => []];

        // Pass 1: build every object so all ids exist before relations wire them.
        $built = [];
        $indexBySlug = [];
        foreach ($spec['objects'] as $object) {
            [$objectDef, $fieldIndex] = $this->buildObject($object, $currency, $lang);
            $indexBySlug[$objectDef['slug']] = count($built);
            // pageFields drives the object's page; the many-side relation field is
            // appended to it, the one-side (inverse) field is structural only.
            $built[] = ['def' => $objectDef, 'pageFields' => $fieldIndex];
        }

        // Pass 2: each link becomes a bidirectional relation pair (many_to_one on
        // the `from` object + its one_to_many inverse on the `to` object). We also
        // record, per parent, the child relationships so pass 4 can build a
        // master-detail page (the parent record + its children) for it.
        $childrenByParent = [];
        $relationsByChild = [];
        foreach ($spec['links'] ?? [] as $link) {
            $fromIndex = $indexBySlug[$link['from']] ?? null;
            $toIndex = $indexBySlug[$link['to']] ?? null;
            if ($fromIndex === null || $toIndex === null || $fromIndex === $toIndex) {
                continue;
            }

            // Many-to-many: a symmetric picker on each object, no parent/child and
            // no POS/master-detail bookkeeping (that is a belongs-to concern).
            if (($link['type'] ?? 'belongs_to') === 'many_to_many') {
                $m2m = $this->buildManyToMany($built[$fromIndex]['def'], $built[$toIndex]['def'], $link['name'], $lang);
                $built[$fromIndex]['def']['fields'][] = $m2m['from_field'];
                $built[$fromIndex]['pageFields'][] = $m2m['from_index'];
                $built[$toIndex]['def']['fields'][] = $m2m['to_field'];
                $built[$toIndex]['pageFields'][] = $m2m['to_index'];

                continue;
            }

            $pair = $this->buildRelation($built[$fromIndex]['def'], $built[$toIndex]['def'], $link['name'], $lang);
            $built[$fromIndex]['def']['fields'][] = $pair['child_field'];
            $built[$fromIndex]['pageFields'][] = $pair['child_index'];
            // The one_to_many inverse is structural (not on the page); the rollup
            // count is shown as a column on the parent's table.
            $built[$toIndex]['def']['fields'][] = $pair['parent_field'];
            $built[$toIndex]['def']['fields'][] = $pair['parent_rollup_field'];
            $built[$toIndex]['pageFields'][] = $pair['parent_rollup_index'];
            // A derived total of the child's money field, when it has one.
            if ($pair['parent_sum_field'] !== null) {
                $built[$toIndex]['def']['fields'][] = $pair['parent_sum_field'];
                $built[$toIndex]['pageFields'][] = $pair['parent_sum_index'];
            }

            $childrenByParent[$toIndex][] = [
                'childIndex' => $fromIndex,
                'childFieldId' => $pair['child_field']['id'],
                'childFieldSlug' => $pair['child_field']['slug'],
            ];
            // For POS detection: every belongs-to the `from` (line) object owns,
            // with the FK field on it and the inverse one_to_many on the target.
            $relationsByChild[$fromIndex][] = [
                'targetIndex' => $toIndex,
                'childFieldId' => $pair['child_field']['id'],
                'childFieldSlug' => $pair['child_field']['slug'],
                'parentFieldId' => $pair['parent_field']['id'],
            ];
        }

        // Pass 2.5: detect a POS-shaped triad (an order ← line → priced product)
        // and synthesise the line economics (unit price lookup, subtotal formula)
        // + the order total rollup so a generated POS screen actually computes.
        $posSpecs = $this->detectAndBuildPosEconomics($built, $relationsByChild, $currency, $lang, (string) ($spec['request'] ?? ''));

        // Pass 2.6: the same arithmetic, for lines that are not a sale. A part
        // used on a work order has a quantity and a unit cost, so its line total
        // is derivable — but the POS pass needs an order←line→product TRIAD, so
        // it never fires here and the total was left as a number to type in by
        // hand, next to the two numbers it is the product of.
        $this->synthesizeLineTotals($built, $currency, $lang);
        $this->dropUnitPriceTotals($built, $lang);

        // Pass 3: a list page per object (now that relation fields exist) —
        // except for the line items, which are not places anyone navigates to.
        //
        // "Refacción Usada" had a page of its own and an entry in the menu, and
        // a part used on a work order is not a concept you go and look at: it
        // exists inside its order, where the detail page already lists and adds
        // them. The test is the one that decides a line total is derivable —
        // quantity AND unit price — because that is exactly what makes a row a
        // line rather than a thing.
        $objects = [];
        $objectPages = [];
        $forDashboard = [];
        foreach ($built as $i => $entry) {
            $objects[] = $entry['def'];
            $forDashboard[] = [
                'name' => $entry['def']['name'],
                'id' => $entry['def']['id'],
                'slug' => $entry['def']['slug'],
                'fieldIndex' => $entry['pageFields'],
            ];

            if ($this->isLineItem($entry['def'], $relationsByChild[$i] ?? [], $lang)) {
                continue;
            }

            $objectPages[$i] = $this->buildPage(['name' => $entry['def']['name'], 'slug' => $entry['def']['slug']], $entry['def']['id'], $entry['pageFields'], $lang);
        }

        // Pass 4: a page for the record itself — every object that has a list
        // of its own gets one, whether or not anything hangs off it. A parent
        // also gets, per child relationship, an inline "add child" form and a
        // related_list. Either way the list table gains an "open" row action.
        //
        // It used to be parents only, and that quietly decided who could be
        // deleted: Delete is a button on this page (the only control the schema
        // lets us gate by role), so an object with no children — a Mecánicos, a
        // Categorías — could be created and then never removed by any route in
        // its own app.
        //
        // Line items are still excluded, for the opposite reason rather than by
        // oversight: they have no list page either, and they are edited and
        // removed from the related list on the parent they belong to.
        $detailPages = [];
        $usedSlugs = array_column($objectPages, 'slug');
        foreach (array_keys($objectPages) as $parentIndex) {
            $rels = $childrenByParent[$parentIndex] ?? [];
            $parent = $built[$parentIndex];
            $detailSlug = $this->uniqueSlug($parent['def']['slug'].'_detail', $usedSlugs, 'detail');
            $usedSlugs[] = $detailSlug;

            $children = array_map(fn (array $rel): array => [
                'def' => $built[$rel['childIndex']]['def'],
                'pageFields' => $built[$rel['childIndex']]['pageFields'],
                'childFieldId' => $rel['childFieldId'],
                'childFieldSlug' => $rel['childFieldSlug'],
            ], $rels);

            $detailPages[] = $this->buildDetailPage($parent['def'], $parent['pageFields'], $detailSlug, $children, $lang);
            $this->addRowActionToTable($objectPages[$parentIndex], $detailSlug, $lang);
        }

        // Pass 5: a POS-style screen (product grid + live cart) for each triad.
        $posPages = [];
        // NOT `$spec` — that is the caller's spec, still needed thirty lines
        // below for the summary fallback. Shadowing it here crashed every
        // scaffold where the POS module fired AND the fallback was reached
        // (a long brief with no model-written summary) with an unhelpful
        // "Undefined array key objects". Found from the outside: three
        // shop-flavoured briefs failed through `scaffold_app` in a row while a
        // field-service one went through, because the latter builds no POS
        // pages and so never reached the shadowing.
        foreach ($posSpecs as $posSpec) {
            $posPages[] = $this->buildPosPage($posSpec, $lang, $usedSlugs);
            $usedSlugs[] = end($posPages)['slug'];
        }

        $pages = [...$posPages, ...array_values($objectPages), ...$detailPages];

        // A dashboard landing page summarising every object goes first so it is
        // the app's home. Only worth it once there is something to summarise.
        if ($forDashboard !== []) {
            $dashboardSlug = $this->uniqueSlug('dashboard', array_column($pages, 'slug'), 'dashboard');
            array_unshift($pages, $this->buildDashboard($base['name'] ?? 'Dashboard', $dashboardSlug, $forDashboard, $lang, $focus));
        }

        $base['objects'] = $objects;
        $base['pages'] = $pages;

        // The description the app carries from here on. `initialManifest` seeded
        // it with the brief (clamped to 500 chars mid-word); this replaces it
        // with the line that says what the app is for. The caller mirrors it
        // back onto the App row — see ScaffoldAppTool.
        //
        // A summary the model wrote always wins: it was asked for exactly this.
        // The mechanical fallback only displaces a description that is already
        // too long to be one — the in-app builder assembles over a manifest
        // whose description may have been written by hand, and "Keeps track of
        // A, B and C." is not an improvement on that.
        $summary = trim((string) ($spec['summary'] ?? ''));
        if ($summary === '' && mb_strlen(trim((string) ($base['description'] ?? ''))) > self::MAX_SUMMARY_LENGTH) {
            $summary = $this->summaryFromObjects($spec['objects'], $lang);
        }
        if ($summary !== '') {
            $base['description'] = $summary;
        }

        return $this->ensureObjectPolicies($base);
    }

    /**
     * State what each role may do, for every object, so the roles the scaffold
     * ships are not decorative.
     *
     * A generated app declared `admin` and `user` and not one policy, and an app
     * with no object policies is open-within-visibility: every member could
     * delete every record, and the role picker in the Access panel promised a
     * distinction the app did not have. Naming a role Admin and giving it
     * nothing is worse than shipping one role, because it reads as configured.
     *
     * The matrix must stay COMPLETE: once an object has any policy, a role with
     * no entry on it gets nothing (deny-by-default, per object). So every role
     * gets a row on every object — except the roles a public portal hands to
     * strangers, which are deny-by-default ON PURPOSE and must never be widened
     * by automation.
     *
     * Existing rows are never touched: an author who narrowed a role keeps it,
     * and a new object inherits what that role already has elsewhere.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function ensureObjectPolicies(array $manifest): array
    {
        $roles = $manifest['permissions']['roles'] ?? [];
        $objects = $manifest['objects'] ?? [];
        if ($roles === [] || $objects === []) {
            return $manifest;
        }

        $public = $manifest['permissions']['public'] ?? [];
        $portalRoleIds = array_filter([$public['role_id'] ?? null, $public['member_role_id'] ?? null]);

        $policies = array_values($manifest['permissions']['object_policies'] ?? []);
        $covered = [];
        foreach ($policies as $policy) {
            $covered[(string) ($policy['object_id'] ?? '')] = true;
        }

        foreach ($objects as $object) {
            if (isset($covered[$object['id']])) {
                continue;
            }
            foreach ($roles as $role) {
                if (in_array($role['id'] ?? null, $portalRoleIds, true)) {
                    continue;
                }
                $policies[] = [
                    'object_id' => $object['id'],
                    'role_id' => $role['id'],
                    'actions' => $this->actionsForRole($role, $policies),
                ];
            }
        }

        $manifest['permissions']['object_policies'] = $policies;

        return $manifest;
    }

    /**
     * What a role may do on an object it has no policy for yet: whatever it
     * already holds elsewhere in this app — so a role an author narrowed stays
     * narrow as the app grows — else the meaning of its name. `admin` is the
     * only role the scaffold grants delete: everyone else works the records
     * (create/read/update) without being able to erase them.
     *
     * @param  array<string, mixed>  $role
     * @param  list<array<string, mixed>>  $existing
     * @return list<string>
     */
    private function actionsForRole(array $role, array $existing): array
    {
        $held = [];
        foreach ($existing as $policy) {
            if (($policy['role_id'] ?? null) === ($role['id'] ?? null)) {
                $held = array_merge($held, $policy['actions'] ?? []);
            }
        }
        if ($held !== []) {
            return array_values(array_unique($held));
        }

        return ($role['slug'] ?? null) === 'admin'
            ? ['create', 'read', 'update', 'delete']
            : ['create', 'read', 'update'];
    }

    /**
     * @param  array{name: string, slug: string, fields: array<int, array<string, mixed>>}  $object
     * @return array{0: array<string, mixed>, 1: array<int, array{id: string, slug: string}>}
     */
    public function buildObject(array $object, string $currency, string $lang = 'en'): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = [];
        $fieldIndex = [];

        foreach ($object['fields'] as $field) {
            [$definition, $indexEntry] = $this->buildField($this->typeForCapture($field, $lang), $currency);
            $fields[] = $definition;
            $fieldIndex[] = $indexEntry;
        }

        $this->applyIntegrityDefaults($fields, $fieldIndex, $lang);

        // What this object is CALLED. Without it every reader downstream has to
        // guess — the label on a relation cell, a card's title, a picker's rows
        // — and they each guess separately.
        $title = $this->titleField($fieldIndex);

        // Plural, because everything that shows this name shows MANY records:
        // the list page, its nav entry, the count KPI, the first crumb of the
        // detail page. The singular is derived where it belongs — "Agregar
        // Cliente", the detail page itself — which is also what stopped the
        // breadcrumb reading "Cliente › Cliente".
        return [array_filter([
            'id' => $this->id('obj'),
            'slug' => $object['slug'],
            'name' => Inflector::plural($object['name'], $lang),
            'primary_display_field_id' => $title['id'] ?? null,
            'fields' => $fields,
        ], fn ($v): bool => $v !== null), $fieldIndex];
    }

    /**
     * The two guarantees a generated object owes its own screens.
     *
     * The model is never asked for `required` or `default` — its spec is
     * name/slug/type/options — so every scaffolded app used to accept a record
     * with EVERY field null. That is not a hypothetical: on a freshly generated
     * help desk, a ticket saved blank and a ticket saved with no status, and the
     * board groups by exactly that field, so the card lands outside all four
     * columns and the donut counts a slice with no name.
     *
     * Deterministic on purpose. Asking the model for these would spend tokens on
     * a judgement that has one right answer:
     *   - the field that LABELS the record (same rule as titleField) is required
     *     — a row whose title is blank is unreadable in every table and card;
     *   - the field the kanban groups by (the first single_select, same rule as
     *     buildKanban) defaults to its first option — that is what "new" means.
     * Only the grouping select: a default on `priority` would be an opinion
     * about the business, not a structural need. An explicit value from the
     * typed add_field path always wins.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private function applyIntegrityDefaults(array &$fields, array $fieldIndex = [], string $lang = 'en'): void
    {
        $titleIndex = null;
        foreach ($fields as $i => $field) {
            if ($titleIndex === null && ($field['type'] ?? null) === 'string') {
                $titleIndex = $i;
            }
        }
        // Mirrors titleField(): the first string, else whatever labels the record.
        $titleIndex ??= array_key_first($fields);

        if ($titleIndex !== null && ! array_key_exists('required', $fields[$titleIndex])) {
            $fields[$titleIndex]['required'] = true;
        }

        // The STATUS field, not the first select. Defaulting by position put
        // "preventivo" on every work order — a classification nobody chose — and
        // left the actual `estado` empty, so each new order opened outside the
        // board grouped by it. An object with no status gets no default at all.
        $status = $this->statusField($fieldIndex, $lang);
        if ($status === null) {
            return;
        }

        foreach ($fields as $i => $field) {
            if (($field['id'] ?? null) !== $status['id'] || array_key_exists('default', $field)) {
                continue;
            }
            $first = $field['options'][0]['value'] ?? null;
            if (is_string($first) && $first !== '') {
                $fields[$i]['default'] = $first;
            }
        }
    }

    /**
     * Build one field definition + its index entry from a normalized field spec.
     *
     * @param  array{name: string, slug: string, type: string, options?: array<int, array{value: string, label: string}>|null}  $field
     * @return array{0: array<string, mixed>, 1: array{id: string, slug: string, type: string, name: string, option_labels: list<string>}}
     */
    /**
     * Type a capture from what it is CALLED, before anything renders it.
     *
     * R11 already knows that a field named `firma`, `foto` or `ubicacion` held
     * in a string cannot store what its name promises — it says so, in those
     * words, as a design warning. But the scaffolder types fields on the basic
     * subset and had no way to emit `file` + capture, so it produced exactly the
     * defect its own linter then reported: measured on the benchmark suite, one
     * app in nine came back with a photo held as text.
     *
     * Same lexicon, same three categories, one step earlier. The rule that
     * judges the field now decides its type, so the warning has nothing left to
     * fire on.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function typeForCapture(array $field, string $lang): array
    {
        // `url` belongs here beside the text types: a photo typed as a link
        // cannot be taken on the spot either, and the model reaches for `url`
        // on a field called «Foto de evidencia» about as often as it reaches
        // for `string`. The weblink exclusion below is what keeps an actual
        // link field — «Foto URL» — a url.
        if (! in_array((string) ($field['type'] ?? ''), ['string', 'long_text', 'url'], true)) {
            return $field;
        }

        $lex = SemanticLexicon::for($lang);
        $words = [(string) ($field['name'] ?? ''), (string) ($field['slug'] ?? '')];

        if ($lex->matches('signature', ...$words)) {
            return ['type' => 'file', 'config' => ['capture' => 'signature']] + $field;
        }

        // `foto_url` is a LINK to an image, not a capture — the same exclusion
        // R11 makes, and for the same reason: in Spanish the `image` category
        // contains 'foto', so excluding on it would swallow the rule whole.
        if ($lex->matches('snapshot', ...$words) && ! $lex->matches('weblink', ...$words)) {
            return ['type' => 'file', 'config' => ['capture' => 'camera']] + $field;
        }

        if ($lex->matches('geopoint', ...$words)) {
            return ['type' => 'geo'] + $field;
        }

        return $field;
    }

    public function buildField(array $field, string $currency): array
    {
        $fieldId = $this->id('fld');
        $type = $field['type'];
        $definition = [
            'id' => $fieldId,
            'slug' => $field['slug'],
            'name' => $field['name'],
            'type' => $type,
        ];

        // Type-specific + base optional props the typed add_field path passes in a
        // `config` bag (absent on scaffold-built fields, so this is a no-op there).
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $allowedProps = array_merge(self::BASE_OPTIONAL_PROPS, self::FIELD_CONFIG_PROPS[$type] ?? []);
        foreach ($allowedProps as $prop) {
            if (array_key_exists($prop, $config)) {
                $definition[$prop] = $config[$prop];
            }
        }

        // Currency defaults to the app's currency when not set explicitly.
        if ($type === 'currency' && ! isset($definition['currency_code'])) {
            $definition['currency_code'] = $currency;
        }

        if (in_array($type, ['single_select', 'multi_select'], true)) {
            $colors = self::OPTION_COLORS;
            $definition['options'] = array_values(array_map(fn (int $i, array $opt): array => [
                'id' => $this->id('opt'),
                'value' => $opt['value'],
                'label' => $opt['label'],
                // Colour-code chips/kanban columns by position unless one was given.
                'color' => $opt['color'] ?? $colors[$i % count($colors)],
            ], array_keys($field['options'] ?? []), array_values($field['options'] ?? [])));
        }

        // Computed fields (formula/lookup/rollup) must be read-only per the schema.
        if (in_array($type, self::DERIVED_TYPES, true)) {
            $definition['readonly'] = true;
        }

        // The index carries the field's NAME and its option labels, not just its
        // type: the generators below decide what a field MEANS (a status? a
        // classification? a date you schedule against?) and a type alone cannot
        // tell them apart — `estado` and `tipo_contrato` are both a select with
        // options.
        return [$definition, [
            'id' => $fieldId,
            'slug' => $field['slug'],
            'type' => $type,
            'name' => $field['name'] ?? $field['slug'],
            'option_labels' => array_values(array_map(
                fn (array $o): string => (string) ($o['label'] ?? $o['value'] ?? ''),
                $definition['options'] ?? [],
            )),
            // The pair, for anything that has to FILTER on this field: a select
            // control writes the stored value and shows the label, and
            // option_labels alone cannot say what gets stored.
            'options' => $definition['options'] ?? [],
        ]];
    }

    /**
     * Build a bidirectional belongs-to relation pair: a many_to_one field on the
     * `from` object pointing at `to`, plus its one_to_many inverse on `to`. Both
     * carry inverse_field_id so lookups/rollups work later. Returns the two field
     * definitions and the from-side index entry (so the page can show it).
     *
     * Also creates a rollup on the `to` side that counts its children, so the
     * relationship pays off immediately (e.g. a "Drafts" count on each Idea), and
     * — when the child has a money field — a second rollup that SUMS it, so a
     * parent total (e.g. an order's total from its line amounts) is derived rather
     * than entered by hand.
     *
     * @param  array{id: string, name: string, slug: string, fields: array<int, array<string, mixed>>}  $from  the "many" side (a $from belongs to one $to)
     * @param  array{id: string, name: string, slug: string, fields: array<int, array<string, mixed>>}  $to  the "one" side
     * @return array{child_field: array<string, mixed>, parent_field: array<string, mixed>, child_index: array{id: string, slug: string, type: string}, parent_rollup_field: array<string, mixed>, parent_rollup_index: array{id: string, slug: string, type: string}, parent_sum_field: array<string, mixed>|null, parent_sum_index: array{id: string, slug: string, type: string}|null}
     */
    public function buildRelation(array $from, array $to, ?string $name = null, string $lang = 'en'): array
    {
        $childFieldId = $this->id('fld');
        $parentFieldId = $this->id('fld');
        $rollupFieldId = $this->id('fld');

        $relName = ($name !== null && trim($name) !== '') ? trim($name) : Inflector::singular($to['name'], $lang);
        $relSlug = $this->uniqueSlug($relName, array_column($from['fields'], 'slug'), 'related');

        // Inverse + rollup both land on the `to` object — keep their slugs unique
        // against each other as well as the existing fields.
        $parentTaken = array_column($to['fields'], 'slug');
        $inverseSlug = $this->uniqueSlug($from['slug'], $parentTaken, 'related');
        $parentTaken[] = $inverseSlug;
        $rollupSlug = $this->uniqueSlug($from['slug'].'_count', $parentTaken, 'count');

        $childField = [
            'id' => $childFieldId,
            'slug' => $relSlug,
            'name' => $relName,
            'type' => 'relation',
            'target_object_id' => $to['id'],
            'cardinality' => 'many_to_one',
            // A belongs-to that survives deleting its parent: the link is nulled,
            // the child record stays.
            'on_delete' => 'set_null',
            'inverse_field_id' => $parentFieldId,
        ];

        $parentField = [
            'id' => $parentFieldId,
            'slug' => $inverseSlug,
            'name' => $from['name'],
            'type' => 'relation',
            'target_object_id' => $from['id'],
            'cardinality' => 'one_to_many',
            'inverse_field_id' => $childFieldId,
        ];

        // Counts the children through the one_to_many side (which carries
        // inverse_field_id — required for a rollup to resolve).
        $rollupField = [
            'id' => $rollupFieldId,
            'slug' => $rollupSlug,
            // NOT the child object's name, which the relation field beside it
            // already carries: an object ended up with two fields both called
            // "Sede", rendering as two identical column headers with nothing to
            // tell them apart. This one is the count.
            'name' => $this->labelCountOf($lang, $from['name']),
            'type' => 'rollup',
            'via_relation_field_id' => $parentFieldId,
            'aggregator' => 'count',
            'readonly' => true,
        ];

        // If the child carries a money field, also sum it onto the parent so a
        // total is derived (e.g. an order total from its line amounts).
        $sumField = null;
        $sumIndex = null;
        $amount = $this->lineAmountField($from['fields'], $lang);
        if ($amount !== null) {
            $parentTaken[] = $rollupSlug;
            $sumFieldId = $this->id('fld');
            $sumSlug = $this->uniqueSlug($from['slug'].'_'.$amount['slug'].'_total', $parentTaken, 'total');
            // Named for the measure ("Total Renta Mensual"), except when two
            // child objects sum a like-named field onto the same parent — then
            // the measure no longer tells them apart and the child's name does.
            $sumName = $this->labelMoneyTotal(
                $lang,
                (string) ($amount['name'] ?? ''),
                $from['name'],
            );
            $takenNames = array_column($to['fields'], 'name');
            $takenNames[] = $rollupField['name'];
            if (in_array($sumName, $takenNames, true)) {
                $sumName = $this->labelTotal($lang, $from['name']);
            }
            $sumField = [
                'id' => $sumFieldId,
                'slug' => $sumSlug,
                'name' => $sumName,
                'type' => 'rollup',
                'via_relation_field_id' => $parentFieldId,
                'aggregator' => 'sum',
                'target_field_id' => $amount['id'],
                'readonly' => true,
            ];
            $sumIndex = ['id' => $sumFieldId, 'slug' => $sumSlug, 'type' => 'rollup'];
        }

        return [
            'child_field' => $childField,
            'parent_field' => $parentField,
            'child_index' => ['id' => $childFieldId, 'slug' => $relSlug, 'type' => 'relation'],
            'parent_rollup_field' => $rollupField,
            'parent_rollup_index' => ['id' => $rollupFieldId, 'slug' => $rollupSlug, 'type' => 'rollup'],
            'parent_sum_field' => $sumField,
            'parent_sum_index' => $sumIndex,
        ];
    }

    /**
     * Build a MANY-TO-MANY relation: a many_to_many picker on EACH object pointing
     * at the other, cross-linked via inverse_field_id so the runtime resolves the
     * link from either side (e.g. a Shoot Day lists its Scenes and a Scene lists
     * the Days it is shot on). Symmetric — unlike buildRelation there is no
     * "many"/"one" side and no rollup; both pickers go on their object's page.
     *
     * @param  array{id: string, name: string, slug: string, fields: array<int, array<string, mixed>>}  $from
     * @param  array{id: string, name: string, slug: string, fields: array<int, array<string, mixed>>}  $to
     * @return array{from_field: array<string, mixed>, from_index: array{id: string, slug: string, type: string}, to_field: array<string, mixed>, to_index: array{id: string, slug: string, type: string}}
     */
    public function buildManyToMany(array $from, array $to, ?string $name = null, string $lang = 'en'): array
    {
        $fromFieldId = $this->id('fld');
        $toFieldId = $this->id('fld');

        // The picker label is the RELATED collection (kept plural — it holds many).
        $fromName = ($name !== null && trim($name) !== '') ? trim($name) : (string) $to['name'];
        $fromSlug = $this->uniqueSlug($to['slug'], array_column($from['fields'], 'slug'), 'related');
        $toSlug = $this->uniqueSlug($from['slug'], array_column($to['fields'], 'slug'), 'related');

        $fromField = [
            'id' => $fromFieldId,
            'slug' => $fromSlug,
            'name' => $fromName,
            'type' => 'relation',
            'target_object_id' => $to['id'],
            'cardinality' => 'many_to_many',
            'inverse_field_id' => $toFieldId,
        ];

        $toField = [
            'id' => $toFieldId,
            'slug' => $toSlug,
            'name' => (string) $from['name'],
            'type' => 'relation',
            'target_object_id' => $from['id'],
            'cardinality' => 'many_to_many',
            'inverse_field_id' => $fromFieldId,
        ];

        return [
            'from_field' => $fromField,
            'from_index' => ['id' => $fromFieldId, 'slug' => $fromSlug, 'type' => 'relation'],
            'to_field' => $toField,
            'to_index' => ['id' => $toFieldId, 'slug' => $toSlug, 'type' => 'relation'],
        ];
    }

    /**
     * The first currency field in a field list (used to derive a parent total
     * from a child's amount), or null when the object tracks no money.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>|null
     */
    private function firstCurrencyField(array $fields): ?array
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? null) === 'currency') {
                return $field;
            }
        }

        return null;
    }

    /**
     * The child's money field that is worth summing onto its parent.
     *
     * Taking the FIRST currency field summed UNIT PRICES across lines: a parent
     * carrying "Total Costo Unitario", which is the addition of prices per piece
     * and answers no question anyone has. What totals is the line's own amount.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>|null
     */
    private function lineAmountField(array $fields, string $lang = 'en'): ?array
    {
        $lex = SemanticLexicon::for($lang);
        $words = fn (array $f): array => [(string) ($f['name'] ?? ''), (string) ($f['slug'] ?? '')];

        $money = array_values(array_filter(
            $fields,
            fn (array $f): bool => in_array($f['type'] ?? null, ['currency', 'formula'], true),
        ));

        // An amount-shaped field that is not a per-unit price.
        foreach ($money as $field) {
            if ($lex->matches('amount', ...$words($field)) && ! $lex->matches('unit_price', ...$words($field))) {
                return $field;
            }
        }

        // Failing that, anything that at least is not a unit price.
        foreach ($money as $field) {
            if (($field['type'] ?? null) === 'currency' && ! $lex->matches('unit_price', ...$words($field))) {
                return $field;
            }
        }

        return $this->firstCurrencyField($fields);
    }

    /**
     * One list page per object: heading + "new" button + create modal/form + table.
     *
     * @param  array{name: string, slug: string}  $object
     * @param  array<int, array{id: string, slug: string}>  $fieldIndex
     * @return array<string, mixed>
     */
    public function buildPage(array $object, string $objectId, array $fieldIndex, string $lang = 'en'): array
    {
        $modalId = $this->id('blk');
        $singular = Inflector::singular($object['name'], $lang);

        // Derived/read-only fields (rollup/lookup/formula) are computed, not
        // entered — they belong in the table but never in the create form.
        $formIndex = array_values(array_filter(
            $fieldIndex,
            fn (array $f): bool => ! in_array($f['type'] ?? 'string', self::DERIVED_TYPES, true),
        ));
        $formFields = array_map(fn (array $f): array => ['field_id' => $f['id']], $formIndex);
        $createValues = [];
        foreach ($formIndex as $f) {
            $createValues[$f['slug']] = '{{form.'.$f['slug'].'}}';
        }

        $modal = [
            'id' => $modalId,
            'type' => 'modal',
            'title' => $this->labelNew($lang, $singular),
            'blocks' => [[
                'id' => $this->id('blk'),
                'type' => 'form',
                'object_id' => $objectId,
                'mode' => 'create',
                'fields' => $formFields,
                'submit_label' => $this->labelSubmit($lang),
                'on_submit' => [
                    ['type' => 'create_record', 'object_id' => $objectId, 'values' => $createValues],
                    ['type' => 'close_modal'],
                    ['type' => 'show_toast', 'level' => 'success', 'message' => $this->toastSaved($lang, $singular)],
                    ['type' => 'refresh'],
                ],
            ]],
        ];

        $button = [
            'id' => $this->id('blk'),
            'type' => 'button',
            'label' => $this->labelNew($lang, $singular),
            'variant' => 'primary',
            'on_click' => [['type' => 'open_modal', 'modal_block_id' => $modalId]],
        ];

        // The other half of a create form: a record you cannot change after
        // saving it is a log, not an app. A row action opens a modal carrying
        // both the clicked row's id and its current values, which the edit form
        // reads back as {{params.record_id}} and seeds its inputs from — the
        // row is already in the browser, so this costs no extra read.
        $editModalId = $this->id('blk');
        $updateValues = [];
        foreach ($formIndex as $f) {
            $updateValues[$f['slug']] = '{{form.'.$f['slug'].'}}';
        }

        $editModal = [
            'id' => $editModalId,
            'type' => 'modal',
            'title' => $this->labelEditTitle($lang, $singular),
            'blocks' => [[
                'id' => $this->id('blk'),
                'type' => 'form',
                'object_id' => $objectId,
                'mode' => 'edit',
                'record_id_expression' => '{{params.record_id}}',
                'fields' => $formFields,
                'submit_label' => $this->labelSave($lang),
                'on_submit' => [
                    [
                        'type' => 'update_record',
                        'object_id' => $objectId,
                        'record_id_expression' => '{{params.record_id}}',
                        'values' => $updateValues,
                    ],
                    ['type' => 'close_modal'],
                    ['type' => 'show_toast', 'level' => 'success', 'message' => $this->toastSaved($lang, $singular)],
                    ['type' => 'refresh'],
                ],
            ]],
        ];

        // Columns follow the ranking, and everything past the cap starts folded
        // away behind the table's column picker rather than being dropped.
        $ranked = $this->rankedColumnFields($fieldIndex, $lang);
        $byId = [];
        foreach ($fieldIndex as $f) {
            $byId[$f['id']] = $f;
        }

        $columns = [];
        foreach ($ranked as $i => $fieldId) {
            if (! isset($byId[$fieldId])) {
                continue;
            }
            $column = ['id' => $this->id('col'), 'field_id' => $fieldId];
            if ($i >= self::VISIBLE_COLUMN_CAP) {
                $column['hidden_by_default'] = true;
            }
            $columns[] = $column;
        }

        // When the record has enough of its own to say, the row it was typed on
        // is not one of the six things worth the width.
        $created = ['id' => $this->id('col'), 'field_id' => 'sys_created_at', 'label_override' => $this->labelCreatedColumn($lang)];
        if (count($ranked) >= self::VISIBLE_COLUMN_CAP) {
            $created['hidden_by_default'] = true;
        }
        $columns[] = $created;
        $columns[] = [
            'id' => $this->id('col'),
            'type' => 'action',
            'label' => $this->labelEdit($lang),
            'icon' => 'pencil',
            'variant' => 'ghost',
            'on_click' => [[
                'type' => 'open_modal',
                'modal_block_id' => $editModalId,
                'params' => ['record_id' => '{{row.id}}', 'record' => '{{row.data}}'],
            ]],
        ];

        $table = [
            'id' => $this->id('blk'),
            'type' => 'table',
            'data_source' => [
                'object_id' => $objectId,
                'sort' => [['field_id' => 'sys_created_at', 'direction' => 'desc']],
                // Named, rather than left to the query layer's default of 50: a
                // list page IS the object, and stopping at fifty records with a
                // pager that only knows about fifty is how a search answered
                // "no such record" about the two hundredth one.
                'limit' => self::LIST_PAGE_ROW_LIMIT,
            ],
            'pagination' => ['page_size' => self::LIST_PAGE_SIZE],
            'columns' => $columns,
        ];

        $blocks = [
            ['id' => $this->id('blk'), 'type' => 'heading', 'content' => $object['name']],
            $modal,
            $editModal,
            $button,
        ];

        $filters = $this->buildListFilters($fieldIndex, $lang);
        if ($filters['controls'] !== []) {
            $blocks[] = [
                'id' => $this->id('blk'),
                'type' => 'filter_bar',
                'controls' => $filters['controls'],
            ];
        }

        // The alternative ways to look at the SAME rows: a board when the object
        // has a status, a month grid when it has a date you schedule against, a
        // timeline when it has a real span.
        $lex = SemanticLexicon::for($lang);
        $views = [];
        $gantt = $this->buildGantt($objectId, $fieldIndex, $lang);
        if ($gantt !== null) {
            $views[] = ['label' => $lex->label('view_timeline'), 'block' => $gantt];
        }
        $calendar = $this->buildCalendar($objectId, $fieldIndex, $lang, (string) ($object['name'] ?? ''));
        if ($calendar !== null) {
            $views[] = ['label' => $lex->label('view_calendar'), 'block' => $calendar];
        }
        $kanban = $this->buildKanban($objectId, $fieldIndex, $lang);
        if ($kanban !== null) {
            $views[] = ['label' => $lex->label('view_board'), 'block' => $kanban];
        }

        // The bar filters the PAGE, not one block on it. These tabs are the same
        // records drawn four ways, and a filter that survived "Lista" but not
        // "Tablero" would read as the board having different data.
        if ($filters['conditions'] !== []) {
            $this->applyFilter($table, $filters['conditions']);
            foreach ($views as $i => $view) {
                $this->applyFilter($views[$i]['block'], $filters['conditions']);
            }
        }

        // Stacked, these were the same records drawn three times over, with the
        // table — the thing you came to the page for — last and below the fold.
        // Tabbed, every view stays one click away and the list opens first.
        if ($views === []) {
            $blocks[] = $table;
        } else {
            $blocks[] = [
                'id' => $this->id('blk'),
                'type' => 'tabs',
                'tabs' => [
                    [
                        'id' => $this->id('tab'),
                        'label' => $lex->label('view_list'),
                        'blocks' => [$table],
                    ],
                    ...array_map(fn (array $view): array => [
                        'id' => $this->id('tab'),
                        'label' => $view['label'],
                        'blocks' => [$view['block']],
                    ], $views),
                ],
            ];
        }

        return [
            'id' => $this->id('pag'),
            'slug' => $object['slug'],
            'name' => $object['name'],
            'path' => '/'.$object['slug'],
            'blocks' => $blocks,
        ];
    }

    /**
     * What you can DO to the record whose page you are on.
     *
     * A generated detail page showed the record and offered nothing: opening an
     * order to change its status meant going back to the list and finding the
     * row again, and no generated app could delete a record at all — a typo
     * lived for ever. Both halves of that are here.
     *
     * Delete lives on this page rather than as a row action in the list, and
     * that is the point of it being here: you are looking at the record you are
     * about to destroy instead of at the twelfth row of a table. It is also the
     * only place the schema lets us gate the control by role — an action column
     * carries no `visibility`, a button does — and the scaffold grants delete to
     * `admin` alone, so anyone else never sees it. The executor re-checks the
     * policy server-side regardless; this is about not offering what will be
     * refused.
     *
     * @param  array<string, mixed>  $parentDef
     * @param  array<int, array<string, mixed>>  $parentPageFields
     * @return list<array<string, mixed>>
     */
    private function detailRecordActions(array $parentDef, array $parentPageFields, string $singular, string $lang): array
    {
        $objectId = $parentDef['id'];

        // The same fields the list's edit form offers: everything enterable,
        // minus what the app works out for itself.
        $formIndex = array_values(array_filter(
            $parentPageFields,
            fn (array $f): bool => ! in_array($f['type'] ?? 'string', self::DERIVED_TYPES, true),
        ));

        if ($formIndex === []) {
            return [];
        }

        $values = [];
        foreach ($formIndex as $field) {
            $values[$field['slug']] = '{{form.'.$field['slug'].'}}';
        }

        $lex = SemanticLexicon::for($lang);
        $modalId = $this->id('blk');

        $blocks = [[
            'id' => $modalId,
            'type' => 'modal',
            'title' => $this->labelEditTitle($lang, $singular),
            'blocks' => [[
                'id' => $this->id('blk'),
                'type' => 'form',
                'object_id' => $objectId,
                'mode' => 'edit',
                // The page's own id — this form edits the record being shown,
                // so there is no row to carry one in.
                'record_id_expression' => '{{params.id}}',
                'fields' => array_map(fn (array $f): array => ['field_id' => $f['id']], $formIndex),
                'submit_label' => $this->labelSave($lang),
                'on_submit' => [
                    ['type' => 'update_record', 'object_id' => $objectId, 'record_id_expression' => '{{params.id}}', 'values' => $values],
                    ['type' => 'close_modal'],
                    ['type' => 'show_toast', 'level' => 'success', 'message' => $lex->label('saved', singular: $singular)],
                    ['type' => 'refresh'],
                ],
            ]],
        ], [
            'id' => $this->id('blk'),
            'type' => 'button',
            'label' => $this->labelEdit($lang),
            'icon' => 'pencil',
            'variant' => 'secondary',
            'on_click' => [['type' => 'open_modal', 'modal_block_id' => $modalId]],
        ], [
            'id' => $this->id('blk'),
            'type' => 'button',
            'label' => $lex->label('delete'),
            'icon' => 'trash-2',
            'variant' => 'danger',
            'visibility' => ['roles' => ['admin']],
            'confirm' => [
                'title' => $lex->label('delete_title', singular: $singular),
                'message' => $lex->label('delete_message'),
            ],
            'on_click' => [
                ['type' => 'delete_record', 'object_id' => $objectId, 'record_id_expression' => '{{params.id}}'],
                ['type' => 'show_toast', 'level' => 'success', 'message' => $lex->label('deleted', singular: $singular)],
                // Back to the list: staying would leave the page asking the
                // server for a record that is no longer there.
                ['type' => 'navigate', 'to' => '/'.$parentDef['slug']],
            ],
        ]];

        return $blocks;
    }

    /**
     * AND a set of conditions into a block's data source, keeping whatever
     * filter it already carried.
     *
     * @param  array<string, mixed>  $block
     * @param  list<array<string, mixed>>  $conditions
     */
    private function applyFilter(array &$block, array $conditions): void
    {
        $existing = $block['data_source']['filter'] ?? null;
        $all = $existing === null ? $conditions : [$existing, ...$conditions];

        $block['data_source']['filter'] = count($all) === 1
            ? $all[0]
            : ['op' => 'and', 'conditions' => array_values($all)];
    }

    /**
     * The filters a list page can honestly offer, and the conditions that make
     * them do something.
     *
     * A generated list had a search box and sortable headings and no way to ask
     * "only the ones still open" — so a workshop app built from a brief that
     * said, in as many words, "the manager wants to see which ones are going to
     * miss the promised date" could not answer that question by any route.
     *
     * Restrained on purpose, the same way the dashboard's bar is: only controls
     * at least one block listens to. A status select whenever the object has a
     * lifecycle, and a window over a date you look FORWARD to. No default
     * window — a list page IS the object, and opening it silently scoped to
     * thirty days hides records with nothing on screen to say so.
     *
     * @param  array<int, array<string, mixed>>  $fieldIndex
     * @return array{controls: list<array<string, mixed>>, conditions: list<array<string, mixed>>}
     */
    private function buildListFilters(array $fieldIndex, string $lang): array
    {
        $controls = [];
        $conditions = [];

        $status = $this->statusField($fieldIndex, $lang);
        if ($status !== null && ($status['options'] ?? []) !== []) {
            $controls[] = [
                'param' => 'status',
                'type' => 'select',
                'label' => (string) $status['name'],
                'options' => array_values(array_map(
                    fn (array $o): array => [
                        'value' => (string) ($o['value'] ?? ''),
                        'label' => (string) ($o['label'] ?? $o['value'] ?? ''),
                    ],
                    $status['options'],
                )),
            ];
            $conditions[] = [
                'op' => 'eq',
                'field_id' => $status['id'],
                'value_expression' => '{{params.status}}',
            ];
        }

        $date = null;
        foreach ($fieldIndex as $field) {
            if (in_array($field['type'] ?? '', ['date', 'datetime'], true)
                && $this->isScheduleDate($field, $lang)) {
                $date = $field;
                break;
            }
        }

        if ($date !== null) {
            $controls[] = ['param' => 'range', 'type' => 'date_range', 'default' => 'all'];
            $conditions[] = [
                'op' => 'gte',
                'field_id' => $date['id'],
                'value_expression' => "{{range_start(default(params.range, 'all'))}}",
            ];
        }

        return ['controls' => $controls, 'conditions' => $conditions];
    }

    /**
     * A kanban board grouped by the object's first status (single_select) field,
     * or null when the object has no such field. Cards show the title field plus
     * up to two other non-status fields.
     *
     * @param  array<int, array{id: string, slug: string, type: string}>  $fieldIndex
     * @return array<string, mixed>|null
     */
    private function buildKanban(string $objectId, array $fieldIndex, string $lang = 'en'): ?array
    {
        // No status, no board: a classification does not move across columns.
        $status = $this->statusField($fieldIndex, $lang);
        $title = $this->titleField($fieldIndex);
        if ($status === null || $title === null) {
            return null;
        }

        $meta = [];
        foreach ($fieldIndex as $field) {
            if ($field['id'] !== $status['id'] && $field['id'] !== $title['id'] && count($meta) < 2) {
                $meta[] = ['field_id' => $field['id']];
            }
        }

        $kanban = [
            'id' => $this->id('blk'),
            'type' => 'kanban',
            'data_source' => ['object_id' => $objectId],
            'group_by_field_id' => $status['id'],
            'card_title_field_id' => $title['id'],
            // Drag a card between columns to change its status (writes group_by).
            'editable' => true,
        ];
        if ($meta !== []) {
            $kanban['card_meta_fields'] = $meta;
        }

        return $kanban;
    }

    /**
     * A Gantt chart of each record's span, or null when the object lacks the two
     * date/datetime fields (a start + an end) a schedule needs. Each bar runs
     * from the first date field to the second, titled by the title field and —
     * when the object has a status (single_select) — coloured by it. This is how
     * a "Tasks"/"Milestones" object with start & end dates surfaces as a
     * work-plan timeline.
     *
     * @param  array<int, array{id: string, slug: string, type: string}>  $fieldIndex
     * @return array<string, mixed>|null
     */
    private function buildGantt(string $objectId, array $fieldIndex, string $lang = 'en'): ?array
    {
        $span = $this->spanFields($fieldIndex, $lang);
        $title = $this->titleField($fieldIndex);
        if ($span === null || $title === null) {
            return null;
        }
        [$start, $end] = $span;

        $gantt = [
            'id' => $this->id('blk'),
            'type' => 'gantt',
            'data_source' => ['object_id' => $objectId],
            'start_field_id' => $start['id'],
            'end_field_id' => $end['id'],
            'title_field_id' => $title['id'],
        ];

        // Colour each bar by the object's status, when it has one.
        $status = $this->statusField($fieldIndex, $lang);
        if ($status !== null) {
            $gantt['color_field_id'] = $status['id'];
        }

        return $gantt;
    }

    /**
     * A month calendar of each record on its date, or null when the object is not
     * a single-date EVENT. A lone date/datetime field marks a point-in-time event
     * (a shoot day, an inspection, an appointment) — exactly what a calendar is
     * for; two dates are a span and belong on the Gantt instead, so the two views
     * never both fire. Coloured by the object's status when it has one.
     *
     * @param  array<int, array{id: string, slug: string, type: string}>  $fieldIndex
     * @return array<string, mixed>|null
     */
    private function buildCalendar(string $objectId, array $fieldIndex, string $lang = 'en', string $objectName = ''): ?array
    {
        // A calendar answers "what is coming up", so it needs a date you look
        // forward to. Every other date records what already happened, and a
        // month grid of those is noise above the list you came for — a customer
        // list opened on a month of signup dates.
        //
        // Unless the object IS a schedule. On an Appointments object the date
        // needs no qualifier: being an appointment is the qualifier.
        $scheduleObject = $objectName !== ''
            && SemanticLexicon::for($lang)->matches('event_object', $objectName);

        $dates = array_values(array_filter(
            $fieldIndex,
            fn (array $f): bool => in_array($f['type'] ?? '', ['date', 'datetime'], true)
                && ($scheduleObject || $this->isScheduleDate($f, $lang)),
        ));
        $title = $this->titleField($fieldIndex);
        if ($dates === [] || $title === null) {
            return null;
        }

        $calendar = [
            'id' => $this->id('blk'),
            'type' => 'calendar',
            'data_source' => ['object_id' => $objectId],
            'date_field_id' => $dates[0]['id'],
            'title_field_id' => $title['id'],
        ];

        $status = $this->firstFieldOfType($fieldIndex, 'single_select');
        if ($status !== null) {
            $calendar['color_field_id'] = $status['id'];
        }

        return $calendar;
    }

    /**
     * A dashboard landing page driven by each object's field semantics: a KPI
     * row (count + currency total + average per object), then per object the
     * visualisations that fit its shape — a status donut, a growth trend
     * (sparkline over sys_created_at, which always exists), and a value-by-status
     * bar when the object tracks money. All deterministic and schema-valid; the
     * AI builder then deepens it (compares, insights) via the dashboard
     * blueprints. Caps total charts so a many-object app stays readable.
     *
     * @param  array<int, array{name: string, id: string, fieldIndex: array<int, array{id: string, slug: string, type: string}>}>  $objects
     * @return array<string, mixed>
     */
    /**
     * Whether an object is a LINE of something else rather than a thing in its
     * own right.
     *
     * Belongs to a parent, and carries the quantity + unit price that make a
     * row an economic line. Deliberately narrow: a task belonging to a project
     * is also a child, and "all my tasks" is a page people very much want — so
     * being a child is not enough on its own.
     *
     * @param  array<string, mixed>  $def
     * @param  array<int, array<string, mixed>>  $relations
     */
    private function isLineItem(array $def, array $relations, string $lang): bool
    {
        if ($relations === []) {
            return false;
        }

        $lex = SemanticLexicon::for($lang);
        $words = fn (array $f): array => [(string) ($f['name'] ?? ''), (string) ($f['slug'] ?? '')];

        $hasQuantity = false;
        $hasUnitPrice = false;
        foreach ($def['fields'] ?? [] as $field) {
            $type = $field['type'] ?? null;
            if ($type === 'number' && $lex->matches('quantity', ...$words($field))) {
                $hasQuantity = true;
            }
            if ($type === 'currency' && $lex->matches('unit_price', ...$words($field))) {
                $hasUnitPrice = true;
            }
        }

        return $hasQuantity && $hasUnitPrice;
    }

    /**
     * The object a dashboard is really about: the first that has a status, since
     * a status is what makes records move and therefore what anyone watches.
     * Falls back to the first object when nothing has one.
     *
     * @param  array<int, array<string, mixed>>  $objects
     * @return array<string, mixed>|null
     */
    /**
     * The app's operational core — the object the dashboard is about.
     *
     * Taking the first object that happens to have a status field made this a
     * question of who the author listed first. In a rentals app that answered
     * "Inmuebles": a catalogue of properties, which does have a status, sitting
     * above the Contratos that are the actual work.
     *
     * Score the signals instead. Records that move through states are the work
     * (a catalogue's "status" is usually one too, so this alone is not enough).
     * What separates a transaction from the reference data it cites is that the
     * transaction POINTS at things — a contract names a property and a tenant —
     * while a catalogue is only pointed at. A deadline says the records happen
     * in time rather than simply existing, and money says someone is counting.
     *
     * Ties keep the author's order, so a genuinely flat model is unchanged.
     *
     * @param  array<int, array{id: string, name: string, fieldIndex: array<int, array<string, mixed>>}>  $objects
     */
    private function primaryObject(array $objects, string $lang): ?array
    {
        $best = null;
        $bestScore = 0;

        foreach ($objects as $object) {
            $index = $object['fieldIndex'];
            $score = 0;

            if ($this->statusField($index, $lang) !== null) {
                $score += 3;
            }

            // Outgoing belongs-to links. The parent side of a relation reaches
            // its children through a rollup, so a plain relation in the index
            // is this object citing another.
            $cites = count(array_filter(
                $index,
                fn (array $f): bool => ($f['type'] ?? null) === 'relation',
            ));
            $score += min($cites, 2);

            foreach ($index as $field) {
                if (in_array($field['type'] ?? null, ['date', 'datetime'], true)
                    && $this->isScheduleDate($field, $lang)) {
                    $score += 2;
                    break;
                }
            }

            if ($this->firstFieldOfType($index, 'currency') !== null) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $best = $object;
                $bestScore = $score;
            }
        }

        return $best ?? ($objects[0] ?? null);
    }

    /**
     * Put the objects the description cared about at the front, keeping the
     * structural order among the rest. The dashboard spends its charts down
     * this list, and they are capped — which is how an app asked for payment
     * collection and open incidents got a dashboard mentioning neither.
     *
     * @param  list<array<string, mixed>>  $objects
     * @param  list<string>  $focusSlugs
     * @return list<array<string, mixed>>
     */
    private function orderByFocus(array $objects, array $focusSlugs): array
    {
        if ($focusSlugs === []) {
            return $objects;
        }

        $bySlug = [];
        foreach ($objects as $object) {
            $bySlug[(string) ($object['slug'] ?? '')] = $object;
        }

        $front = [];
        foreach ($focusSlugs as $slug) {
            if (isset($bySlug[$slug])) {
                $front[] = $bySlug[$slug];
                unset($bySlug[$slug]);
            }
        }

        return $front === [] ? $objects : [...$front, ...array_values($bySlug)];
    }

    /**
     * The figures the description named, as KPI items.
     *
     * Everything here was already checked against the objects that exist —
     * normalizeFocus drops what does not resolve and says so — but the ids only
     * exist once the objects are built, so the slugs are looked up again here.
     * Anything that still fails to resolve is skipped rather than emitted
     * pointing at nothing.
     *
     * @param  list<array<string, mixed>>  $objects
     * @param  list<array{object: string, field: string, aggregation: string}>  $measures
     * @return list<array<string, mixed>>
     */
    private function focusMeasureItems(array $objects, array $measures, string $lang): array
    {
        $items = [];
        $seen = [];

        foreach ($measures as $measure) {
            $object = collect($objects)->firstWhere('slug', $measure['object']);
            if ($object === null) {
                continue;
            }
            $field = collect($object['fieldIndex'])->firstWhere('slug', $measure['field']);
            if ($field === null) {
                continue;
            }

            // The same figure asked for twice is one figure.
            //
            // A COUNT does not read its field — it counts rows — so two count
            // measures on one object differing only in which field they named
            // produce two tiles with the same label and the same number. A live
            // workshop dashboard opened with "Órdenes de servicio 14" twice,
            // side by side, and the benchmark scored it clean.
            $key = $measure['aggregation'] === 'count'
                ? $object['id'].'|count'
                : $object['id'].'|'.$measure['aggregation'].'|'.$field['id'];

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $isMoney = ($field['type'] ?? null) === 'currency';
            $measureName = $this->moneyMeasureName(
                $lang,
                (string) ($field['name'] ?? ''),
                (string) $object['name'],
            );

            $items[] = array_filter([
                'id' => $this->id('itm'),
                'label' => match ($measure['aggregation']) {
                    'sum' => $this->labelMoneyTotal(
                        $lang,
                        (string) ($field['name'] ?? ''),
                        (string) $object['name'],
                    ),
                    'avg' => $this->labelAverage($lang, $measureName),
                    default => (string) $object['name'],
                },
                // The figures the description asked for by name are most of
                // what a dashboard ends up showing, and they were the ones
                // going out without one — so the tile the runtime draws stayed
                // invisible on the very cards that matter most.
                'icon' => $this->kpiIcon($measure['aggregation'], $isMoney),
                'query' => ['object_id' => $object['id']],
                'aggregation' => $measure['aggregation'],
                // A count is over records, not over a column.
                'field_id' => $measure['aggregation'] === 'count' ? null : $field['id'],
                'format' => $isMoney && $measure['aggregation'] !== 'count' ? 'currency' : null,
            ], fn ($v): bool => $v !== null);
        }

        return $items;
    }

    /**
     * The glyph on a KPI's tile, chosen by what the figure IS — money, a
     * direction, a count of things — rather than by the object it came from.
     * Same rule the label follows.
     */
    private function kpiIcon(string $aggregation, bool $isMoney): string
    {
        if ($aggregation === 'count') {
            return 'layers';
        }
        if ($aggregation === 'avg') {
            return 'trending-up';
        }

        return $isMoney ? 'banknote' : 'sigma';
    }

    private function buildDashboard(string $appName, string $slug, array $objects, string $lang = 'en', array $focus = ['objects' => [], 'measures' => []]): array
    {
        // What the description asked to see comes first, when it named
        // anything; the structural read is the answer when it did not.
        $objects = $this->orderByFocus($objects, $focus['objects'] ?? []);

        // The app's operational core: what earns the money figures and the
        // trend line — an app tracking work orders wants "how much are we
        // billing", not "how many depots do we have".
        $primary = ($focus['objects'] ?? []) !== []
            ? $objects[0]
            : $this->primaryObject($objects, $lang);

        // The figures the description asked for by name, ahead of the derived
        // ones: someone who wrote "I need to know what is owed this month"
        // should find that at the top, not a count of every object.
        $items = $this->focusMeasureItems($objects, $focus['measures'] ?? [], $lang);
        $primaryCurrency = $primary !== null
            ? $this->firstFieldOfType($primary['fieldIndex'], 'currency')
            : null;

        $alreadyAsked = fn (string $objectId, string $fieldId, string $aggregation): bool => collect($items)
            ->contains(fn (array $i): bool => ($i['query']['object_id'] ?? null) === $objectId
                && ($i['field_id'] ?? null) === $fieldId
                && ($i['aggregation'] ?? null) === $aggregation);

        if ($primary !== null && $primaryCurrency !== null
            && ! $alreadyAsked($primary['id'], $primaryCurrency['id'], 'sum')) {
            // The measure, not the object: a money KPI sits next to a count KPI
            // for the very same object, and naming both after the object leaves
            // "Total Inmuebles $308,500.00" above "Inmuebles 6".
            $measure = $this->moneyMeasureName(
                $lang,
                (string) ($primaryCurrency['name'] ?? ''),
                $primary['name'],
            );
            $items[] = [
                'id' => $this->id('itm'),
                'label' => $this->labelMoneyTotal(
                    $lang,
                    (string) ($primaryCurrency['name'] ?? ''),
                    $primary['name'],
                ),
                'query' => ['object_id' => $primary['id']],
                'aggregation' => 'sum',
                'field_id' => $primaryCurrency['id'],
                'format' => 'currency',
                // The runtime draws a KPI's icon on a tinted tile, and nothing
                // ever emitted one — so the tile existed and no generated app
                // could show it. Named for what the figure IS rather than for
                // the object, which is the same rule its label follows.
                'icon' => 'banknote',
            ];
            $items[] = [
                'id' => $this->id('itm'),
                'label' => $this->labelAverage($lang, $measure),
                'query' => ['object_id' => $primary['id']],
                'aggregation' => 'avg',
                'field_id' => $primaryCurrency['id'],
                'format' => 'currency',
                'icon' => 'trending-up',
            ];
        }

        $ordered = $primary === null
            ? $objects
            : [$primary, ...array_filter($objects, fn (array $o): bool => $o['id'] !== $primary['id'])];

        foreach ($ordered as $object) {
            // A count the description already asked for is on the board.
            if (collect($items)->contains(fn (array $i): bool => ($i['aggregation'] ?? null) === 'count'
                && ($i['query']['object_id'] ?? null) === $object['id'])) {
                continue;
            }
            $items[] = [
                'id' => $this->id('itm'),
                'label' => $object['name'],
                'query' => ['object_id' => $object['id']],
                'aggregation' => 'count',
                'icon' => 'layers',
            ];
        }

        $items = array_slice($items, 0, self::DASHBOARD_KPI_CAP);

        $blocks = [
            ['id' => $this->id('blk'), 'type' => 'heading', 'content' => $appName],
            ['id' => $this->id('blk'), 'type' => 'metric_grid', 'items' => $items],
        ];

        // Per object, the visualisations its shape supports. Status donuts come
        // first (so the first `chart` block stays the status breakdown), then a
        // growth trend, then a value-by-status bar for money objects.
        // Breakdowns lead with the objects that have a real status; a
        // classification breakdown only fills a slot the lifecycle ones left
        // empty. On a six-object app "customers by contract type" is the fourth
        // most interesting thing on the page and does not earn a place.
        $withStatus = [];
        $withClassification = [];
        foreach ($objects as $object) {
            if ($this->statusField($object['fieldIndex'], $lang) !== null) {
                $withStatus[] = $object;

                continue;
            }
            if ($this->firstFieldOfType($object['fieldIndex'], 'single_select') !== null) {
                $withClassification[] = $object;
            }
        }
        // Two breakdowns is the right default for a dashboard derived from
        // structure alone. When the description named the things it wants to
        // see, the budget belongs to them: an operator who asked for expiring
        // contracts, the month's collections, available properties and open
        // incidents got three of the four, because the fourth slot went to a
        // growth line nobody asked for and a value bar re-cutting an object
        // that already had a donut.
        $breakdownCap = max(
            self::DASHBOARD_BREAKDOWN_CAP,
            min(count($focus['objects'] ?? []), self::DASHBOARD_CHART_CAP),
        );
        $breakdownObjects = array_slice(
            [...$withStatus, ...$withClassification],
            0,
            $breakdownCap,
        );

        $charts = 0;
        $trends = [];
        $valueBars = [];
        foreach ($breakdownObjects as $object) {
            if ($charts >= self::DASHBOARD_CHART_CAP) {
                break;
            }
            // A breakdown is worth drawing for a classification too, so this
            // takes the status when there is one and falls back to the first
            // select — but it is titled after the field it ACTUALLY groups by.
            // Labelling every donut "por estado" produced three charts out of
            // four whose titles were simply false: customers by contract type,
            // equipment by equipment type, technicians by speciality.
            $status = $this->statusField($object['fieldIndex'], $lang)
                ?? $this->firstFieldOfType($object['fieldIndex'], 'single_select');
            $currency = $this->firstFieldOfType($object['fieldIndex'], 'currency');

            if ($status !== null) {
                $blocks[] = [
                    'id' => $this->id('blk'),
                    'type' => 'chart',
                    'label' => $this->labelByField($lang, $object['name'], (string) ($status['name'] ?? '')),
                    'chart_type' => 'donut',
                    'data_source' => ['object_id' => $object['id'], 'limit' => self::DASHBOARD_ROW_LIMIT],
                    'aggregation' => 'count',
                    'group_by_field_id' => $status['id'],
                ];
                $charts++;

                if ($currency !== null) {
                    $valueBars[] = [
                        'id' => $this->id('blk'),
                        'type' => 'chart',
                        'label' => $this->labelValueByStatus($lang, $object['name']),
                        'chart_type' => 'bar',
                        'data_source' => ['object_id' => $object['id'], 'limit' => self::DASHBOARD_ROW_LIMIT],
                        'aggregation' => 'sum',
                        'y_field_id' => $currency['id'],
                        'group_by_field_id' => $status['id'],
                    ];
                }
            }

        }

        // One trend, for the operational core. "Depots over time" is not a
        // signal anyone watches; four of them is a wall.
        $trendObject = $primary ?? ($objects[0] ?? null);
        if ($trendObject !== null) {
            $trends[] = [
                'id' => $this->id('blk'),
                'type' => 'sparkline',
                'label' => $this->labelOverTime($lang, $trendObject['name']),
                'data_source' => ['object_id' => $trendObject['id'], 'limit' => self::DASHBOARD_ROW_LIMIT],
                'x_field_id' => 'sys_created_at',
                'aggregation' => 'count',
            ];
        }

        // Trends and value bars fill what the breakdowns left, and the ceiling
        // is a ceiling: it used to bound only the loop above, so these two were
        // appended on top of it and a four-chart budget drew six. They are the
        // extras — a growth line and a re-cut of an object that already has a
        // donut — so when the breakdowns need the room, they are what gives.
        foreach ([
            array_slice($trends, 0, self::DASHBOARD_TREND_CAP),
            array_slice($valueBars, 0, self::DASHBOARD_VALUE_BAR_CAP),
        ] as $extras) {
            foreach ($extras as $extra) {
                if ($charts >= self::DASHBOARD_CHART_CAP) {
                    break 2;
                }
                $blocks[] = $extra;
                $charts++;
            }
        }

        // Applied to the FLAT list, before the charts are paired into rows:
        // afterwards they are nested and the walk would have to know about the
        // containers to find them.
        $blocks = $this->applyDashboardWindow($blocks, $objects, $lang);

        return [
            'id' => $this->id('pag'),
            'slug' => $slug,
            'name' => 'Dashboard',
            'path' => '/',
            'blocks' => $this->pairUpVisualisations($blocks),
        ];
    }

    /**
     * A window over the whole board, and every figure on it answering.
     *
     * A generated dashboard could only ever say one thing. "Órdenes de servicio
     * 14" meant since the beginning of time, with no way to ask what this month
     * looked like — and the brief it was built from said, in as many words,
     * that the manager wants to know how much is being billed. A board with no
     * way to change the window is a board you read once.
     *
     * Every block is scoped by ITS OWN object's date: a dashboard aggregates
     * across several objects and each keeps time differently. The date it looks
     * FORWARD to when it has one, otherwise the row's own created-at — which
     * every record has, so no figure is left out of the window silently.
     *
     * Opens on 'all', so the board reads exactly as it did before anybody
     * touches the control. A default of 30 days would silently turn 14 into 3
     * with nothing on screen to say why.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<int, array<string, mixed>>  $objects
     * @return list<array<string, mixed>>
     */
    private function applyDashboardWindow(array $blocks, array $objects, string $lang): array
    {
        $dateByObject = [];
        foreach ($objects as $object) {
            $dateByObject[$object['id']] = $this->windowDateFor($object, $lang);
        }

        if ($dateByObject === []) {
            return $blocks;
        }

        $condition = fn (string $objectId): ?array => isset($dateByObject[$objectId])
            ? [
                'op' => 'gte',
                'field_id' => $dateByObject[$objectId],
                'value_expression' => "{{range_start(default(params.range, 'all'))}}",
            ]
            : null;

        $wired = false;
        foreach ($blocks as $i => $block) {
            if (($block['type'] ?? null) === 'metric_grid') {
                foreach ($block['items'] as $j => $item) {
                    $where = $condition((string) ($item['query']['object_id'] ?? ''));
                    if ($where !== null) {
                        $blocks[$i]['items'][$j]['query']['filter'] = $where;
                        $wired = true;
                    }
                }

                continue;
            }

            if (in_array($block['type'] ?? null, ['chart', 'sparkline'], true)) {
                $where = $condition((string) ($block['data_source']['object_id'] ?? ''));
                if ($where !== null) {
                    $blocks[$i]['data_source']['filter'] = $where;
                    $wired = true;
                }
            }
        }

        // Same rule the list page's bar follows: never a control nothing
        // listens to.
        if (! $wired) {
            return $blocks;
        }

        $bar = [
            'id' => $this->id('blk'),
            'type' => 'filter_bar',
            'controls' => [['param' => 'range', 'type' => 'date_range', 'default' => 'all']],
        ];

        // Under the title, above the figures it governs.
        array_splice($blocks, 1, 0, [$bar]);

        return $blocks;
    }

    /**
     * The date a dashboard block should be windowed by: the one this object
     * looks forward to, or the row's own created-at.
     *
     * @param  array<string, mixed>  $object
     */
    private function windowDateFor(array $object, string $lang): string
    {
        foreach ($object['fieldIndex'] ?? [] as $field) {
            if (in_array($field['type'] ?? '', ['date', 'datetime'], true)
                && $this->isScheduleDate($field, $lang)) {
                return (string) $field['id'];
            }
        }

        return 'sys_created_at';
    }

    /**
     * Put the dashboard's charts side by side instead of one per full-width row.
     *
     * Each one is a donut and a short legend: given the whole width it fills a
     * fifth of its card and leaves the rest empty, so four of them ran a screen
     * and a half of mostly nothing. Two across halves that, and a donut does not
     * get better for being wide.
     *
     * Odd one out stays full width rather than sitting alone in half a row — a
     * lone half-card reads like something failed to load beside it.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function pairUpVisualisations(array $blocks): array
    {
        $out = [];
        $pending = [];

        $flush = function () use (&$out, &$pending): void {
            if (count($pending) >= 2) {
                $out[] = [
                    'id' => $this->id('blk'),
                    'type' => 'container',
                    'direction' => 'row',
                    'gap' => 'md',
                    'blocks' => array_splice($pending, 0, 2),
                ];
            }
            foreach ($pending as $leftover) {
                $out[] = $leftover;
            }
            $pending = [];
        };

        foreach ($blocks as $block) {
            if (in_array($block['type'] ?? null, ['chart', 'sparkline'], true)) {
                $pending[] = $block;
                if (count($pending) === 2) {
                    $flush();
                }

                continue;
            }
            $flush();
            $out[] = $block;
        }
        $flush();

        return $out;
    }

    /** Numeric field types a sum/avg/min/max (or a percentile KPI) can fold. */
    private const NUMERIC_TYPES = ['number', 'currency', 'rating', 'slider'];

    /** Date-ish field types that can drive the date-range filter / time axes. */
    private const DATE_TYPES = ['date', 'datetime'];

    /** Chart aggregations the runtime renders; percentiles belong in KPIs. */
    private const CHART_AGGS = ['count', 'sum', 'avg', 'min', 'max'];

    private const KPI_AGGS = ['count', 'sum', 'avg', 'min', 'max', 'distinct_count', 'median', 'p90', 'p95'];

    /**
     * Compile a full dashboard page from a compact CONTENT spec (the model says
     * WHAT: kpis/charts/insights; the server decides HOW: balanced rows, column
     * weights, ids, the date-range filter wiring, the brand hero). Deterministic
     * and schema-valid by construction — the add_dashboard_page tool then lints
     * the returned `plan_rows` with PlanDashboardTool::lint before proposing.
     *
     * @param  array<string, mixed>  $spec  {title?, purpose?, date_field_id?, kpis: [...], charts: [...], insights?: [...], include_hero?, include_date_filter?}
     * @param  array<string, mixed>  $object  the PRIMARY manifest object node (the default when an item names no object_slug)
     * @param  list<string>  $takenPageSlugs
     * @param  array{ramp: array<string, string>}|null  $palette  brand palette for the hero gradient
     * @param  list<array<string, mixed>>  $extraObjects  other manifest objects addressable per kpi/chart via `object_slug`
     * @return array{ok: bool, page?: array<string, mixed>, plan_rows?: list<array<string, mixed>>, purpose?: string, errors?: list<array{path: string, message: string, code: string}>}
     */
    public function buildDashboardFromSpec(array $spec, array $object, array $takenPageSlugs, ?array $palette, string $lang = 'en', array $extraObjects = []): array
    {
        $primarySlug = (string) ($object['slug'] ?? '');
        $objectsBySlug = [$primarySlug => $object];
        foreach ($extraObjects as $extra) {
            $slug = is_array($extra) ? (string) ($extra['slug'] ?? '') : '';
            if ($slug !== '' && ! isset($objectsBySlug[$slug])) {
                $objectsBySlug[$slug] = $extra;
            }
        }

        $fieldsBySlug = [];
        foreach ($objectsBySlug as $slug => $obj) {
            $map = [];
            foreach ($obj['fields'] ?? [] as $f) {
                $map[$f['id']] = $f;
            }
            $map['sys_created_at'] = ['id' => 'sys_created_at', 'slug' => 'sys_created_at', 'type' => 'datetime', 'name' => 'Created at'];
            $map['sys_updated_at'] = ['id' => 'sys_updated_at', 'slug' => 'sys_updated_at', 'type' => 'datetime', 'name' => 'Updated at'];
            $fieldsBySlug[$slug] = $map;
        }

        $errors = [];
        $fieldType = function (?string $id, string $path, bool $required = false, ?array $on = null) use ($fieldsBySlug, $object, $primarySlug, &$errors): ?string {
            $slug = (string) (($on ?? $object)['slug'] ?? $primarySlug);
            $fieldById = $fieldsBySlug[$slug] ?? $fieldsBySlug[$primarySlug];
            if ($id === null || $id === '') {
                if ($required) {
                    $errors[] = ['path' => $path, 'message' => 'field_id is required here.', 'code' => 'missing_field'];
                }

                return null;
            }
            if (! isset($fieldById[$id])) {
                $errors[] = ['path' => $path, 'message' => "Field '{$id}' does not exist on object '{$slug}'. Use the ids from read_manifest/profile_object.", 'code' => 'unknown_field'];

                return null;
            }

            return $fieldById[$id]['type'];
        };

        // Which object an item reads: its own object_slug, else the primary.
        $resolveObject = function (array $item, string $path) use ($objectsBySlug, $primarySlug, &$errors): ?array {
            $slug = trim((string) ($item['object_slug'] ?? '')) ?: $primarySlug;
            if (! isset($objectsBySlug[$slug])) {
                $errors[] = ['path' => $path.'/object_slug', 'message' => "No object with slug '{$slug}' exists in this app.", 'code' => 'unknown_object'];

                return null;
            }

            return $objectsBySlug[$slug];
        };

        // Aggregation legality, enforced for EVERY caller — Express suggests
        // only legal specs, but a hand-authored spec once shipped a bar chart
        // COUNTING pre-aggregated weekly rows (every bar = 1 week) and summed
        // scores. Only the unambiguous lies are blocked; the errors name the
        // honest alternative.
        $grainBySlug = [];
        foreach ($objectsBySlug as $slug => $obj) {
            $grainBySlug[$slug] = $this->semantics->grainOf($obj);
        }
        $legalAggregation = function (array $obj, string $agg, ?string $fieldId, string $path) use (&$errors, $grainBySlug, $fieldsBySlug, $primarySlug): bool {
            $slug = (string) ($obj['slug'] ?? $primarySlug);
            $grain = $grainBySlug[$slug] ?? SemanticProfile::GRAIN_RAW;
            $field = $fieldId !== null ? ($fieldsBySlug[$slug][$fieldId] ?? null) : null;
            $measure = $field !== null ? $this->semantics->measureTypeOf($field) : null;

            if ($agg === 'count' && $grain !== SemanticProfile::GRAIN_RAW) {
                $errors[] = ['path' => $path, 'message' => "count on '{$slug}' counts pre-aggregated BUCKETS, not records — sum an additive column of the object instead.", 'code' => 'illegal_aggregation'];

                return false;
            }
            if ($measure === SemanticProfile::MEASURE_IDENTIFIER) {
                $errors[] = ['path' => $path, 'message' => "'{$field['slug']}' is an identifier — no aggregation of an id means anything. Aggregate a real measure or drop this item.", 'code' => 'illegal_aggregation'];

                return false;
            }
            if ($agg === 'sum' && in_array($measure, [SemanticProfile::MEASURE_RATIO, SemanticProfile::MEASURE_STATISTIC], true)) {
                $errors[] = ['path' => $path, 'message' => "Never SUM '{$field['slug']}' (a percentage/score/statistic) — use avg, min or max.", 'code' => 'illegal_aggregation'];

                return false;
            }

            return true;
        };

        $kpis = array_values(is_array($spec['kpis'] ?? null) ? $spec['kpis'] : []);
        $charts = array_values(is_array($spec['charts'] ?? null) ? $spec['charts'] : []);
        $insights = array_values(is_array($spec['insights'] ?? null) ? $spec['insights'] : []);
        if ($kpis === []) {
            $errors[] = ['path' => '/kpis', 'message' => 'Give at least one KPI — a dashboard opens with its headline numbers.', 'code' => 'missing_kpis'];
        }
        if ($charts === []) {
            $errors[] = ['path' => '/charts', 'message' => 'Give at least one chart.', 'code' => 'missing_charts'];
        }

        // The date field that drives the range filter (and default time axes).
        // The spec's date_field_id applies to the PRIMARY; every other object
        // wires the shared `range` param to its OWN first temporal field. A
        // connected object without a real one gets NO range condition at all:
        // sys_created_at is a records-only column, and filtering connected
        // rows by it silently deletes every row.
        $dateFieldId = is_string($spec['date_field_id'] ?? null) && $spec['date_field_id'] !== '' ? $spec['date_field_id'] : null;
        if ($dateFieldId !== null) {
            $type = $fieldType($dateFieldId, '/date_field_id');
            if ($type !== null && ! in_array($type, self::DATE_TYPES, true)) {
                $errors[] = ['path' => '/date_field_id', 'message' => "Field '{$dateFieldId}' is {$type}, not a date/datetime.", 'code' => 'wrong_type'];
            }
        }
        $withDateFilter = (bool) ($spec['include_date_filter'] ?? true);

        // Every dashboard opens on the last 30 days — the product default —
        // unless the spec asks for another preset (`default_range`): the
        // data-aware suggester widens it when the sampled rows span months, so
        // a monthly/yearly series doesn't open as an empty board filtered to a
        // window its data lives outside of. Validated against the filter bar's
        // REAL presets; anything else falls back to 30d.
        $defaultRange = in_array($spec['default_range'] ?? null, ['7d', '30d', '90d', '1y'], true)
            ? (string) $spec['default_range']
            : '30d';

        $rangeBySlug = [];
        foreach ($objectsBySlug as $slug => $obj) {
            $fieldId = $slug === $primarySlug ? $dateFieldId : null;
            if ($fieldId === null) {
                foreach ($obj['fields'] ?? [] as $f) {
                    if (in_array($f['type'], self::DATE_TYPES, true)) {
                        $fieldId = $f['id'];
                        break;
                    }
                }
            }
            if ($fieldId === null && ($obj['source']['type'] ?? '') !== 'connected') {
                $fieldId = 'sys_created_at';
            }
            $rangeBySlug[$slug] = $fieldId === null
                ? null
                : ['op' => 'gte', 'field_id' => $fieldId, 'value_expression' => "{{range_start(default(params.range, '{$defaultRange}'))}}"];
        }

        // The dominant-categorical SELECT filter: an eq condition on
        // params.<param> merged into every block reading the PRIMARY object.
        // An unset param resolves empty and the condition is skipped
        // server-side — the same "Todo" mechanics the date range uses.
        $categoryFilter = null;
        $categoryWired = false;
        if (is_array($spec['category_filter'] ?? null)) {
            $cf = $spec['category_filter'];
            // The filter's field may live on a SECONDARY object («filtro por
            // categoría» when the primary is a reason breakdown) — resolve it
            // on its owner and remember the slug so every object carrying a
            // same-named field listens too.
            $cfOwner = isset($cf['object_slug'])
                ? (collect($extraObjects)->firstWhere('slug', $cf['object_slug']) ?? $object)
                : $object;
            $cfField = collect($cfOwner['fields'] ?? [])->firstWhere('id', $cf['field_id'] ?? null);
            $cfOptions = collect($cf['options'] ?? [])
                ->map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '')
                ->filter()->unique()->take(12)->values();
            if ($cfField !== null && in_array($cfField['type'] ?? '', ['string', 'single_select'], true) && $cfOptions->count() >= 2) {
                $param = (string) preg_replace('/[^a-z0-9_]/', '', Str::snake((string) ($cf['param'] ?? $cfField['slug'] ?? 'categoria')));
                $categoryFilter = [
                    'field_id' => $cfField['id'],
                    'field_slug' => (string) ($cfField['slug'] ?? ''),
                    'label' => (string) ($cf['label'] ?? $cfField['name'] ?? $cfField['slug']),
                    'param' => $param !== '' && $param !== 'range' ? $param : 'categoria',
                    'options' => $cfOptions->all(),
                ];
            }
        }

        // Merge the range filter into a block's own filter (empty preset ⇒ the
        // condition resolves empty and is skipped server-side ⇒ "Todo").
        $rangeWired = false;
        $withRange = function (?array $own, array $obj) use ($withDateFilter, $rangeBySlug, $primarySlug, &$rangeWired, $categoryFilter, &$categoryWired): ?array {
            $conditions = [];
            $range = $rangeBySlug[(string) ($obj['slug'] ?? $primarySlug)] ?? null;
            if ($withDateFilter && $range !== null) {
                $rangeWired = true;
                $conditions[] = $range;
            }
            if ($categoryFilter !== null) {
                $listener = collect($obj['fields'] ?? [])->first(
                    fn ($f): bool => is_array($f) && ($f['slug'] ?? null) === $categoryFilter['field_slug'],
                );
                if ($listener !== null) {
                    $categoryWired = true;
                    $conditions[] = [
                        'op' => 'eq',
                        'field_id' => $listener['id'],
                        'value_expression' => '{{params.'.$categoryFilter['param'].'}}',
                    ];
                }
            }
            if ($conditions === []) {
                return $own;
            }
            $all = $own === null ? $conditions : [$own, ...$conditions];

            return count($all) === 1 ? $all[0] : ['op' => 'and', 'conditions' => $all];
        };

        // --- KPI band ---------------------------------------------------------
        $items = [];
        foreach ($kpis as $i => $kpi) {
            $kpiObject = $resolveObject(is_array($kpi) ? $kpi : [], "/kpis/{$i}");
            if ($kpiObject === null) {
                continue;
            }
            $agg = (string) ($kpi['aggregation'] ?? 'count');
            if (! in_array($agg, self::KPI_AGGS, true)) {
                $errors[] = ['path' => "/kpis/{$i}/aggregation", 'message' => "Unknown aggregation '{$agg}'. Valid: ".implode('|', self::KPI_AGGS).'.', 'code' => 'bad_aggregation'];

                continue;
            }
            $needsField = $agg !== 'count';
            $type = $fieldType($kpi['field_id'] ?? null, "/kpis/{$i}/field_id", $needsField, $kpiObject);
            if ($type !== null && $agg !== 'count' && $agg !== 'distinct_count' && ! in_array($type, self::NUMERIC_TYPES, true)) {
                $errors[] = ['path' => "/kpis/{$i}/field_id", 'message' => "'{$agg}' needs a numeric field; '{$kpi['field_id']}' is {$type}.", 'code' => 'wrong_type'];
            }
            if (! $legalAggregation($kpiObject, $agg, $needsField ? ($kpi['field_id'] ?? null) : null, "/kpis/{$i}")) {
                continue;
            }

            $ownFilter = is_array($kpi['filter'] ?? null) ? $kpi['filter'] : null;
            $query = array_filter([
                'object_id' => $kpiObject['id'],
                'filter' => $withRange($ownFilter, $kpiObject),
            ], fn ($v) => $v !== null);

            $compare = is_array($kpi['compare'] ?? null) ? $kpi['compare'] : null;
            if ($compare !== null && ! isset($compare['object_id'])) {
                $compare['object_id'] = $kpiObject['id'];
            }

            // A RATE KPI: value = SUM(numerator) ÷ SUM(denominator), recomputed live.
            // The compiler owns the denominator's query (same object, same window) so
            // the spec only names the column and how to aggregate it — otherwise the
            // ratio would silently read a different window than the block it sits in.
            $ratio = is_array($kpi['ratio_denominator'] ?? null) ? $kpi['ratio_denominator'] : null;
            if ($ratio !== null) {
                $ratio = array_filter([
                    'query' => $query,
                    'aggregation' => (string) ($ratio['aggregation'] ?? 'sum'),
                    'field_id' => $ratio['field_id'] ?? null,
                ], fn ($v) => $v !== null);
            }

            $items[] = array_filter([
                'id' => $this->id('itm'),
                'label' => (string) ($kpi['label'] ?? 'KPI'),
                'query' => $query,
                'aggregation' => $agg,
                'field_id' => $needsField ? ($kpi['field_id'] ?? null) : null,
                'ratio_denominator' => $ratio,
                'format' => $kpi['format'] ?? null,
                'icon' => $this->renderableIcon($kpi['icon'] ?? null),
                'compare' => $compare,
                'compare_window' => ($kpi['compare_window'] ?? null) === 'previous' && $compare === null ? 'previous' : null,
                'delta_good' => $kpi['delta_good'] ?? null,
                // Inline history behind the number: the compiler owns the query
                // (object + current-window filter); the spec names the axes.
                'spark' => is_array($kpi['spark'] ?? null) ? array_filter([
                    'data_source' => array_filter([
                        'object_id' => $kpiObject['id'],
                        'filter' => $withRange(null, $kpiObject),
                        'limit' => self::DASHBOARD_ROW_LIMIT,
                    ], fn ($v) => $v !== null),
                    'x_field_id' => $kpi['spark']['x_field_id'] ?? null,
                    'y_field_id' => $kpi['spark']['y_field_id'] ?? null,
                    'aggregation' => $kpi['spark']['aggregation'] ?? null,
                ], fn ($v) => $v !== null) : null,
                // An honest caption naming the aggregation basis (a promedio vs a
                // suma vs a mediana reads very differently), filter-safe because
                // it describes the number's KIND, not a value that goes stale.
                // A spec-provided `unit` (min, h, %) rides along — "mediana del
                // periodo · min" says what the number is AND what it measures.
                'subtitle' => trim(((string) ($kpi['subtitle'] ?? '') !== ''
                    ? (string) $kpi['subtitle']
                    : $this->kpiSubtitle($agg, $lang))
                    .((string) ($kpi['unit'] ?? '') !== '' ? ' · '.$kpi['unit'] : '')),
            ], fn ($v) => $v !== null && $v !== '');
        }

        // --- Charts -----------------------------------------------------------
        $chartBlocks = [];
        $seenChartIdentities = [];
        $droppedCharts = [];
        foreach ($charts as $i => $chart) {
            $chartObject = $resolveObject(is_array($chart) ? $chart : [], "/charts/{$i}");
            if ($chartObject === null) {
                continue;
            }
            $chartType = (string) ($chart['chart_type'] ?? '');

            // Two charts that show EXACTLY the same information — the same
            // measure over the same dimension of the same object with the
            // same filter — add nothing, whatever their chart_type (prod:
            // «Total Tickets por reason» bar beside «Total Tickets por
            // Motivo» hbar). The identity folds the aggregation away on
            // pre-aggregated grains, where sum/avg/min/max over one-row
            // groups collapse to the same numbers. Later duplicates are
            // DROPPED, never errored: losing zero information can't fail a
            // build.
            $identityGrain = $grainBySlug[(string) ($chartObject['slug'] ?? $primarySlug)] ?? '';
            $identityMeasure = (string) (($chart['y_field_id'] ?? null) ?: 'count');
            if ($identityMeasure === 'count'
                || ! in_array($identityGrain, [SemanticProfile::GRAIN_DIMENSION, SemanticProfile::GRAIN_TIME_SERIES], true)) {
                $identityMeasure .= ':'.(string) ($chart['aggregation'] ?? 'count');
            }
            $identity = json_encode([
                $chartObject['slug'] ?? null,
                $chart['group_by_field_id'] ?? null,
                $chart['x_field_id'] ?? null,
                $chart['bucket'] ?? null,
                $chart['series_field_id'] ?? null,
                $identityMeasure,
                $chart['filter'] ?? null,
            ]);
            if (isset($seenChartIdentities[$identity])) {
                $droppedCharts[] = '«'.(string) ($chart['label'] ?? $chartType).'» (misma información que otra gráfica)';

                continue;
            }
            $seenChartIdentities[$identity] = $i;

            // Specialized-viz intents authored as chart entries: the spec
            // grammar stays uniform (everything visual lives in charts[]) and
            // the compiler translates to the dedicated block the runtime
            // renders — with its own feasibility checks instead of the
            // chart-block lints below.
            if ($chartType === 'funnel') {
                $block = $this->funnelBlockFromChart($chart, $chartObject, $i, $errors, $withRange);
                if ($block !== null) {
                    $chartBlocks[] = $block;
                }

                continue;
            }
            if ($chartType === 'heatmap') {
                $block = $this->heatmapBlockFromChart($chart, $chartObject, $i, $errors);
                if ($block !== null) {
                    $chartBlocks[] = $block;
                }

                continue;
            }
            if ($chartType === 'gauge') {
                $block = $this->gaugeBlockFromChart($chart, $chartObject, $i, $errors, $withRange);
                if ($block !== null) {
                    $chartBlocks[] = $block;
                }

                continue;
            }

            $agg = (string) ($chart['aggregation'] ?? 'count');
            if (in_array($agg, ['median', 'p90', 'p95', 'distinct_count'], true)) {
                $errors[] = ['path' => "/charts/{$i}/aggregation", 'message' => "Charts render count|sum|avg|min|max only — put '{$agg}' in a KPI instead.", 'code' => 'bad_aggregation'];

                continue;
            }
            if (! in_array($agg, self::CHART_AGGS, true)) {
                $errors[] = ['path' => "/charts/{$i}/aggregation", 'message' => "Unknown aggregation '{$agg}'.", 'code' => 'bad_aggregation'];

                continue;
            }
            $yType = $fieldType($chart['y_field_id'] ?? null, "/charts/{$i}/y_field_id", $agg !== 'count', $chartObject);
            if ($yType !== null && ! in_array($yType, self::NUMERIC_TYPES, true)) {
                $errors[] = ['path' => "/charts/{$i}/y_field_id", 'message' => "'{$agg}' needs a numeric y_field_id; '{$chart['y_field_id']}' is {$yType}.", 'code' => 'wrong_type'];
            }
            if (! $legalAggregation($chartObject, $agg, $chart['y_field_id'] ?? null, "/charts/{$i}")) {
                continue;
            }
            $groupType = $fieldType($chart['group_by_field_id'] ?? null, "/charts/{$i}/group_by_field_id", false, $chartObject);
            $xType = $fieldType($chart['x_field_id'] ?? null, "/charts/{$i}/x_field_id", false, $chartObject);

            // A count-over-time chart over a recency-capped source (mode:latest/
            // recent) is a misleading trend: the source only ever returns its
            // most-recent N rows, so the per-bucket counts are an artefact of the
            // cap (older buckets read as empty, the newest as full), not a real
            // volume trend. Observed: a `count` line over Nps Comments (latest)
            // that plotted the sampling window, not the data. Chart a real
            // measure of the value, or use this object for a non-temporal cut.
            $hasDateAxis = ($xType !== null && in_array($xType, self::DATE_TYPES, true))
                || ($groupType !== null && in_array($groupType, self::DATE_TYPES, true));
            $chartMode = strtolower((string) ($chartObject['source']['operations']['list']['arguments']['mode'] ?? ''));
            if ($agg === 'count' && $hasDateAxis && in_array($chartMode, ['latest', 'recent'], true)) {
                $chartObjSlug = (string) ($chartObject['slug'] ?? $chartObject['name'] ?? 'this object');
                $errors[] = ['path' => "/charts/{$i}", 'message' => "'{$chartObjSlug}' returns only a recency-capped sample (mode:{$chartMode}), so counting it over time plots the sampling window, not a real trend. Chart sum/avg of a value column, or use this object for a non-temporal breakdown.", 'code' => 'illegal_aggregation'];

                continue;
            }

            // Grouping a time series by its bucket-LABEL column re-plots the
            // trend as unordered bars (shipped once: «Distribución por
            // Segmento» grouped by period_label — every bar one week).
            $groupId = $chart['group_by_field_id'] ?? null;
            $groupSlug = $groupId !== null ? (string) ($fieldsBySlug[(string) ($chartObject['slug'] ?? $primarySlug)][$groupId]['slug'] ?? '') : '';
            if ($groupSlug !== ''
                && ($grainBySlug[(string) ($chartObject['slug'] ?? $primarySlug)] ?? '') === SemanticProfile::GRAIN_TIME_SERIES
                && SemanticLexicon::for($lang)->matches('temporal', $groupSlug)) {
                $errors[] = ['path' => "/charts/{$i}/group_by_field_id", 'message' => "'{$groupSlug}' is the series' bucket label (the time axis in costume) — chart the trend with x_field_id on the object's date field instead.", 'code' => 'illegal_aggregation'];

                continue;
            }

            // Counting PRE-AGGREGATED rows grouped by a category charts "one
            // bar per row" — the source already collapsed each category to one
            // row, so count(rows) per group is always 1 (or the number of
            // buckets, never the number of underlying entities). Shipped once:
            // «Total Tickets por Motivo», an hbar of flat 1s over a reason
            // breakdown. Size the slices with the additive measure instead.
            if ($agg === 'count' && $groupId !== null && $groupId !== ''
                && ! isset($chart['x_field_id'])
                && in_array($grainBySlug[(string) ($chartObject['slug'] ?? $primarySlug)] ?? '',
                    [SemanticProfile::GRAIN_DIMENSION, SemanticProfile::GRAIN_TIME_SERIES], true)) {
                $errors[] = ['path' => "/charts/{$i}/aggregation", 'message' => 'This object is pre-aggregated (one row per category/bucket), so counting rows per group charts the row layout, not the data — aggregate sum/avg of a numeric column instead.', 'code' => 'illegal_aggregation'];

                continue;
            }

            // A pareto RANKS categories by their share (bars + cumulative-%
            // line) — it needs a real non-temporal dimension to rank; a date
            // axis is an order, not a ranking.
            if ($chartType === 'pareto'
                && ($groupId === null || $groupId === ''
                    || ($groupType !== null && in_array($groupType, self::DATE_TYPES, true)))) {
                $errors[] = ['path' => "/charts/{$i}/group_by_field_id", 'message' => 'A pareto ranks categories by their share of the total — set group_by_field_id to a real dimension (motivo, categoría, responsable…), never a date.', 'code' => 'degenerate_chart'];

                continue;
            }

            // A part-of-whole chart needs a category to slice by. A pie/donut
            // with no group_by (and no series) is a single 100% slice —
            // observed: a «Respuestas por Periodo» donut of sum(responses) that
            // said nothing. Point the model at a breakdown dimension or a bar.
            if (in_array($chartType, ['pie', 'donut'], true)
                && ($groupId === null || $groupId === '')
                && ($chart['series_field_id'] ?? null) === null) {
                $errors[] = ['path' => "/charts/{$i}/group_by_field_id", 'message' => "A {$chartType} needs a category to slice by — set group_by_field_id to a real dimension (status, segment, vertical…), or use a line/bar over time instead.", 'code' => 'degenerate_chart'];

                continue;
            }

            // A date axis gets a bucket so the series reads chronologically.
            $bucket = $chart['bucket'] ?? null;
            if ($bucket === null
                && (($groupType !== null && in_array($groupType, self::DATE_TYPES, true))
                    || ($xType !== null && in_array($xType, self::DATE_TYPES, true)))) {
                $bucket = 'week';
            }

            $ownFilter = is_array($chart['filter'] ?? null) ? $chart['filter'] : null;
            $dataSource = array_filter([
                'object_id' => $chartObject['id'],
                'filter' => $withRange($ownFilter, $chartObject),
                'limit' => is_numeric($chart['limit'] ?? null) ? (int) $chart['limit'] : self::DASHBOARD_ROW_LIMIT,
            ], fn ($v) => $v !== null);

            $chartBlocks[] = array_filter([
                'id' => $this->id('blk'),
                'type' => 'chart',
                'label' => (string) ($chart['label'] ?? 'Chart'),
                'description' => Str::ucfirst(Str::limit(trim((string) ($chart['description'] ?? '')) !== ''
                    ? trim((string) $chart['description'])
                    : $this->chartDescription($chart, $chartType, $agg, $chartObject, $lang), 200)),
                'chart_type' => $chartType,
                // Clicking a category toggles the select filter's param — the
                // whole board re-scopes through wiring that already exists.
                'drill_param' => ($categoryFilter !== null
                    && collect($chartObject['fields'] ?? [])->contains(
                        fn ($f): bool => is_array($f)
                            && ($f['id'] ?? null) === ($chart['group_by_field_id'] ?? null)
                            && ($f['slug'] ?? null) === $categoryFilter['field_slug'],
                    ))
                    ? $categoryFilter['param'] : null,
                'data_source' => $dataSource,
                'aggregation' => $agg,
                'y_field_id' => $chart['y_field_id'] ?? null,
                'group_by_field_id' => $chart['group_by_field_id'] ?? null,
                'x_field_id' => $chart['x_field_id'] ?? null,
                'bucket' => $bucket,
                'series_field_id' => $chart['series_field_id'] ?? null,
                'stacked' => $chart['stacked'] ?? null,
            ], fn ($v) => $v !== null);
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // --- Deterministic layout: pair charts by their natural footprint ------
        $wide = $medium = $short = [];
        foreach ($chartBlocks as $block) {
            match (PlanDashboardTool::kindOf((string) ($block['type'] ?? 'chart'), (string) ($block['chart_type'] ?? ''))) {
                'wide' => $wide[] = $block,
                'short' => $short[] = $block,
                default => $medium[] = $block,
            };
        }

        $chartRows = [];
        while ($wide !== [] && $short !== []) {
            $w = array_shift($wide);
            $s = array_shift($short);
            $w['style'] = ['col_span' => 7];
            $s['style'] = ['col_span' => 5];
            $chartRows[] = [$w, $s];
        }
        while (count($short) >= 2) {
            $chartRows[] = [array_shift($short), array_shift($short)];
        }
        while (count($medium) >= 2) {
            $chartRows[] = [array_shift($medium), array_shift($medium)];
        }
        if ($medium !== [] && $short !== []) {
            $m = array_shift($medium);
            $s = array_shift($short);
            $m['style'] = ['col_span' => 7];
            $s['style'] = ['col_span' => 5];
            $chartRows[] = [$m, $s];
        }
        while ($wide !== []) {
            $chartRows[] = [array_shift($wide)];
        }
        while ($medium !== []) {
            $chartRows[] = [array_shift($medium)];
        }
        if ($short !== []) {
            // A leftover lone short chart joins the last roomy equal-width row
            // rather than leaving a half-empty row of its own.
            $placed = false;
            for ($ri = count($chartRows) - 1; $ri >= 0; $ri--) {
                $row = $chartRows[$ri];
                $hasSpans = array_filter($row, fn ($b) => isset($b['style']['col_span'])) !== [];
                if (count($row) < 3 && ! $hasSpans) {
                    $chartRows[$ri][] = array_shift($short);
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                if (count($short) === 1) {
                    // Nowhere to pair it and nothing to stack it with: a lone
                    // donut/pie fails the lone-short-block lint and would kill
                    // the whole compile. The same breakdown reads fine as bars
                    // at full width — pick a form not already at the variety cap.
                    $used = array_count_values(array_column($chartBlocks, 'chart_type'));
                    foreach (['bar', 'hbar', 'treemap'] as $roomier) {
                        if (($used[$roomier] ?? 0) < 2) {
                            $short[0]['chart_type'] = $roomier;
                            break;
                        }
                    }
                }
                $chartRows[] = array_splice($short, 0);
            }
        }

        // --- Assemble the page -------------------------------------------------
        $title = trim((string) ($spec['title'] ?? '')) ?: $object['name'];
        $blocks = [];
        $planRows = [];

        if ((bool) ($spec['include_hero'] ?? true)) {
            $hero = [
                'id' => $this->id('hro'),
                'type' => 'hero',
                'title' => $title,
                'eyebrow' => SemanticLexicon::for($lang)->label('report'),
                'eyebrow_icon' => 'bar-chart',
                'align' => 'left',
                'min_height' => 120,
            ];
            // Float the headline KPI into the hero as a live figure — the
            // executive-summary number. A PROVEN rate wins ("96.7% OTD" is the
            // number leadership reads): a KPI with a ratio_denominator is a real
            // sum/sum rate. Next a MEANINGFUL avg — a real quantity like average
            // resolution hours or a score (aggregation avg, NOT a percentage) —
            // which normalises for scale better than a raw total. Then a VOLUME
            // (a sum/count total — "1.5M tickets"). A bare PERCENTAGE avg is
            // excluded from the avg tier: it's usually a pre-computed share (a
            // Pareto's "% of total" ≈ 100/N — a meaningless headline), so it
            // falls to a real total or the first KPI rather than leading.
            $lead = collect($items)->first(fn (array $k): bool => is_array($k['ratio_denominator'] ?? null))
                ?? collect($items)->first(fn (array $k): bool => ($k['aggregation'] ?? null) === 'avg' && ($k['format'] ?? null) !== 'percentage')
                ?? collect($items)->first(fn (array $k): bool => in_array($k['aggregation'] ?? null, ['sum', 'count'], true))
                ?? ($items[0] ?? null);
            if (is_array($lead) && isset($lead['query'], $lead['aggregation'])) {
                $hero['stat'] = array_filter([
                    'label' => (string) ($lead['label'] ?? ''),
                    'query' => $lead['query'],
                    'aggregation' => $lead['aggregation'],
                    'field_id' => $lead['field_id'] ?? null,
                    // A rate KPI is sum(numerator) ÷ ratio_denominator, NOT a
                    // bare sum — carry the denominator so the hero recomputes the
                    // ratio live. Dropping it summed the numerator alone and
                    // printed it as a percentage (1.8M% "OTD").
                    'ratio_denominator' => $lead['ratio_denominator'] ?? null,
                    'format' => $lead['format'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
            }
            if (is_array($palette['ramp'] ?? null) && isset($palette['ramp']['900'], $palette['ramp']['600'])) {
                // Reference the ramp VARS, not the hexes they resolve to today.
                // Baking the hex froze the hero at whatever palette was active
                // when the board compiled, so switching palette_mode to "Escala
                // grises" later left a blue hero on an otherwise grey board. The
                // hex stays as the var's fallback, so a surface that doesn't
                // define the ramp still renders the intended gradient.
                $hero['style'] = ['gradient' => [
                    'from' => "var(--sp-accent-900, {$palette['ramp']['900']})",
                    'to' => "var(--sp-accent-600, {$palette['ramp']['600']})",
                    'direction' => 'to-br',
                ]];
            }
            $blocks[] = $hero; // chrome — not a lint row
        } else {
            $blocks[] = ['id' => $this->id('blk'), 'type' => 'heading', 'content' => $title];
        }

        // Only controls at least one block actually listens to — a filter bar
        // over unwired blocks is a control that does nothing.
        $controls = [];
        if ($withDateFilter && $rangeWired) {
            // Matches the range condition's default so the active preset on
            // open reflects the window the blocks actually query.
            $controls[] = ['param' => 'range', 'type' => 'date_range', 'default' => $defaultRange];
        }
        if ($categoryFilter !== null && $categoryWired) {
            $controls[] = [
                'param' => $categoryFilter['param'],
                'type' => 'select',
                'label' => Str::limit($categoryFilter['label'], 60, ''),
                'options' => array_map(
                    fn (string $v): array => ['value' => Str::limit($v, 120, ''), 'label' => Str::limit($v, 120, '')],
                    $categoryFilter['options'],
                ),
            ];
        }
        if ($controls !== []) {
            $blocks[] = ['id' => $this->id('blk'), 'type' => 'filter_bar', 'controls' => $controls];
            $planRows[] = ['blocks' => [['type' => 'filter_bar']]];
        }

        // Keep only the headline KPIs. The suggester emits them most-important
        // first, so the first N are the ones worth the top row; more than this
        // reads as a wall of numbers and pushes the charts below the fold.
        $items = array_slice($items, 0, self::DASHBOARD_KPI_CAP);
        $blocks[] = [
            'id' => $this->id('blk'),
            'type' => 'metric_grid',
            'columns' => count($items) <= 6 ? max(count($items), 3) : 4,
            'items' => $items,
        ];
        $planRows[] = ['blocks' => [['type' => 'metric_grid']]];

        // Narrative sections: short headings that make the board read as a
        // story (trend → breakdown → readings) instead of a pile of charts.
        // Emitted only when both a temporal and a categorical group exist, so
        // a small board isn't over-chaptered. Overridable via spec.sections.
        $sections = is_array($spec['sections'] ?? null) ? $spec['sections'] : [];
        $lex = SemanticLexicon::for($lang);
        $sectionLabels = [
            'trend' => (string) ($sections['trend'] ?? $lex->label('trend')),
            'breakdown' => (string) ($sections['breakdown'] ?? $lex->label('breakdown')),
            'insights' => (string) ($sections['insights'] ?? $lex->label('insights')),
        ];
        $rowIsTemporal = fn (array $row): bool => collect($row)->contains(
            fn (array $b): bool => isset($b['x_field_id']) || isset($b['bucket']),
        );
        $temporalRows = collect($chartRows)->filter($rowIsTemporal)->count();
        $useSections = $temporalRows > 0 && $temporalRows < count($chartRows);
        if ($useSections) {
            // Story order: every trend row precedes every breakdown row, so
            // each chapter heading is emitted exactly once.
            $chartRows = array_merge(
                array_values(array_filter($chartRows, $rowIsTemporal)),
                array_values(array_filter($chartRows, fn (array $r): bool => ! $rowIsTemporal($r))),
            );
        }
        $emittedSection = null;

        foreach ($chartRows as $row) {
            if ($useSections) {
                $section = $rowIsTemporal($row) ? 'trend' : 'breakdown';
                if ($section !== $emittedSection) {
                    $blocks[] = ['id' => $this->id('blk'), 'type' => 'heading', 'level' => 3, 'content' => $sectionLabels[$section]];
                    $emittedSection = $section;
                }
            }
            $blocks[] = [
                'id' => $this->id('cn'),
                'type' => 'container',
                'direction' => 'row',
                'gap' => 'md',
                'blocks' => array_values($row),
            ];
            $planRows[] = ['section' => $useSections ? $sectionLabels[$rowIsTemporal($row) ? 'trend' : 'breakdown'] : null] + ['blocks' => array_map(fn (array $b): array => array_filter([
                'type' => (string) ($b['type'] ?? 'chart'),
                'chart_type' => $b['chart_type'] ?? null,
                'col_span' => $b['style']['col_span'] ?? null,
            ], fn ($v) => $v !== null), $row)];
        }

        // The flagship detail table: the rows BEHIND the charts, right
        // columns, honest sort — where a manager checks the specific cases.
        if (is_array($spec['table'] ?? null)) {
            $tableSpec = $spec['table'];
            $columns = collect($tableSpec['columns'] ?? [])
                ->filter(fn ($fid): bool => is_string($fid)
                    && collect($object['fields'] ?? [])->firstWhere('id', $fid) !== null)
                ->take(5)->values();
            $sort = collect($tableSpec['sort'] ?? [])
                ->filter(fn ($s): bool => is_array($s)
                    && collect($object['fields'] ?? [])->firstWhere('id', $s['field_id'] ?? null) !== null)
                ->take(1)->values()->all();
            if ($columns->count() >= 2) {
                if ($useSections) {
                    $blocks[] = ['id' => $this->id('blk'), 'type' => 'heading', 'level' => 3, 'content' => $lex->label('detail')];
                }
                $blocks[] = array_filter([
                    'id' => $this->id('blk'),
                    'type' => 'table',
                    'columns' => $columns->map(fn (string $fid): array => ['id' => $this->id('col'), 'field_id' => $fid])->all(),
                    'data_source' => array_filter([
                        'object_id' => $object['id'],
                        'filter' => $withRange(null, $object),
                        'sort' => $sort !== [] ? $sort : null,
                        'limit' => max(5, min(25, (int) ($tableSpec['limit'] ?? 10))),
                    ], fn ($v) => $v !== null),
                    'pagination' => ['page_size' => max(5, min(25, (int) ($tableSpec['limit'] ?? 10)))],
                ], fn ($v) => $v !== null);
                $planRows[] = ['blocks' => [['type' => 'table']]];
            }
        }

        $emittedInsightsHeading = false;
        foreach (array_chunk($insights, 3) as $chunk) {
            if ($useSections && ! $emittedInsightsHeading && $insights !== []) {
                $blocks[] = ['id' => $this->id('blk'), 'type' => 'heading', 'level' => 3, 'content' => $sectionLabels['insights']];
                $emittedInsightsHeading = true;
            }
            $insightBlocks = array_map(fn (array $ins): array => array_filter([
                'id' => $this->id('in'),
                'type' => 'insight',
                'variant' => $ins['variant'] ?? 'insight',
                'title' => (string) ($ins['title'] ?? 'Insight'),
                'body' => $ins['body'] ?? null,
                'metric_label' => isset($ins['metric_label']) ? (string) $ins['metric_label'] : null,
                'compute' => is_array($ins['compute'] ?? null) ? $ins['compute'] : null,
            ], fn ($v) => $v !== null), $chunk);
            $blocks[] = [
                'id' => $this->id('cn'),
                'type' => 'container',
                'direction' => 'row',
                'gap' => 'md',
                'blocks' => array_values($insightBlocks),
            ];
            $planRows[] = ['blocks' => array_map(fn () => ['type' => 'insight'], $chunk)];
        }

        $pageSlug = $this->uniqueSlugAmong('dashboard', $takenPageSlugs);

        return [
            'ok' => true,
            'dropped_charts' => $droppedCharts,
            'page' => [
                'id' => $this->id('pag'),
                'slug' => $pageSlug,
                'name' => $title,
                'path' => '/'.$pageSlug,
                'blocks' => $blocks,
            ],
            'plan_rows' => $planRows,
            'purpose' => trim((string) ($spec['purpose'] ?? '')) ?: "Vista ejecutiva de {$object['name']}: KPIs, tendencias y conclusiones.",
        ];
    }

    /**
     * One executive line under a chart's title: WHAT it shows and HOW to read
     * it, written deterministically from the form + measure + dimension. A
     * spec-provided description always wins; this is the floor every compiled
     * chart gets so no visual ships unexplained.
     *
     * @param  array<string, mixed>  $chart
     * @param  array<string, mixed>  $object
     */
    private function chartDescription(array $chart, string $chartType, string $agg, array $object, string $lang): string
    {
        $nameOf = function (?string $fieldId) use ($object): ?string {
            if ($fieldId === null || $fieldId === '') {
                return null;
            }
            $field = collect($object['fields'] ?? [])->firstWhere('id', $fieldId);

            return $field !== null ? Str::lower((string) ($field['name'] ?? $field['slug'])) : null;
        };

        return SemanticLexicon::for($lang)->chartDescription(
            $chartType,
            $nameOf($chart['y_field_id'] ?? null),
            $nameOf($chart['group_by_field_id'] ?? null),
            $nameOf($chart['x_field_id'] ?? null),
            $nameOf($chart['series_field_id'] ?? null),
            is_string($chart['bucket'] ?? null) ? $chart['bucket'] : null,
        );
    }

    /**
     * Translate a funnel-intent chart entry into the dedicated funnel block:
     * one stage per named category value, each an eq-filtered aggregate over
     * the SAME object (count of rows, or sum of the entry's y_field). Stage
     * values come from the suggester's sampled data or, for a single_select,
     * the field's authored options — the compiler itself has no rows. 2-8
     * stages or it isn't a funnel.
     *
     * @param  array<string, mixed>  $chart
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, mixed>|null
     */
    private function funnelBlockFromChart(array $chart, array $object, int $i, array &$errors, \Closure $withRange): ?array
    {
        $group = collect($object['fields'] ?? [])->firstWhere('id', $chart['group_by_field_id'] ?? null);
        if ($group === null) {
            $errors[] = ['path' => "/charts/{$i}/group_by_field_id", 'message' => 'A funnel needs group_by_field_id — the category whose values are the stages.', 'code' => 'degenerate_chart'];

            return null;
        }

        $stages = collect($chart['stages'] ?? [])
            ->map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '')
            ->filter()->unique()->values();
        if ($stages->isEmpty() && ($group['type'] ?? '') === 'single_select') {
            $stages = collect($group['options'] ?? [])
                ->map(fn ($o): string => trim((string) (is_array($o) ? ($o['value'] ?? '') : $o)))
                ->filter()->values();
        }
        if ($stages->count() < 2 || $stages->count() > 8) {
            $errors[] = ['path' => "/charts/{$i}/stages", 'message' => 'A funnel needs 2-8 stages — pass the category values in order (or use a single_select group field whose options define them).', 'code' => 'degenerate_chart'];

            return null;
        }

        $sum = ($chart['aggregation'] ?? 'count') === 'sum' && isset($chart['y_field_id']);

        return [
            'id' => $this->id('blk'),
            'type' => 'funnel',
            'label' => (string) ($chart['label'] ?? 'Embudo'),
            'stages' => $stages->map(fn (string $value): array => array_filter([
                'id' => $this->id('stg'),
                'label' => Str::limit($value, 80, ''),
                'query' => [
                    'object_id' => $object['id'],
                    'filter' => $withRange(['op' => 'eq', 'field_id' => $group['id'], 'value' => $value], $object),
                ],
                'aggregation' => $sum ? 'sum' : 'count',
                'field_id' => $sum ? $chart['y_field_id'] : null,
            ], fn ($v) => $v !== null))->all(),
        ];
    }

    /**
     * Translate a target-intent chart entry into the dedicated gauge block:
     * one aggregate of a numeric field against the max_value the ask named.
     *
     * @param  array<string, mixed>  $chart
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, mixed>|null
     */
    private function gaugeBlockFromChart(array $chart, array $object, int $i, array &$errors, \Closure $withRange): ?array
    {
        $field = collect($object['fields'] ?? [])->firstWhere('id', $chart['y_field_id'] ?? null);
        $max = is_numeric($chart['max_value'] ?? null) ? (float) $chart['max_value'] : null;
        if ($field === null || ! in_array($field['type'] ?? '', self::NUMERIC_TYPES, true) || $max === null || $max <= 0) {
            $errors[] = ['path' => "/charts/{$i}", 'message' => 'A gauge needs a numeric y_field_id and a positive max_value (the target).', 'code' => 'degenerate_chart'];

            return null;
        }

        return array_filter([
            'id' => $this->id('blk'),
            'type' => 'gauge',
            'label' => Str::limit((string) ($chart['label'] ?? 'Meta'), 80, ''),
            'query' => array_filter([
                'object_id' => $object['id'],
                'filter' => $withRange(null, $object),
            ], fn ($v) => $v !== null),
            'aggregation' => in_array($chart['aggregation'] ?? null, ['count', 'sum', 'avg', 'min', 'max'], true) ? $chart['aggregation'] : 'sum',
            'field_id' => $field['id'],
            'max_value' => $max,
            'format' => in_array($chart['format'] ?? null, ['number', 'currency', 'percentage'], true) ? $chart['format'] : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Translate a heatmap-intent chart entry into the calendar-heatmap block
     * (records per day over the trailing weeks). Needs a date/datetime field
     * — the entry's x_field_id — and record-level rows to count.
     *
     * @param  array<string, mixed>  $chart
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, mixed>|null
     */
    private function heatmapBlockFromChart(array $chart, array $object, int $i, array &$errors): ?array
    {
        $date = collect($object['fields'] ?? [])->firstWhere('id', $chart['x_field_id'] ?? ($chart['date_field_id'] ?? null));
        if ($date === null || ! in_array($date['type'] ?? '', self::DATE_TYPES, true)) {
            $errors[] = ['path' => "/charts/{$i}/x_field_id", 'message' => 'A heatmap counts records per DAY — set x_field_id to a date/datetime field.', 'code' => 'degenerate_chart'];

            return null;
        }

        return [
            'id' => $this->id('blk'),
            'type' => 'heatmap',
            'label' => (string) ($chart['label'] ?? 'Actividad'),
            'data_source' => ['object_id' => $object['id']],
            'date_field_id' => $date['id'],
        ];
    }

    /**
     * A short caption naming what the KPI number IS — its aggregation basis.
     * The card label is often just the field name ("Otd Pct Global"); this
     * disambiguates promedio vs suma vs mediana. Filter-safe by design: it
     * describes the number's KIND, never a value that would go stale on filter.
     */
    private function kpiSubtitle(string $aggregation, string $lang): string
    {
        return SemanticLexicon::for($lang)->kpiSubtitle($aggregation);
    }

    /**
     * An icon the runtime can actually draw: a real Lucide name (normalized —
     * curated or not, ALL_NAMES covers both), an emoji, or nothing. A
     * slug-like name outside even the FULL Lucide set would render as raw
     * text beside the KPI ("thumbs-down" shipped once before it was added) —
     * dropped.
     */
    private function renderableIcon(mixed $icon): ?string
    {
        if (! is_string($icon) || trim($icon) === '') {
            return null;
        }
        $icon = trim($icon);
        $normalized = strtolower((string) preg_replace('/[\s_]+/', '-', $icon));
        if (in_array($normalized, IconCatalog::ALL_NAMES, true)) {
            return $normalized;
        }

        return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/i', $icon) === 1 ? null : $icon;
    }

    /**
     * A page slug unique among the given taken slugs.
     *
     * @param  list<string>  $taken
     */
    private function uniqueSlugAmong(string $base, array $taken): string
    {
        $slug = $base;
        $n = 2;
        while (in_array($slug, $taken, true)) {
            $slug = $base.'_'.$n++;
        }

        return $slug;
    }

    /**
     * A master-detail page for a parent record: a breadcrumb back to the list, the
     * parent's fields (record_detail), then per child relationship an inline
     * "add child" form (with the link back to this parent preset from the page id)
     * and a related_list of that parent's children.
     *
     * @param  array{id: string, name: string, slug: string}  $parentDef
     * @param  array<int, array{id: string, slug: string, type: string}>  $parentPageFields
     * @param  array<int, array{def: array<string, mixed>, pageFields: array<int, array{id: string, slug: string, type: string}>, childFieldId: string, childFieldSlug: string}>  $children
     * @return array<string, mixed>
     */
    public function buildDetailPage(array $parentDef, array $parentPageFields, string $detailSlug, array $children, string $lang): array
    {
        $singular = Inflector::singular($parentDef['name'], $lang);

        $blocks = [
            [
                'id' => $this->id('blk'),
                'type' => 'breadcrumb',
                'items' => [
                    ['label' => $parentDef['name'], 'href' => '/'.$parentDef['slug']],
                    ['label' => $singular],
                ],
            ],
            [
                'id' => $this->id('blk'),
                'type' => 'record_detail',
                'label' => $singular,
                'object_id' => $parentDef['id'],
                'record_id_expression' => '{{params.id}}',
                'fields' => array_map(fn (array $f): array => ['field_id' => $f['id']], $parentPageFields),
            ],
        ];

        foreach ($this->detailRecordActions($parentDef, $parentPageFields, $singular, $lang) as $block) {
            $blocks[] = $block;
        }

        // What has happened to this record, and where somebody says why.
        //
        // Every app needs this and none of them would ask for it: "the status
        // changed on Tuesday, and Ana wrote that the customer never called
        // back" is the answer to most questions asked of a record, and without
        // it a record is a set of current values with no memory.
        $blocks[] = [
            'id' => $this->id('blk'),
            'type' => 'record_activity',
            'record_id_expression' => '{{params.id}}',
        ];

        foreach ($children as $child) {
            $childDef = $child['def'];
            $childFieldId = $child['childFieldId'];
            $childSingular = Inflector::singular($childDef['name'], $lang);

            // The add-child form: the child's enterable fields minus the relation
            // back to this parent (preset from the page id) and computed fields.
            $formIndex = array_values(array_filter(
                $child['pageFields'],
                fn (array $f): bool => $f['id'] !== $childFieldId && ! in_array($f['type'] ?? 'string', self::DERIVED_TYPES, true),
            ));
            $formFields = array_map(fn (array $f): array => ['field_id' => $f['id']], $formIndex);
            $createValues = [$child['childFieldSlug'] => '{{params.id}}'];
            foreach ($formIndex as $f) {
                $createValues[$f['slug']] = '{{form.'.$f['slug'].'}}';
            }

            $modalId = $this->id('blk');

            $blocks[] = ['id' => $this->id('blk'), 'type' => 'heading', 'level' => 3, 'content' => $childDef['name']];
            $blocks[] = [
                'id' => $modalId,
                'type' => 'modal',
                'title' => $this->labelNew($lang, $childSingular),
                'blocks' => [[
                    'id' => $this->id('blk'),
                    'type' => 'form',
                    'object_id' => $childDef['id'],
                    'mode' => 'create',
                    'fields' => $formFields,
                    'submit_label' => $this->labelSubmit($lang),
                    'on_submit' => [
                        ['type' => 'create_record', 'object_id' => $childDef['id'], 'values' => $createValues],
                        ['type' => 'close_modal'],
                        ['type' => 'show_toast', 'level' => 'success', 'message' => $this->toastSaved($lang, $childSingular)],
                        ['type' => 'refresh'],
                    ],
                ]],
            ];
            $blocks[] = [
                'id' => $this->id('blk'),
                'type' => 'button',
                'label' => $this->labelNew($lang, $childSingular),
                'variant' => 'primary',
                'on_click' => [['type' => 'open_modal', 'modal_block_id' => $modalId]],
            ];
            // The child's own edit form. A child object has no page of its
            // own, so this list IS its screen: without these two columns a line
            // item could be added and then never corrected or removed, which is
            // how a mistyped quantity became permanent.
            $editModalId = $this->id('blk');
            $editValues = [];
            foreach ($formIndex as $f) {
                $editValues[$f['slug']] = '{{form.'.$f['slug'].'}}';
            }

            $blocks[] = [
                'id' => $editModalId,
                'type' => 'modal',
                'title' => $this->labelEditTitle($lang, $childSingular),
                'blocks' => [[
                    'id' => $this->id('blk'),
                    'type' => 'form',
                    'object_id' => $childDef['id'],
                    'mode' => 'edit',
                    'record_id_expression' => '{{params.record_id}}',
                    'fields' => $formFields,
                    'submit_label' => $this->labelSave($lang),
                    'on_submit' => [
                        ['type' => 'update_record', 'object_id' => $childDef['id'], 'record_id_expression' => '{{params.record_id}}', 'values' => $editValues],
                        ['type' => 'close_modal'],
                        ['type' => 'show_toast', 'level' => 'success', 'message' => $this->toastSaved($lang, $childSingular)],
                        ['type' => 'refresh'],
                    ],
                ]],
            ];

            $lex = SemanticLexicon::for($lang);
            $childColumns = array_map(fn (array $f): array => ['field_id' => $f['id']], array_values(array_filter(
                $child['pageFields'],
                fn (array $f): bool => $f['id'] !== $childFieldId,
            )));
            $childColumns[] = [
                'id' => $this->id('col'),
                'type' => 'action',
                'label' => $this->labelEdit($lang),
                'icon' => 'pencil',
                'variant' => 'ghost',
                'on_click' => [[
                    'type' => 'open_modal',
                    'modal_block_id' => $editModalId,
                    'params' => ['record_id' => '{{row.id}}', 'record' => '{{row.data}}'],
                ]],
            ];
            $childColumns[] = [
                'id' => $this->id('col'),
                'type' => 'action',
                'label' => $lex->label('delete'),
                'icon' => 'trash-2',
                'variant' => 'danger',
                // Same gate the detail page's Delete carries: the scaffold
                // grants delete to `admin` alone, so nobody else is offered a
                // button the executor is going to refuse.
                'visibility' => ['roles' => ['admin']],
                'confirm' => [
                    'title' => $lex->label('delete_title', singular: $childSingular),
                    'message' => $lex->label('delete_message'),
                ],
                'on_click' => [
                    ['type' => 'delete_record', 'object_id' => $childDef['id'], 'record_id_expression' => '{{row.id}}'],
                    ['type' => 'refresh'],
                ],
            ];

            $blocks[] = [
                'id' => $this->id('blk'),
                'type' => 'related_list',
                'object_id' => $childDef['id'],
                'via_relation_field_id' => $childFieldId,
                'parent_id_expression' => '{{params.id}}',
                'columns' => $childColumns,
            ];
        }

        return [
            'id' => $this->id('pag'),
            'slug' => $detailSlug,
            'name' => $singular,
            'path' => '/'.$detailSlug,
            'blocks' => $blocks,
        ];
    }

    /**
     * Append an "open" row action to a list page's table block so each row links
     * to its detail page, passing the row id via the URL (read as {{params.id}}).
     *
     * @param  array<string, mixed>  $page
     */
    private function addRowActionToTable(array &$page, string $detailSlug, string $lang): void
    {
        $column = [
            'id' => $this->id('col'),
            'type' => 'action',
            'label' => SemanticLexicon::for($lang)->label('open'),
            'icon' => 'arrow-right',
            'variant' => 'ghost',
            'on_click' => [['type' => 'navigate', 'to' => '/'.$detailSlug.'?id={{row.id}}']],
        ];

        // Recursive because the table is no longer a top-level block: it sits in
        // the first tab beside the board and the calendar. Scanning only the top
        // level silently dropped the row action, and with it the only way into
        // the detail page — the design lint caught it as a record_detail whose
        // {{params.id}} nothing provides.
        $this->attachOpenColumn($page['blocks'], $column);
    }

    /**
     * Append $column to the first table found anywhere in $blocks. Returns true
     * once it has landed, so the search stops at the first table.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $column
     */
    private function attachOpenColumn(array &$blocks, array $column): bool
    {
        foreach ($blocks as &$block) {
            if (($block['type'] ?? null) === 'table') {
                $block['columns'][] = $column;

                return true;
            }

            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (isset($block[$key]) && is_array($block[$key]) && $this->attachOpenColumn($block[$key], $column)) {
                    return true;
                }
            }

            foreach (['tabs', 'sections'] as $key) {
                foreach ($block[$key] ?? [] as $ci => $child) {
                    $children = $child['blocks'] ?? [];
                    if (is_array($children) && $this->attachOpenColumn($children, $column)) {
                        $block[$key][$ci]['blocks'] = $children;

                        return true;
                    }
                }
            }
        }
        unset($block);

        return false;
    }

    /**
     * Detect a POS-shaped triad — an order object linked from a line object that
     * also links to a priced product — and synthesise the line economics so a
     * generated POS screen computes: a unit-price LOOKUP of the product price, a
     * SUBTOTAL formula (qty × price) and an order TOTAL rollup (sum of subtotals).
     * Mutates $built (adds the fields) and returns a spec per triad for the page.
     *
     * @param  array<int, array{def: array<string, mixed>, pageFields: array<int, array<string, mixed>>}>  $built
     * @param  array<int, array<int, array{targetIndex: int, childFieldId: string, childFieldSlug: string, parentFieldId: string}>>  $relationsByChild
     * @return array<int, array<string, mixed>>
     */
    private function detectAndBuildPosEconomics(array &$built, array $relationsByChild, string $currency, string $lang, string $request = ''): array
    {
        // A whole point-of-sale module is the largest thing this scaffolder
        // invents on its own, and inventing it wrongly is not free: the closing
        // critic reports the page as unrequested and a review turn deletes it,
        // on every build. Measured on one field-service brief, that happened in
        // four separate runs.
        //
        // The structural shape (order <- line -> product) is not evidence of a
        // shop; neither is the `commerce` vocabulary, which covers order, line,
        // service and ticket and therefore matches most operations apps — the
        // existing triad guard reads that vocabulary and passed all four times.
        // So the REQUEST has to actually ask to sell. When there is no request
        // text (a template, a hand-assembled spec, the older tests) nothing
        // changes.
        if ($request !== '' && ! SemanticLexicon::for($lang)->matches('pos_intent', $request)) {
            return [];
        }

        $labels = $this->posLabels($lang);
        $specs = [];

        foreach ($relationsByChild as $lineIndex => $rels) {
            if (count($rels) < 2) {
                continue;
            }

            // Product = a related object that has a price (currency) field + a title.
            $productRel = $productPrice = $productTitle = null;
            foreach ($rels as $rel) {
                $def = $built[$rel['targetIndex']]['def'];
                $price = $this->firstDefFieldOfType($def, ['currency']);
                $title = $this->firstDefFieldOfType($def, ['string']);
                if ($price !== null && $title !== null) {
                    $productRel = $rel;
                    $productPrice = $price;
                    $productTitle = $title;
                    break;
                }
            }
            if ($productRel === null) {
                continue;
            }

            // Order = the first OTHER belongs-to (the parent that isn't the product).
            $orderRel = null;
            foreach ($rels as $rel) {
                if ($rel['targetIndex'] !== $productRel['targetIndex']) {
                    $orderRel = $rel;
                    break;
                }
            }
            if ($orderRel === null) {
                continue;
            }

            $orderDef = $built[$orderRel['targetIndex']]['def'];
            $orderStatus = $this->firstDefFieldOfType($orderDef, ['single_select']);
            $newOrderValues = $this->posNewOrderValues($orderDef, $orderStatus);
            if ($newOrderValues === []) {
                // No seedable field to open an order with → can't drive a POS flow.
                continue;
            }

            // Semantic guard: a structural order←line→product shape is a POINT OF
            // SALE only when it reads like commerce — the product's currency field
            // is an actual SALE PRICE (not a project budget or a cost) and a name in
            // the triad carries sale intent. Without this a `budget`/`presupuesto`
            // currency field turns tasks/milestones into "order lines" and spawns
            // bogus POS screens (an NPD app produced two "Punto de venta" pages).
            if (! $this->isCommerceTriad($orderDef, $built[$lineIndex]['def'], $built[$productRel['targetIndex']]['def'], $productPrice, $lang)) {
                continue;
            }

            $lineDef = &$built[$lineIndex]['def'];
            $taken = array_column($lineDef['fields'], 'slug');

            // Quantity: reuse a number field that reads like a quantity, else add one.
            $qty = $this->quantityFieldOf($lineDef, $lang);
            if ($qty === null) {
                $slug = $this->uniqueSlug('cantidad', $taken, 'cantidad');
                $taken[] = $slug;
                [$qty, $qtyIdx] = $this->buildField(['name' => $labels['qty'], 'slug' => $slug, 'type' => 'number', 'options' => null, 'config' => ['default' => 1, 'min' => 1]], $currency);
                $lineDef['fields'][] = $qty;
                $built[$lineIndex]['pageFields'][] = $qtyIdx;
            }

            // Unit price: a lookup of the product price across the line→product rel.
            // Unit price: looked up off the product so the line cannot drift from
            // the catalogue. Reuse the one the model already put on the line —
            // adding a second gave the object two fields both called "Precio
            // unitario", side by side in the table.
            $existingPrice = $this->unitPriceFieldOf($lineDef, $lang);
            if ($existingPrice !== null) {
                $priceSlug = (string) $existingPrice['slug'];
                $precio = [
                    'id' => $existingPrice['id'],
                    'slug' => $priceSlug,
                    'name' => $existingPrice['name'] ?? $labels['unit_price'],
                    'type' => 'lookup',
                    'readonly' => true,
                    'via_relation_field_id' => $productRel['childFieldId'],
                    'target_field_id' => $productPrice['id'],
                ];
                foreach ($lineDef['fields'] as $fi => $f) {
                    if (($f['id'] ?? null) === $existingPrice['id']) {
                        $lineDef['fields'][$fi] = $precio;
                        break;
                    }
                }
                foreach ($built[$lineIndex]['pageFields'] as $pi => $idx) {
                    if (($idx['id'] ?? null) === $existingPrice['id']) {
                        $built[$lineIndex]['pageFields'][$pi]['type'] = 'lookup';
                        break;
                    }
                }
            } else {
                $priceSlug = $this->uniqueSlug('precio_unitario', $taken, 'precio');
                $taken[] = $priceSlug;
                [$precio, $precioIdx] = $this->buildField(['name' => $labels['unit_price'], 'slug' => $priceSlug, 'type' => 'lookup', 'options' => null, 'config' => ['via_relation_field_id' => $productRel['childFieldId'], 'target_field_id' => $productPrice['id']]], $currency);
                $lineDef['fields'][] = $precio;
                $built[$lineIndex]['pageFields'][] = $precioIdx;
            }

            // Subtotal: qty × unit price. Reuse an amount field the model already
            // put on the line (convert it to the formula in place, keeping its
            // id/slug/name) instead of adding a duplicate; else synthesise one.
            $expression = '{{'.$qty['slug'].' * '.$priceSlug.'}}';
            $existingAmount = $this->subtotalFieldOf($lineDef, $lang);
            if ($existingAmount !== null) {
                $subtotal = [
                    'id' => $existingAmount['id'],
                    'slug' => $existingAmount['slug'],
                    'name' => $existingAmount['name'] ?? $labels['subtotal'],
                    'type' => 'formula',
                    'readonly' => true,
                    'expression' => $expression,
                    'return_type' => 'number',
                    'currency_code' => $currency,
                ];
                foreach ($lineDef['fields'] as $fi => $f) {
                    if (($f['id'] ?? null) === $existingAmount['id']) {
                        $lineDef['fields'][$fi] = $subtotal;
                        break;
                    }
                }
                // Reflect the type change in the page index so the now-computed
                // field is dropped from create forms but stays in tables.
                foreach ($built[$lineIndex]['pageFields'] as $pi => $idx) {
                    if (($idx['id'] ?? null) === $existingAmount['id']) {
                        $built[$lineIndex]['pageFields'][$pi]['type'] = 'formula';
                        break;
                    }
                }
            } else {
                $subSlug = $this->uniqueSlug('subtotal', $taken, 'subtotal');
                [$subtotal, $subIdx] = $this->buildField(['name' => $labels['subtotal'], 'slug' => $subSlug, 'type' => 'formula', 'options' => null, 'config' => ['expression' => $expression, 'return_type' => 'number', 'currency_code' => $currency]], $currency);
                $lineDef['fields'][] = $subtotal;
                $built[$lineIndex]['pageFields'][] = $subIdx;
            }

            // When the unit price was the only money on the line, the relation
            // pass had nothing else to total and pointed the parent's rollup at
            // it — adding prices-per-unit across rows. Now that the line has a
            // subtotal, that is what the parent was always adding up.
            $this->repointLineRollups($built, (string) $precio['id'], (string) $subtotal['id']);

            // Order total: rollup SUM of the line subtotals. Reuse the sum rollup
            // buildRelation already added over this field (the common case when the
            // model gave the line an amount) instead of adding a second total.
            $orderDefRef = &$built[$orderRel['targetIndex']]['def'];
            $total = null;
            foreach ($orderDefRef['fields'] as $f) {
                if (($f['type'] ?? null) === 'rollup'
                    && ($f['aggregator'] ?? null) === 'sum'
                    && ($f['target_field_id'] ?? null) === $subtotal['id']) {
                    $total = $f;
                    break;
                }
            }
            if ($total === null) {
                $totalSlug = $this->uniqueSlug('total', array_column($orderDefRef['fields'], 'slug'), 'total');
                [$total, $totalIdx] = $this->buildField(['name' => $labels['total'], 'slug' => $totalSlug, 'type' => 'rollup', 'options' => null, 'config' => ['via_relation_field_id' => $orderRel['parentFieldId'], 'aggregator' => 'sum', 'target_field_id' => $subtotal['id']]], $currency);
                $orderDefRef['fields'][] = $total;
                $built[$orderRel['targetIndex']]['pageFields'][] = $totalIdx;
            }

            // A picker of bare rectangles is the thing this screen exists to
            // avoid, so when the product has nowhere to put a picture, give it
            // one. Not padding: it is the field the screen being built needs,
            // and it stays empty until someone fills it.
            if ($this->imageFieldOf($built[$productRel['targetIndex']]['def'], $lang) === null) {
                $productFields = &$built[$productRel['targetIndex']]['def']['fields'];
                $photoSlug = $this->uniqueSlug('foto', array_column($productFields, 'slug'), 'foto');
                [$photo, $photoIdx] = $this->buildField([
                    'name' => $labels['photo'],
                    'slug' => $photoSlug,
                    'type' => 'string',
                    'options' => null,
                ], $currency);
                $productFields[] = $photo;
                $built[$productRel['targetIndex']]['pageFields'][] = $photoIdx;
                unset($productFields);
            }

            $productDef = $built[$productRel['targetIndex']]['def'];
            $specs[] = [
                'order_object_id' => $orderDefRef['id'],
                'line_object_id' => $lineDef['id'],
                'product_object_id' => $productDef['id'],
                'product_title_field_id' => $productTitle['id'],
                'product_price_field_id' => $productPrice['id'],
                'product_image_field_id' => $this->imageFieldOf($productDef, $lang)['id'] ?? null,
                'line_order_rel_field_id' => $orderRel['childFieldId'],
                'line_order_rel_slug' => $orderRel['childFieldSlug'],
                'line_product_rel_field_id' => $productRel['childFieldId'],
                'line_product_rel_slug' => $productRel['childFieldSlug'],
                'qty_field_id' => $qty['id'],
                'qty_slug' => $qty['slug'],
                'subtotal_field_id' => $subtotal['id'],
                'order_total_field_id' => $total['id'],
                'order_status_field_id' => $orderStatus['id'] ?? null,
                'new_order_values' => $newOrderValues,
            ];

            // Dedup: one POS screen is the intended output. Stop after the first
            // qualifying triad so a schema with several priced parents (a project
            // budget AND a product budget) can't spawn duplicate "Point of Sale"
            // pages — and we don't graft POS economics onto extra line objects.
            break;
        }

        return $specs;
    }

    /**
     * What the "new order" button writes.
     *
     * `values` is the WHOLE payload — nothing merges anything into it — so this
     * has to satisfy every REQUIRED field of the order object or the button is
     * refused on every press. It did not: the status was set, the required
     * folio beside it was not, and the till's first tap failed on a page the
     * scaffolder had just built. Seeding a required field with `''` (the old
     * fallback) fails the same check, since blank is what required means.
     *
     * Only values that are honestly derivable at a till: a status opens on its
     * first option, an identifier nobody has typed yet gets a timestamp folio,
     * a date is today, a number is zero. A required field we cannot fill — a
     * relation to a customer, an email — means this is not a till order, so the
     * POS screen is not built AT ALL rather than built broken (empty ⇒ the
     * caller skips POS generation).
     *
     * @param  array<string, mixed>  $orderDef
     * @param  array<string, mixed>|null  $status
     * @return array<string, mixed>
     */
    private function posNewOrderValues(array $orderDef, ?array $status): array
    {
        $values = [];
        if ($status !== null && ! empty($status['options'])) {
            $values[(string) $status['slug']] = $status['options'][0]['value'];
        }

        foreach ($orderDef['fields'] ?? [] as $field) {
            if (empty($field['required']) || array_key_exists((string) $field['slug'], $values)) {
                continue;
            }
            if (in_array($field['type'] ?? '', self::DERIVED_TYPES, true) || ($field['default'] ?? null) !== null) {
                continue;
            }

            $seed = $this->posSeedValue($field);
            if ($seed === null) {
                return [];
            }
            $values[(string) $field['slug']] = $seed;
        }

        return $values;
    }

    /**
     * A value for one required field of a new order, or null when the till has
     * no honest answer for it.
     *
     * @param  array<string, mixed>  $field
     */
    private function posSeedValue(array $field): string|int|bool|null
    {
        return match ($field['type'] ?? '') {
            // An order opened at a till has no name yet, and a folio is exactly
            // the thing a timestamp can be.
            'string' => '{{now(\'YmdHis\')}}',
            'number', 'currency' => 0,
            'boolean' => false,
            'date' => '{{today()}}',
            'datetime' => '{{now()}}',
            'single_select' => is_array($field['options'] ?? null) && $field['options'] !== []
                ? (string) $field['options'][0]['value']
                : null,
            default => null,
        };
    }

    /**
     * Derive a line's total wherever one is derivable: an object carrying a
     * QUANTITY and a UNIT PRICE knows its own amount.
     *
     * Deliberately not gated on commerce, unlike the POS screen above.
     * Multiplication is arithmetic, not a claim about selling: a refacción used
     * on a work order has a quantity and a unit cost, and the total of those is
     * a fact whether or not anyone is running a till. The POS gate exists to
     * stop a project budget spawning a point-of-sale PAGE — a different
     * question from whether a number can be computed.
     *
     * Skips a field that is already computed, so the POS pass (which runs
     * first) keeps its own richer treatment untouched.
     *
     * @param  array<int, array{def: array<string, mixed>, pageFields: array<int, array<string, mixed>>}>  $built
     */
    /**
     * Point a parent's sum rollup at the line total instead of at the per-unit
     * price it was summing.
     *
     * Relations are wired in pass 2 and the line total is derived after, so by
     * the time one exists the rollup has already chosen its target — and with
     * no amount on the line to choose, it took the only money field there was.
     * The parent's own name for the figure is left alone: it is still that
     * object's total, and it was already named for the children it adds up.
     *
     * @param  list<array{def: array<string, mixed>, pageFields: array<int, array<string, mixed>>}>  $built
     */
    private function repointLineRollups(array &$built, string $fromFieldId, string $toFieldId): void
    {
        foreach ($built as $i => $entry) {
            foreach ($entry['def']['fields'] ?? [] as $fi => $field) {
                if (($field['type'] ?? null) === 'rollup'
                    && ($field['aggregator'] ?? null) === 'sum'
                    && ($field['target_field_id'] ?? null) === $fromFieldId) {
                    $built[$i]['def']['fields'][$fi]['target_field_id'] = $toFieldId;
                }
            }
        }
    }

    /**
     * The invariant, enforced after every pass that could have satisfied it:
     * nothing ships a total of per-unit prices.
     *
     * The passes above give a line the total it implies and point the parent at
     * that — but only when they can recognise the quantity. A honey harvest
     * measured in kilos beside a price per kilo defeated that, and no
     * enumeration of units is ever going to be complete. So whatever is still
     * summing a price-per-something when the derivation had its chance is
     * removed: the sum of what one of each thing costs is not a smaller truth
     * than the total, it is not a figure at all, and a wrong number on a screen
     * is worse than a missing one.
     *
     * @param  list<array{def: array<string, mixed>, pageFields: array<int, array<string, mixed>>}>  $built
     */
    private function dropUnitPriceTotals(array &$built, string $lang): void
    {
        $lex = SemanticLexicon::for($lang);

        $unitPriceIds = [];
        foreach ($built as $entry) {
            foreach ($entry['def']['fields'] ?? [] as $field) {
                if ($lex->matches('unit_price', (string) ($field['name'] ?? ''), (string) ($field['slug'] ?? ''))) {
                    $unitPriceIds[] = (string) $field['id'];
                }
            }
        }
        if ($unitPriceIds === []) {
            return;
        }

        foreach ($built as $i => $entry) {
            $doomed = [];
            foreach ($entry['def']['fields'] ?? [] as $fi => $field) {
                if (($field['type'] ?? null) === 'rollup'
                    && ($field['aggregator'] ?? null) === 'sum'
                    && in_array((string) ($field['target_field_id'] ?? ''), $unitPriceIds, true)) {
                    $doomed[] = (string) $field['id'];
                    unset($built[$i]['def']['fields'][$fi]);
                }
            }
            if ($doomed === []) {
                continue;
            }
            $built[$i]['def']['fields'] = array_values($built[$i]['def']['fields']);
            $built[$i]['pageFields'] = array_values(array_filter(
                $built[$i]['pageFields'],
                fn (array $idx): bool => ! in_array((string) ($idx['id'] ?? ''), $doomed, true),
            ));
        }
    }

    private function synthesizeLineTotals(array &$built, string $currency, string $lang): void
    {
        $lex = SemanticLexicon::for($lang);
        $words = fn (array $f): array => [(string) ($f['name'] ?? ''), (string) ($f['slug'] ?? '')];

        foreach ($built as $i => $entry) {
            $fields = $entry['def']['fields'] ?? [];

            $quantity = null;
            $unitPrice = null;
            $amount = null;
            foreach ($fields as $field) {
                $type = $field['type'] ?? null;
                if ($quantity === null && $type === 'number' && $lex->matches('quantity', ...$words($field))) {
                    $quantity = $field;
                }
                if ($unitPrice === null && $type === 'currency' && $lex->matches('unit_price', ...$words($field))) {
                    $unitPrice = $field;
                }
                if ($amount === null
                    && $type === 'currency'
                    && $lex->matches('amount', ...$words($field))
                    && ! $lex->matches('unit_price', ...$words($field))) {
                    $amount = $field;
                }
            }

            if ($quantity === null || $unitPrice === null) {
                continue;
            }

            $expression = '{{'.$quantity['slug'].' * '.$unitPrice['slug'].'}}';

            // Quantity and a unit cost, and nowhere for their product to go: a
            // work order's parts had exactly this shape, so the order's total
            // ended up summing the per-part COST instead — adding unit prices
            // across rows, which is not a figure at all. Give the line the
            // total it implies.
            if ($amount === null) {
                $amountSlug = $this->uniqueSlug('subtotal', array_column($fields, 'slug'), 'subtotal');
                [$amountField, $amountIdx] = $this->buildField([
                    'name' => $lex->label('subtotal'),
                    'slug' => $amountSlug,
                    'type' => 'formula',
                    'options' => null,
                    'config' => ['expression' => $expression, 'return_type' => 'number', 'currency_code' => $currency],
                ], $currency);
                $built[$i]['def']['fields'][] = $amountField;
                $built[$i]['pageFields'][] = $amountIdx;
                $this->repointLineRollups($built, $unitPrice['id'], $amountField['id']);

                continue;
            }

            foreach ($fields as $fi => $field) {
                if (($field['id'] ?? null) !== $amount['id']) {
                    continue;
                }

                $built[$i]['def']['fields'][$fi] = [
                    'id' => $field['id'],
                    'slug' => $field['slug'],
                    'name' => $field['name'],
                    'type' => 'formula',
                    'readonly' => true,
                    'expression' => $expression,
                    'return_type' => 'number',
                    'currency_code' => $field['currency_code'] ?? $currency,
                ];
                break;
            }

            // The page index has to learn the new type too, or the create form
            // keeps offering an input for a value the save now computes.
            foreach ($built[$i]['pageFields'] as $pi => $idx) {
                if (($idx['id'] ?? null) === $amount['id']) {
                    $built[$i]['pageFields'][$pi]['type'] = 'formula';
                    break;
                }
            }
        }
    }

    /**
     * Whether an order←line→product triad is genuinely a POINT OF SALE, not just
     * three objects with the right shape. The decisive signal is the "price": a
     * sale price sells (precio, price, tarifa), a budget/cost/salary does not — a
     * project budget is a currency field too, and treating it as a price is what
     * spawned bogus "Punto de venta" pages on a product-development app. A triad
     * also needs some sale intent in its names (an order/line/product/catalog).
     *
     * @param  array<string, mixed>  $orderDef
     * @param  array<string, mixed>  $lineDef
     * @param  array<string, mixed>  $productDef
     * @param  array<string, mixed>  $priceField
     */
    private function isCommerceTriad(array $orderDef, array $lineDef, array $productDef, array $priceField, string $lang): bool
    {
        $lex = SemanticLexicon::for($lang);
        $words = fn (array $e): array => [(string) ($e['name'] ?? ''), (string) ($e['slug'] ?? '')];

        // A budget/cost/salary is a currency field, but it is not a sale price.
        if ($lex->matches('not_price', ...$words($priceField))) {
            return false;
        }

        // Sale intent: a price-named price field, or an order/line/product/catalog
        // name somewhere in the triad.
        return $lex->matches('price', ...$words($priceField))
            || $lex->matches('commerce', ...$words($orderDef))
            || $lex->matches('commerce', ...$words($lineDef))
            || $lex->matches('commerce', ...$words($productDef));
    }

    /**
     * Build the POS screen: a "new order" button (opens an order and routes to
     * ?order=<id>), then a split view — a product card_grid whose on_click adds a
     * line to the open order, beside a live cart (the order record + its lines
     * with −/+ and remove, totalled).
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, string>  $usedSlugs
     * @return array<string, mixed>
     */
    private function buildPosPage(array $spec, string $lang, array $usedSlugs): array
    {
        $labels = $this->posLabels($lang);
        $posSlug = $this->uniqueSlug('pos', $usedSlugs, 'pos');
        $path = '/'.$posSlug;

        $cardGrid = [
            'id' => $this->id('blk'),
            'type' => 'card_grid',
            'data_source' => ['object_id' => $spec['product_object_id']],
            'columns' => 3,
            'title_field_id' => $spec['product_title_field_id'],
            'meta_fields' => [['field_id' => $spec['product_price_field_id']]],
            'action_icon' => 'plus',
            'on_click' => [
                ['type' => 'create_record', 'object_id' => $spec['line_object_id'], 'values' => [
                    $spec['line_order_rel_slug'] => '{{params.order}}',
                    $spec['line_product_rel_slug'] => '{{row.id}}',
                    $spec['qty_slug'] => 1,
                ]],
                ['type' => 'refresh'],
            ],
        ];
        if ($spec['product_image_field_id'] !== null) {
            $cardGrid['image_field_id'] = $spec['product_image_field_id'];
        }

        $detailFields = [];
        if ($spec['order_status_field_id'] !== null) {
            $detailFields[] = ['field_id' => $spec['order_status_field_id']];
        }
        $detailFields[] = ['field_id' => $spec['order_total_field_id']];

        $qtyExpr = fn (string $op): string => '{{row.data.'.$spec['qty_slug'].' '.$op.' 1}}';
        $stepAction = fn (string $glyph, string $op): array => [
            'id' => $this->id('col'),
            'type' => 'action',
            'label' => $glyph,
            'variant' => 'secondary',
            'on_click' => [
                ['type' => 'update_record', 'object_id' => $spec['line_object_id'], 'record_id_expression' => '{{row.id}}', 'values' => [$spec['qty_slug'] => $qtyExpr($op)]],
                ['type' => 'refresh'],
            ],
        ];

        $cart = [
            ['id' => $this->id('blk'), 'type' => 'heading', 'level' => 3, 'content' => $labels['order']],
            // Shown only until an order is open — a guide instead of an empty card.
            [
                'id' => $this->id('blk'),
                'type' => 'alert',
                'variant' => 'info',
                'title' => $labels['order'],
                'body' => $labels['empty'],
                'visibility' => ['expression' => '{{not params.order}}'],
            ],
            [
                'id' => $this->id('blk'),
                'type' => 'record_detail',
                'object_id' => $spec['order_object_id'],
                'record_id_expression' => '{{params.order}}',
                'fields' => $detailFields,
                'visibility' => ['expression' => '{{params.order}}'],
            ],
            [
                'id' => $this->id('blk'),
                'type' => 'table',
                'empty_state_message' => $labels['empty'],
                'visibility' => ['expression' => '{{params.order}}'],
                'data_source' => [
                    'object_id' => $spec['line_object_id'],
                    'filter' => ['op' => 'eq', 'field_id' => $spec['line_order_rel_field_id'], 'value_expression' => '{{params.order}}'],
                ],
                'columns' => [
                    ['id' => $this->id('col'), 'field_id' => $spec['line_product_rel_field_id']],
                    ['id' => $this->id('col'), 'field_id' => $spec['qty_field_id']],
                    ['id' => $this->id('col'), 'field_id' => $spec['subtotal_field_id']],
                    $stepAction('−', '-'),
                    $stepAction('+', '+'),
                    [
                        'id' => $this->id('col'),
                        'type' => 'action',
                        'label' => '×',
                        'variant' => 'danger',
                        'on_click' => [
                            ['type' => 'delete_record', 'object_id' => $spec['line_object_id'], 'record_id_expression' => '{{row.id}}'],
                            ['type' => 'refresh'],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'id' => $this->id('pag'),
            'slug' => $posSlug,
            'name' => $labels['pos'],
            'path' => $path,
            'blocks' => [
                ['id' => $this->id('blk'), 'type' => 'heading', 'content' => $labels['pos']],
                [
                    'id' => $this->id('blk'),
                    'type' => 'button',
                    'label' => $labels['new_order'],
                    'variant' => 'primary',
                    'icon' => 'plus',
                    'on_click' => [
                        ['type' => 'create_record', 'object_id' => $spec['order_object_id'], 'values' => $spec['new_order_values']],
                        ['type' => 'navigate', 'to' => $path.'?order={{record.id}}'],
                    ],
                ],
                [
                    'id' => $this->id('blk'),
                    'type' => 'split_view',
                    'left_fraction' => 7,
                    'left_blocks' => [$cardGrid],
                    'right_blocks' => $cart,
                ],
            ],
        ];
    }

    /**
     * First field of any of the given base types in a built object def.
     *
     * @param  array<string, mixed>  $def
     * @param  list<string>  $types
     * @return array<string, mixed>|null
     */
    private function firstDefFieldOfType(array $def, array $types): ?array
    {
        foreach ($def['fields'] as $field) {
            if (in_array($field['type'] ?? '', $types, true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * A number field that reads like a quantity (by slug/name), or null.
     *
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>|null
     */
    private function quantityFieldOf(array $def, string $lang): ?array
    {
        $lex = SemanticLexicon::for($lang);
        foreach ($def['fields'] as $field) {
            if (($field['type'] ?? '') === 'number'
                && $lex->matches('quantity', (string) ($field['slug'] ?? ''), (string) ($field['name'] ?? ''))) {
                return $field;
            }
        }

        return null;
    }

    /**
     * A currency field on the line that reads like a line amount/subtotal (to be
     * reused as the computed subtotal) — NOT a unit price, which the lookup owns.
     *
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>|null
     */
    /**
     * A per-unit price already on the line, or null.
     *
     * The POS pass adds one as a lookup off the product, and used to add it
     * unconditionally: a line the model had already given a "Precio unitario"
     * came out with two fields of that exact name, rendering as two identical
     * column headers with nothing to tell them apart.
     *
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>|null
     */
    private function unitPriceFieldOf(array $def, string $lang): ?array
    {
        $lex = SemanticLexicon::for($lang);
        foreach ($def['fields'] as $field) {
            if (! in_array($field['type'] ?? '', ['currency', 'number'], true)) {
                continue;
            }
            if ($lex->matches('unit_price', (string) ($field['slug'] ?? ''), (string) ($field['name'] ?? ''))) {
                return $field;
            }
        }

        return null;
    }

    private function subtotalFieldOf(array $def, string $lang): ?array
    {
        $lex = SemanticLexicon::for($lang);
        foreach ($def['fields'] as $field) {
            if (($field['type'] ?? '') !== 'currency') {
                continue;
            }
            $slug = (string) ($field['slug'] ?? '');
            $name = (string) ($field['name'] ?? '');
            if ($lex->matches('amount', $slug, $name) && ! $lex->matches('unit_price', $slug, $name)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * A string field that looks like it holds an image/photo URL, or null.
     *
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>|null
     */
    private function imageFieldOf(array $def, string $lang): ?array
    {
        $lex = SemanticLexicon::for($lang);
        foreach ($def['fields'] as $field) {
            if (($field['type'] ?? '') === 'string'
                && $lex->matches('image', (string) ($field['slug'] ?? ''), (string) ($field['name'] ?? ''))) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The select that says where a record IS in its life, or null when the
     * object has none.
     *
     * A schema cannot tell a status from a taxonomy: `estado` and `tipo_contrato`
     * are both a select with options. Taking the FIRST one — which is what every
     * generator here used to do — put a default on "tipo de servicio" (stamping
     * a classification nobody chose onto every record) and left "estado" empty,
     * so new work orders landed outside the very board grouped by it. It also
     * turned a customer's contract type into a kanban, promising that customers
     * move left to right.
     *
     * Two signals, in order: the field is NAMED like a state, or its options
     * READ like a lifecycle. A name that means a category disqualifies it
     * outright, since "tipo"/"categoria" is the one thing a status never is.
     *
     * @param  array<int, array<string, mixed>>  $fieldIndex
     * @return array<string, mixed>|null
     */
    private function statusField(array $fieldIndex, string $lang = 'en'): ?array
    {
        $lex = SemanticLexicon::for($lang);
        $selects = array_values(array_filter(
            $fieldIndex,
            fn (array $f): bool => ($f['type'] ?? null) === 'single_select',
        ));

        $named = null;
        $lifecycle = null;

        foreach ($selects as $field) {
            $words = [(string) ($field['name'] ?? ''), (string) ($field['slug'] ?? '')];
            if ($lex->matches('not_status', ...$words)) {
                continue;
            }
            if ($named === null && $lex->matches('status', ...$words)) {
                $named = $field;
            }
            if ($lifecycle === null && $lex->matches('lifecycle', ...($field['option_labels'] ?? []))) {
                $lifecycle = $field;
            }
        }

        return $named ?? $lifecycle;
    }

    /**
     * A date you look FORWARD to — a due date, an appointment, an expiry — as
     * opposed to one that records when something already happened.
     *
     * Only the first kind belongs on a calendar. A month grid of signup dates
     * was the first thing on a customer list, above the customers.
     *
     * @param  array<string, mixed>  $field
     */
    private function isScheduleDate(array $field, string $lang = 'en'): bool
    {
        return SemanticLexicon::for($lang)->matches(
            'schedule',
            (string) ($field['name'] ?? ''),
            (string) ($field['slug'] ?? ''),
        );
    }

    /**
     * A start/end pair that describes a real span, or null.
     *
     * Two dates on an object are not a timeline: "installed on" and "last
     * serviced" are both dates and the distance between them is not work being
     * done, so a Gantt of them says nothing.
     *
     * @param  array<int, array<string, mixed>>  $fieldIndex
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function spanFields(array $fieldIndex, string $lang = 'en'): ?array
    {
        $lex = SemanticLexicon::for($lang);
        $words = fn (array $f): array => [(string) ($f['name'] ?? ''), (string) ($f['slug'] ?? '')];

        $dates = array_values(array_filter(
            $fieldIndex,
            fn (array $f): bool => in_array($f['type'] ?? '', ['date', 'datetime'], true),
        ));

        $start = null;
        $end = null;
        foreach ($dates as $field) {
            if ($start === null && $lex->matches('span_start', ...$words($field))) {
                $start = $field;

                continue;
            }
            if ($end === null && $lex->matches('span_end', ...$words($field))) {
                $end = $field;
            }
        }

        return $start !== null && $end !== null ? [$start, $end] : null;
    }

    /**
     * @param  array<int, array{id: string, slug: string, type: string}>  $fieldIndex
     * @return array{id: string, slug: string, type: string}|null
     */
    private function firstFieldOfType(array $fieldIndex, string $type): ?array
    {
        foreach ($fieldIndex as $field) {
            if ($field['type'] === $type) {
                return $field;
            }
        }

        return null;
    }

    /** Data columns a list table shows before the reader has to ask for more. */
    private const VISIBLE_COLUMN_CAP = 6;

    /**
     * Types that cost a list far more width than they repay: a paragraph, a
     * formatted document, an attachment. They still get a column, ranked last,
     * so the picker can offer them.
     */
    private const BULKY_COLUMN_TYPES = ['long_text', 'rich_text', 'file'];

    /**
     * Every field ranked by how much it earns a place in a list table, most
     * deserving first.
     *
     * Every field used to get a visible column, so an object with 17 of them
     * produced a table 17 columns wide: each cell wrapped to three lines and
     * the row that was meant to be scannable read as a paragraph. A list
     * answers two questions — which record is this, and where does it stand.
     * The rest is what the detail page is for, and what the column picker
     * offers on demand; nothing is dropped, only demoted.
     *
     * @param  array<int, array<string, mixed>>  $fieldIndex
     * @return list<string>
     */
    private function rankedColumnFields(array $fieldIndex, string $lang = 'en'): array
    {
        $picked = [];
        $take = function (?array $field) use (&$picked): void {
            if ($field !== null && ! in_array($field['id'], $picked, true)) {
                $picked[] = $field['id'];
            }
        };

        // Which record is this, and where does it stand.
        $take($this->titleField($fieldIndex));
        $take($this->statusField($fieldIndex, $lang));
        // What it hangs off, the date you would chase it by, and how much.
        $take($this->firstFieldOfType($fieldIndex, 'relation'));
        foreach ($fieldIndex as $field) {
            if (in_array($field['type'] ?? null, ['date', 'datetime'], true)
                && $this->isScheduleDate($field, $lang)) {
                $take($field);
                break;
            }
        }
        $take($this->firstFieldOfType($fieldIndex, 'currency'));

        // Then the author's own order, leaving the bulky types for the end.
        foreach ($fieldIndex as $field) {
            if (! in_array($field['type'] ?? null, self::BULKY_COLUMN_TYPES, true)) {
                $take($field);
            }
        }
        foreach ($fieldIndex as $field) {
            $take($field);
        }

        return $picked;
    }

    /**
     * The field to label a record by: the first string field, else the first
     * field of any kind.
     *
     * @param  array<int, array{id: string, slug: string, type: string}>  $fieldIndex
     * @return array{id: string, slug: string, type: string}|null
     */
    private function titleField(array $fieldIndex): ?array
    {
        return $this->firstFieldOfType($fieldIndex, 'string') ?? ($fieldIndex[0] ?? null);
    }

    /**
     * A schema-valid prefixed id: `<prefix>_<lowercased ULID>`. Public so the
     * manifest editor can mint ids when injecting blocks into existing pages.
     */
    public function id(string $prefix): string
    {
        return $prefix.'_'.strtolower((string) Str::ulid());
    }

    /**
     * Map a manifest locale (e.g. "es-MX", "en") to the chrome language the
     * scaffold should generate its built-in UI strings in. Public so the
     * manifest editor (add_object) can localise the same way.
     */
    public static function langForLocale(?string $locale): string
    {
        return SemanticLexicon::resolve($locale);
    }

    private function labelNew(string $lang, string $singular): string
    {
        return SemanticLexicon::for($lang)->label('new', singular: $singular);
    }

    private function labelSubmit(string $lang): string
    {
        return SemanticLexicon::for($lang)->label('submit');
    }

    private function toastSaved(string $lang, string $singular): string
    {
        return SemanticLexicon::for($lang)->label('saved', singular: $singular);
    }

    private function labelCreatedColumn(string $lang): string
    {
        return SemanticLexicon::for($lang)->label('created_col');
    }

    private function labelEdit(string $lang): string
    {
        return SemanticLexicon::for($lang)->label('edit');
    }

    private function labelEditTitle(string $lang, string $singular): string
    {
        return SemanticLexicon::for($lang)->label('edit_title', singular: $singular);
    }

    private function labelSave(string $lang): string
    {
        return SemanticLexicon::for($lang)->label('save');
    }

    private function labelByStatus(string $lang, string $name): string
    {
        return SemanticLexicon::for($lang)->label('by_status', name: $name);
    }

    /** "{Object} by {Field}" — a breakdown that says what it breaks down by. */
    private function labelByField(string $lang, string $name, string $field): string
    {
        return $field === ''
            ? $this->labelByStatus($lang, $name)
            : SemanticLexicon::for($lang)->label('by_field', name: $name, field: mb_strtolower($field));
    }

    /** "N.º de Sedes" — the count of children, distinct from the relation. */
    private function labelCountOf(string $lang, string $name): string
    {
        return SemanticLexicon::for($lang)->label('count_of', name: $name);
    }

    private function labelTotal(string $lang, string $name): string
    {
        return SemanticLexicon::for($lang)->label('total', name: $name);
    }

    /**
     * Name a money aggregate after whichever of the two says something.
     *
     * "Total Inmuebles $308,500.00" beside "Inmuebles 6" spends the same words
     * on a count and on a sum of rents, leaving the reader to work out which
     * figure answers which question. The measure settles it — but only when the
     * measure has a name of its own: "Renta Mensual" does, "Importe" and
     * "Subtotal" are just the words for money, and "Costo Total" prefixed reads
     * "Total Costo Total". Those carry nothing the object doesn't, so the object
     * keeps the naming there.
     */
    /**
     * The label for a money TOTAL.
     *
     * Three cases, and the middle one is why this is not just moneyMeasureName
     * wrapped in labelTotal. A field already called "Costo total de reparación"
     * cannot take the prefix — "Total Costo total de reparación" — but falling
     * back to the object gave "Total Incidencias", a sum of repair costs
     * wearing the same words as the count of incidents beside it. The field
     * already says it is a total; let it say so.
     */
    private function labelMoneyTotal(string $lang, string $fieldName, string $objectName): string
    {
        $fieldName = trim($fieldName);
        $lex = SemanticLexicon::for($lang);
        $totalWord = preg_quote($lex->label('total_word'), '/');

        $bare = ! str_contains($fieldName, ' ') && $lex->matches('amount', $fieldName);
        $saysTotal = preg_match('/\b'.$totalWord.'\b/iu', $fieldName) === 1;

        if ($fieldName === '' || $bare) {
            return $this->labelTotal($lang, $objectName);
        }

        return $saysTotal ? $fieldName : $this->labelTotal($lang, $fieldName);
    }

    private function moneyMeasureName(string $lang, string $fieldName, string $objectName): string
    {
        $fieldName = trim($fieldName);
        $lex = SemanticLexicon::for($lang);

        // Bare "Importe", "Monto", "Subtotal": the word for money and nothing
        // else, so it names no measure. Qualified, it does — "Monto Pagado"
        // says which money, and deserves the label.
        $bareAmountWord = ! str_contains($fieldName, ' ')
            && $lex->matches('amount', $fieldName);

        // "Costo Total" prefixed reads "Total Costo Total". The word has to be
        // whole: "Subtotal" is not this case, it is the one above.
        $totalWord = preg_quote($lex->label('total_word'), '/');
        $stutters = preg_match('/\b'.$totalWord.'\b/iu', $fieldName) === 1;

        return $fieldName === '' || $bareAmountWord || $stutters
            ? $objectName
            : $fieldName;
    }

    private function labelAverage(string $lang, string $name): string
    {
        return SemanticLexicon::for($lang)->label('average', name: $name);
    }

    private function labelOverTime(string $lang, string $name): string
    {
        return SemanticLexicon::for($lang)->label('over_time', name: $name);
    }

    private function labelValueByStatus(string $lang, string $name): string
    {
        return SemanticLexicon::for($lang)->label('value_by_status', name: $name);
    }

    /**
     * @return array{pos: string, new_order: string, order: string, qty: string, unit_price: string, subtotal: string, total: string, empty: string}
     */
    private function posLabels(string $lang): array
    {
        $lex = SemanticLexicon::for($lang);

        return [
            'pos' => $lex->label('pos'),
            'new_order' => $lex->label('new_order'),
            'order' => $lex->label('order'),
            'qty' => $lex->label('qty'),
            'unit_price' => $lex->label('unit_price'),
            'photo' => $lex->label('photo'),
            'subtotal' => $lex->label('subtotal'),
            'total' => $lex->label('total_word'),
            'empty' => $lex->label('cart_empty'),
        ];
    }
}
