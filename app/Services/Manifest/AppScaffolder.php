<?php

namespace App\Services\Manifest;

use App\Ai\ChatAgent;
use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Express\SemanticProfile;
use App\Support\Icons\IconCatalog;
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

    private const MAX_FIELDS_PER_OBJECT = 12;

    private const MAX_OPTIONS = 8;

    private const MAX_LINKS = 8;

    /** Field types the model may request; anything else is coerced to `string`. */
    private const ALLOWED_TYPES = [
        'string', 'long_text', 'number', 'currency', 'boolean',
        'date', 'datetime', 'single_select', 'multi_select', 'rating',
    ];

    /**
     * The full field type set the typed add_field tool accepts (beyond the scaffold
     * subset above): the advanced types that previously forced raw RFC 6902 patches.
     */
    private const TYPED_FIELD_TYPES = [
        'string', 'long_text', 'number', 'currency', 'boolean', 'date', 'datetime',
        'single_select', 'multi_select', 'rating', 'slider', 'date_range', 'file',
        'rich_text', 'relation', 'formula', 'lookup', 'rollup',
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
     * @var array<string, list<string>>
     */
    private const FIELD_CONFIG_PROPS = [
        'string' => ['min_length', 'max_length', 'pattern', 'default'],
        'long_text' => ['max_length', 'default'],
        'number' => ['min', 'max', 'precision', 'format', 'default'],
        'currency' => ['currency_code', 'min', 'max', 'default'],
        'boolean' => ['default'],
        'date' => ['default'],
        'datetime' => ['default'],
        'rating' => ['max', 'default', 'icon'],
        'slider' => ['min', 'max', 'step', 'default', 'format', 'currency_code'],
        'date_range' => ['include_time', 'default'],
        'file' => ['max_size_mb', 'mime_types'],
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
        {"objects":[{"name":string,"slug":string,"fields":[{"name":string,"slug":string,"type":"string"|"long_text"|"number"|"currency"|"boolean"|"date"|"datetime"|"single_select"|"multi_select"|"rating","options":[{"value":string,"label":string}]|null}]}],"links":[{"from":string,"to":string,"name":string}]|null}
        Rules:
        - objects: the main entities the app tracks (e.g. for a content engine: Ideas, Drafts, Published). At most 6. Each needs a human `name` and a snake_case `slug`.
        - fields: the columns of each object. At most 12 per object. Each needs a `name`, a snake_case `slug`, and a `type`. Give every object a short text title/name field FIRST.
        - STAY GROUNDED: only include fields the description actually implies or that are obviously essential to the entity. Do NOT pad objects with invented or generic extra fields — fewer, relevant fields beat a long speculative list.
        - type: use "string" for short text, "long_text" for paragraphs, "number" for quantities/counts, "currency" for money/prices/amounts, "boolean" for yes/no, "date"/"datetime" for dates, "single_select"/"multi_select" for a fixed set of choices, "rating" for 1-5 stars. There is NO email or url type — use "string". There is NO id/foreign-key type — never add a field to hold another object's id or name; express that as a link.
        - options: REQUIRED and non-empty ONLY for single_select / multi_select (each option a short `value` slug + a human `label`); use null for every other type. Add a status/stage single_select whenever the entity moves through states (it becomes a board) — e.g. order: pending/preparing/served/paid.
        - links: "belongs-to" relationships between objects. Each link means "a <from> belongs to one <to>" (e.g. {"from":"drafts","to":"ideas","name":"idea"} = each Draft belongs to one Idea). `from`/`to` are object slugs; `name` is the human label of the link on the <from> side. Use null when there are no relationships. At most 8.
        - NEVER restate a relationship as a field: do not add a string/number field that holds a related record's name or id (e.g. on a line item do NOT add a "product"/"category" text field) — model it with a link instead. The relation, its picker, child counts and totals are generated for you.
        - Model line-item / amount structures as a parent with a child linked to it (e.g. an order/ticket with its line items, each line a currency field): the child's amount then rolls up to a total on the parent automatically. Do not add a manual "total" field to the parent — it is derived.
        - Write names/labels in the SAME language as the description.
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
     * @return array<string, mixed>
     */
    public function scaffold(array $baseManifest, string $description, ?User $user = null): array
    {
        $spec = $this->extractSpec($description, $user);

        return $this->assemble($baseManifest, $spec);
    }

    /**
     * @return array{objects: array<int, array{name: string, slug: string, fields: array<int, array<string, mixed>>}>}
     */
    private function extractSpec(string $description, ?User $user): array
    {
        $model = $this->aiDefaults->model('flows');
        $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;

        try {
            $agent = new ChatAgent(instructions: self::SYSTEM, messages: [], tools: []);
            $response = $agent->prompt(Str::limit($description, 2000), provider: $provider, model: $model, timeout: (int) config('ai.request_timeout', 180));

            return $this->normalizeSpec($this->decodeJson((string) ($response->text ?? '')));
        } catch (\Throwable $e) {
            Log::warning('App scaffold: model call failed', ['error' => $e->getMessage()]);

            return ['objects' => []];
        }
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
     * @return array{objects: array<int, array{name: string, slug: string, fields: array<int, array<string, mixed>>}>, links: array<int, array{from: string, to: string, name: ?string}>}
     */
    public function normalizeSpec(?array $decoded): array
    {
        $rawObjects = is_array($decoded['objects'] ?? null) ? $decoded['objects'] : [];

        $objects = [];
        $usedObjectSlugs = [];
        foreach (array_slice($rawObjects, 0, self::MAX_OBJECTS) as $i => $object) {
            if (! is_array($object)) {
                continue;
            }

            $name = trim((string) ($object['name'] ?? '')) ?: ('Object '.($i + 1));
            $slug = $this->uniqueSlug($object['slug'] ?? $name, $usedObjectSlugs, 'object_'.($i + 1));
            $usedObjectSlugs[] = $slug;

            $fields = $this->normalizeFields(is_array($object['fields'] ?? null) ? $object['fields'] : []);
            if ($fields === []) {
                // Never emit a field-less object — the table/form would be empty.
                $fields[] = ['name' => 'Name', 'slug' => 'name', 'type' => 'string', 'options' => null];
            }

            $objects[] = ['name' => $name, 'slug' => $slug, 'fields' => $fields];
        }

        return [
            'objects' => $objects,
            'links' => $this->normalizeLinks($decoded['links'] ?? null, $usedObjectSlugs),
        ];
    }

    /**
     * Keep only links whose endpoints are real, distinct objects.
     *
     * @param  array<int, string>  $objectSlugs
     * @return array<int, array{from: string, to: string, name: ?string}>
     */
    private function normalizeLinks(mixed $rawLinks, array $objectSlugs): array
    {
        if (! is_array($rawLinks)) {
            return [];
        }

        $links = [];
        $seen = [];
        foreach (array_slice($rawLinks, 0, self::MAX_LINKS) as $link) {
            if (! is_array($link)) {
                continue;
            }
            $from = $this->toSlug((string) ($link['from'] ?? ''));
            $to = $this->toSlug((string) ($link['to'] ?? ''));
            $key = $from.'->'.$to;
            if ($from === $to || ! in_array($from, $objectSlugs, true) || ! in_array($to, $objectSlugs, true) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $name = trim((string) ($link['name'] ?? ''));
            $links[] = ['from' => $from, 'to' => $to, 'name' => $name !== '' ? $name : null];
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
            $options = $this->normalizeOptions($field['options'] ?? null);
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
                $options = $this->normalizeOptions($field['options'] ?? null);
                if ($options === []) {
                    // A select with no options is invalid — degrade to free text.
                    $coercions[] = "field \"{$name}\": {$type} needs a non-empty options array — used plain text instead.";
                    $type = 'string';
                    $options = null;
                }
            }

            $usedSlugs[] = $slug;
            $fields[] = ['name' => $name, 'slug' => $slug, 'type' => $type, 'options' => $options];
        }

        return $fields;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function normalizeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
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

        // Pass 1: build every object so all ids exist before relations wire them.
        $built = [];
        $indexBySlug = [];
        foreach ($spec['objects'] as $object) {
            [$objectDef, $fieldIndex] = $this->buildObject($object, $currency);
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
        $posSpecs = $this->detectAndBuildPosEconomics($built, $relationsByChild, $currency, $lang);

        // Pass 3: a list page per object (now that relation fields exist).
        $objects = [];
        $objectPages = [];
        $forDashboard = [];
        foreach ($built as $i => $entry) {
            $objects[] = $entry['def'];
            $objectPages[$i] = $this->buildPage(['name' => $entry['def']['name'], 'slug' => $entry['def']['slug']], $entry['def']['id'], $entry['pageFields'], $lang);
            $forDashboard[] = ['name' => $entry['def']['name'], 'id' => $entry['def']['id'], 'fieldIndex' => $entry['pageFields']];
        }

        // Pass 4: a master-detail page for every parent that has children — the
        // parent record (record_detail) plus, per child relationship, an inline
        // "add child" form and a related_list of its children. The parent's list
        // table gets an "open" row action that navigates to it.
        $detailPages = [];
        $usedSlugs = array_column($objectPages, 'slug');
        foreach ($childrenByParent as $parentIndex => $rels) {
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
        foreach ($posSpecs as $spec) {
            $posPages[] = $this->buildPosPage($spec, $lang, $usedSlugs);
            $usedSlugs[] = end($posPages)['slug'];
        }

        $pages = [...$posPages, ...array_values($objectPages), ...$detailPages];

        // A dashboard landing page summarising every object goes first so it is
        // the app's home. Only worth it once there is something to summarise.
        if ($forDashboard !== []) {
            $dashboardSlug = $this->uniqueSlug('dashboard', array_column($pages, 'slug'), 'dashboard');
            array_unshift($pages, $this->buildDashboard($base['name'] ?? 'Dashboard', $dashboardSlug, $forDashboard, $lang));
        }

        $base['objects'] = $objects;
        $base['pages'] = $pages;

        return $base;
    }

    /**
     * @param  array{name: string, slug: string, fields: array<int, array<string, mixed>>}  $object
     * @return array{0: array<string, mixed>, 1: array<int, array{id: string, slug: string}>}
     */
    public function buildObject(array $object, string $currency): array
    {
        $fields = [];
        $fieldIndex = [];

        foreach ($object['fields'] as $field) {
            [$definition, $indexEntry] = $this->buildField($field, $currency);
            $fields[] = $definition;
            $fieldIndex[] = $indexEntry;
        }

        return [[
            'id' => $this->id('obj'),
            'slug' => $object['slug'],
            'name' => $object['name'],
            'fields' => $fields,
        ], $fieldIndex];
    }

    /**
     * Build one field definition + its index entry from a normalized field spec.
     *
     * @param  array{name: string, slug: string, type: string, options?: array<int, array{value: string, label: string}>|null}  $field
     * @return array{0: array<string, mixed>, 1: array{id: string, slug: string, type: string}}
     */
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

        return [$definition, ['id' => $fieldId, 'slug' => $field['slug'], 'type' => $type]];
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

        $relName = ($name !== null && trim($name) !== '') ? trim($name) : (string) Str::singular($to['name']);
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
            'name' => $from['name'],
            'type' => 'rollup',
            'via_relation_field_id' => $parentFieldId,
            'aggregator' => 'count',
            'readonly' => true,
        ];

        // If the child carries a money field, also sum it onto the parent so a
        // total is derived (e.g. an order total from its line amounts).
        $sumField = null;
        $sumIndex = null;
        $amount = $this->firstCurrencyField($from['fields']);
        if ($amount !== null) {
            $parentTaken[] = $rollupSlug;
            $sumFieldId = $this->id('fld');
            $sumSlug = $this->uniqueSlug($from['slug'].'_'.$amount['slug'].'_total', $parentTaken, 'total');
            $sumField = [
                'id' => $sumFieldId,
                'slug' => $sumSlug,
                'name' => $this->labelTotal($lang, $amount['name']),
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
     * One list page per object: heading + "new" button + create modal/form + table.
     *
     * @param  array{name: string, slug: string}  $object
     * @param  array<int, array{id: string, slug: string}>  $fieldIndex
     * @return array<string, mixed>
     */
    public function buildPage(array $object, string $objectId, array $fieldIndex, string $lang = 'en'): array
    {
        $modalId = $this->id('blk');
        $singular = (string) Str::singular($object['name']);

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

        $columns = array_map(fn (array $f): array => [
            'id' => $this->id('col'),
            'field_id' => $f['id'],
        ], $fieldIndex);
        $columns[] = ['id' => $this->id('col'), 'field_id' => 'sys_created_at', 'label_override' => $this->labelCreatedColumn($lang)];

        $table = [
            'id' => $this->id('blk'),
            'type' => 'table',
            'data_source' => [
                'object_id' => $objectId,
                'sort' => [['field_id' => 'sys_created_at', 'direction' => 'desc']],
            ],
            'columns' => $columns,
        ];

        $blocks = [
            ['id' => $this->id('blk'), 'type' => 'heading', 'content' => $object['name']],
            $modal,
            $button,
        ];

        // Two date fields (a start + an end) turn the page into a schedule: a
        // Gantt of each record's span. This is what makes a plan/project object
        // render as a work-plan timeline.
        $gantt = $this->buildGantt($objectId, $fieldIndex);
        if ($gantt !== null) {
            $blocks[] = $gantt;
        }

        // A status (single_select) field turns the page into a board: a kanban
        // grouped by that status, with the title field on each card.
        $kanban = $this->buildKanban($objectId, $fieldIndex);
        if ($kanban !== null) {
            $blocks[] = $kanban;
        }

        $blocks[] = $table;

        return [
            'id' => $this->id('pag'),
            'slug' => $object['slug'],
            'name' => $object['name'],
            'path' => '/'.$object['slug'],
            'blocks' => $blocks,
        ];
    }

    /**
     * A kanban board grouped by the object's first status (single_select) field,
     * or null when the object has no such field. Cards show the title field plus
     * up to two other non-status fields.
     *
     * @param  array<int, array{id: string, slug: string, type: string}>  $fieldIndex
     * @return array<string, mixed>|null
     */
    private function buildKanban(string $objectId, array $fieldIndex): ?array
    {
        $status = $this->firstFieldOfType($fieldIndex, 'single_select');
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
    private function buildGantt(string $objectId, array $fieldIndex): ?array
    {
        $dates = array_values(array_filter(
            $fieldIndex,
            fn (array $f): bool => in_array($f['type'] ?? '', ['date', 'datetime'], true),
        ));
        $title = $this->titleField($fieldIndex);
        if (count($dates) < 2 || $title === null) {
            return null;
        }

        $gantt = [
            'id' => $this->id('blk'),
            'type' => 'gantt',
            'data_source' => ['object_id' => $objectId],
            'start_field_id' => $dates[0]['id'],
            'end_field_id' => $dates[1]['id'],
            'title_field_id' => $title['id'],
        ];

        // Colour each bar by the object's status, when it has one.
        $status = $this->firstFieldOfType($fieldIndex, 'single_select');
        if ($status !== null) {
            $gantt['color_field_id'] = $status['id'];
        }

        return $gantt;
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
    private function buildDashboard(string $appName, string $slug, array $objects, string $lang = 'en'): array
    {
        // One record-count KPI per object …
        $items = array_map(fn (array $o): array => [
            'id' => $this->id('itm'),
            'label' => $o['name'],
            'query' => ['object_id' => $o['id']],
            'aggregation' => 'count',
        ], $objects);

        // … plus money KPIs (total + average of the first currency field) for
        // objects that track an amount, so the dashboard leads with the figures
        // that matter, not just counts.
        foreach ($objects as $object) {
            $currencyField = $this->firstFieldOfType($object['fieldIndex'], 'currency');
            if ($currencyField === null) {
                continue;
            }
            $items[] = [
                'id' => $this->id('itm'),
                'label' => $this->labelTotal($lang, $object['name']),
                'query' => ['object_id' => $object['id']],
                'aggregation' => 'sum',
                'field_id' => $currencyField['id'],
                'format' => 'currency',
            ];
            $items[] = [
                'id' => $this->id('itm'),
                'label' => $this->labelAverage($lang, $object['name']),
                'query' => ['object_id' => $object['id']],
                'aggregation' => 'avg',
                'field_id' => $currencyField['id'],
                'format' => 'currency',
            ];
        }

        $blocks = [
            ['id' => $this->id('blk'), 'type' => 'heading', 'content' => $appName],
            ['id' => $this->id('blk'), 'type' => 'metric_grid', 'items' => $items],
        ];

        // Per object, the visualisations its shape supports. Status donuts come
        // first (so the first `chart` block stays the status breakdown), then a
        // growth trend, then a value-by-status bar for money objects.
        $charts = 0;
        $trends = [];
        $valueBars = [];
        foreach ($objects as $object) {
            if ($charts >= self::DASHBOARD_CHART_CAP) {
                break;
            }
            $status = $this->firstFieldOfType($object['fieldIndex'], 'single_select');
            $currency = $this->firstFieldOfType($object['fieldIndex'], 'currency');

            if ($status !== null) {
                $blocks[] = [
                    'id' => $this->id('blk'),
                    'type' => 'chart',
                    'label' => $this->labelByStatus($lang, $object['name']),
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

            // A growth trend works for every object via the always-present
            // sys_created_at system field (sparkline truncates it to day).
            $trends[] = [
                'id' => $this->id('blk'),
                'type' => 'sparkline',
                'label' => $this->labelOverTime($lang, $object['name']),
                'data_source' => ['object_id' => $object['id'], 'limit' => self::DASHBOARD_ROW_LIMIT],
                'x_field_id' => 'sys_created_at',
                'aggregation' => 'count',
            ];
        }

        // Append trends and value bars after the breakdowns, each capped.
        foreach (array_slice($trends, 0, self::DASHBOARD_CHART_CAP) as $trend) {
            $blocks[] = $trend;
        }
        foreach (array_slice($valueBars, 0, self::DASHBOARD_CHART_CAP) as $bar) {
            $blocks[] = $bar;
        }

        return [
            'id' => $this->id('pag'),
            'slug' => $slug,
            'name' => 'Dashboard',
            'path' => '/',
            'blocks' => $blocks,
        ];
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
            $cfField = collect($object['fields'] ?? [])->firstWhere('id', $cf['field_id'] ?? null);
            $cfOptions = collect($cf['options'] ?? [])
                ->map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '')
                ->filter()->unique()->take(12)->values();
            if ($cfField !== null && in_array($cfField['type'] ?? '', ['string', 'single_select'], true) && $cfOptions->count() >= 2) {
                $param = (string) preg_replace('/[^a-z0-9_]/', '', Str::snake((string) ($cfField['slug'] ?? 'categoria')));
                $categoryFilter = [
                    'field_id' => $cfField['id'],
                    'label' => (string) ($cf['label'] ?? $cfField['name'] ?? $cfField['slug']),
                    'param' => $param !== '' && $param !== 'range' ? $param : 'categoria',
                    'options' => $cfOptions->all(),
                ];
            }
        }

        // Merge the range filter into a block's own filter (empty preset ⇒ the
        // condition resolves empty and is skipped server-side ⇒ "Todo").
        $rangeWired = false;
        $withRange = function (?array $own, array $obj) use ($withDateFilter, $rangeBySlug, $primarySlug, &$rangeWired, $categoryFilter, &$categoryWired, $object): ?array {
            $conditions = [];
            $range = $rangeBySlug[(string) ($obj['slug'] ?? $primarySlug)] ?? null;
            if ($withDateFilter && $range !== null) {
                $rangeWired = true;
                $conditions[] = $range;
            }
            if ($categoryFilter !== null && ($obj['id'] ?? null) === ($object['id'] ?? null)) {
                $categoryWired = true;
                $conditions[] = [
                    'op' => 'eq',
                    'field_id' => $categoryFilter['field_id'],
                    'value_expression' => '{{params.'.$categoryFilter['param'].'}}',
                ];
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

            $items[] = array_filter([
                'id' => $this->id('itm'),
                'label' => (string) ($kpi['label'] ?? 'KPI'),
                'query' => $query,
                'aggregation' => $agg,
                'field_id' => $needsField ? ($kpi['field_id'] ?? null) : null,
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
                && preg_match('/label|bucket|period|semana|week/i', $groupSlug) === 1) {
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
                    && ($chart['group_by_field_id'] ?? null) === $categoryFilter['field_id']
                    && ($chartObject['id'] ?? null) === ($object['id'] ?? null))
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
                'eyebrow' => $lang === 'es' ? 'Reporte' : 'Report',
                'eyebrow_icon' => 'bar-chart',
                'align' => 'left',
                'min_height' => 120,
            ];
            // Float the headline KPI into the hero as a live figure — the
            // executive-summary number. Prefer a RATE (a percentage like OTD%, or
            // an averaged score like NPS) over a raw volume: "96.7% OTD" is the
            // number leadership reads, not "1.5M productos". Fall back to the
            // first KPI when there's no rate.
            $lead = collect($items)->first(fn (array $k): bool => ($k['format'] ?? null) === 'percentage')
                ?? collect($items)->first(fn (array $k): bool => ($k['aggregation'] ?? null) === 'avg')
                ?? ($items[0] ?? null);
            if (is_array($lead) && isset($lead['query'], $lead['aggregation'])) {
                $hero['stat'] = array_filter([
                    'label' => (string) ($lead['label'] ?? ''),
                    'query' => $lead['query'],
                    'aggregation' => $lead['aggregation'],
                    'field_id' => $lead['field_id'] ?? null,
                    'format' => $lead['format'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
            }
            if (is_array($palette['ramp'] ?? null) && isset($palette['ramp']['900'], $palette['ramp']['600'])) {
                $hero['style'] = ['gradient' => ['from' => $palette['ramp']['900'], 'to' => $palette['ramp']['600'], 'direction' => 'to-br']];
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
        $sectionLabels = [
            'trend' => (string) ($sections['trend'] ?? ($lang === 'es' ? 'Tendencia' : 'Trend')),
            'breakdown' => (string) ($sections['breakdown'] ?? ($lang === 'es' ? 'Desglose' : 'Breakdown')),
            'insights' => (string) ($sections['insights'] ?? ($lang === 'es' ? 'Lecturas clave' : 'Key readings')),
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
                    $blocks[] = ['id' => $this->id('blk'), 'type' => 'heading', 'level' => 3, 'content' => $lang === 'es' ? 'Detalle' : 'Detail'];
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
        $es = $lang !== 'en';
        $nameOf = function (?string $fieldId) use ($object): ?string {
            if ($fieldId === null || $fieldId === '') {
                return null;
            }
            $field = collect($object['fields'] ?? [])->firstWhere('id', $fieldId);

            return $field !== null ? Str::lower((string) ($field['name'] ?? $field['slug'])) : null;
        };

        $measure = $nameOf($chart['y_field_id'] ?? null) ?? ($es ? 'registros' : 'records');
        $dim = $nameOf($chart['group_by_field_id'] ?? null);
        $x = $nameOf($chart['x_field_id'] ?? null);
        $series = $nameOf($chart['series_field_id'] ?? null);
        $bucketWord = match ($chart['bucket'] ?? null) {
            'day' => $es ? 'diaria' : 'daily',
            'week' => $es ? 'semanal' : 'weekly',
            'month' => $es ? 'mensual' : 'monthly',
            'quarter' => $es ? 'trimestral' : 'quarterly',
            'year' => $es ? 'anual' : 'yearly',
            default => null,
        };

        if (! $es) {
            return trim((string) preg_replace('/\s{2,}/', ' ', match ($chartType) {
                'pareto' => "{$measure} by {$dim}, largest first, with the cumulative-% line — where the total concentrates.",
                'pie', 'donut' => "Share of {$measure} by {$dim} over the total.",
                'treemap' => "Relative weight of {$measure} by {$dim}; area is share.",
                'hbar' => "Ranking of {$dim} by {$measure}, largest first.",
                'line', 'area' => 'Evolution of '.$measure.($bucketWord !== null ? " ({$bucketWord})" : '').' over the selected window.',
                'scatter' => "Relationship between {$x} and {$measure}; each dot is one record.",
                'box' => "Distribution of {$measure} per {$dim}: Q1–Q3 box, median line, outlier dots.",
                'sankey' => "Flow from {$dim} to {$series}; ribbon width is volume.",
                'radar' => "Profile across {$dim} on radial axes.",
                default => $dim !== null
                    ? "Comparison of {$measure} across {$dim}."
                    : 'Evolution of '.$measure.($bucketWord !== null ? " ({$bucketWord})" : '').' over the selected window.',
            }));
        }

        return trim((string) preg_replace('/\s{2,}/', ' ', match ($chartType) {
            'pareto' => "{$measure} por {$dim}, de mayor a menor, con la línea de % acumulado — dónde se concentra el total.",
            'pie', 'donut' => "Participación de {$measure} por {$dim} sobre el total.",
            'treemap' => "Peso relativo de {$measure} por {$dim}; el área es la proporción.",
            'hbar' => "Ranking de {$dim} por {$measure}, de mayor a menor.",
            'line', 'area' => 'Evolución '.($bucketWord ?? '').' de '.$measure.' en la ventana seleccionada.',
            'scatter' => "Relación entre {$x} y {$measure}; cada punto es un registro.",
            'box' => "Distribución de {$measure} por {$dim}: caja Q1–Q3, línea en la mediana, puntos atípicos.",
            'sankey' => "Flujo de {$dim} hacia {$series}; el grosor de la cinta es el volumen.",
            'radar' => "Perfil comparado por {$dim} en ejes radiales.",
            default => $dim !== null
                ? "Comparación de {$measure} entre {$dim}."
                : 'Evolución '.($bucketWord ?? '').' de '.$measure.' en la ventana seleccionada.',
        }));
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
        $es = $lang !== 'en';

        return match ($aggregation) {
            'count' => $es ? 'conteo en la ventana' : 'count in window',
            'sum' => $es ? 'acumulado en la ventana' : 'total in window',
            'avg' => $es ? 'promedio del periodo' : 'period average',
            'median' => $es ? 'mediana del periodo' : 'period median',
            'p90' => $es ? 'percentil 90 del periodo' : 'period p90',
            'p95' => $es ? 'percentil 95 del periodo' : 'period p95',
            'min' => $es ? 'mínimo del periodo' : 'period minimum',
            'max' => $es ? 'máximo del periodo' : 'period maximum',
            'distinct_count' => $es ? 'valores distintos' : 'distinct values',
            default => '',
        };
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
        $singular = (string) Str::singular($parentDef['name']);

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

        foreach ($children as $child) {
            $childDef = $child['def'];
            $childFieldId = $child['childFieldId'];
            $childSingular = (string) Str::singular($childDef['name']);

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
            $blocks[] = [
                'id' => $this->id('blk'),
                'type' => 'related_list',
                'object_id' => $childDef['id'],
                'via_relation_field_id' => $childFieldId,
                'parent_id_expression' => '{{params.id}}',
                'columns' => array_map(fn (array $f): array => ['field_id' => $f['id']], array_values(array_filter(
                    $child['pageFields'],
                    fn (array $f): bool => $f['id'] !== $childFieldId,
                ))),
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
        foreach ($page['blocks'] as &$block) {
            if (($block['type'] ?? null) === 'table') {
                $block['columns'][] = [
                    'id' => $this->id('col'),
                    'type' => 'action',
                    'label' => $lang === 'es' ? 'Abrir' : 'Open',
                    'icon' => 'arrow-right',
                    'variant' => 'ghost',
                    'on_click' => [['type' => 'navigate', 'to' => '/'.$detailSlug.'?id={{row.id}}']],
                ];
                break;
            }
        }
        unset($block);
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
    private function detectAndBuildPosEconomics(array &$built, array $relationsByChild, string $currency, string $lang): array
    {
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

            $lineDef = &$built[$lineIndex]['def'];
            $taken = array_column($lineDef['fields'], 'slug');

            // Quantity: reuse a number field that reads like a quantity, else add one.
            $qty = $this->quantityFieldOf($lineDef);
            if ($qty === null) {
                $slug = $this->uniqueSlug('cantidad', $taken, 'cantidad');
                $taken[] = $slug;
                [$qty, $qtyIdx] = $this->buildField(['name' => $labels['qty'], 'slug' => $slug, 'type' => 'number', 'options' => null, 'config' => ['default' => 1, 'min' => 1]], $currency);
                $lineDef['fields'][] = $qty;
                $built[$lineIndex]['pageFields'][] = $qtyIdx;
            }

            // Unit price: a lookup of the product price across the line→product rel.
            $priceSlug = $this->uniqueSlug('precio_unitario', $taken, 'precio');
            $taken[] = $priceSlug;
            [$precio, $precioIdx] = $this->buildField(['name' => $labels['unit_price'], 'slug' => $priceSlug, 'type' => 'lookup', 'options' => null, 'config' => ['via_relation_field_id' => $productRel['childFieldId'], 'target_field_id' => $productPrice['id']]], $currency);
            $lineDef['fields'][] = $precio;
            $built[$lineIndex]['pageFields'][] = $precioIdx;

            // Subtotal: qty × unit price. Reuse an amount field the model already
            // put on the line (convert it to the formula in place, keeping its
            // id/slug/name) instead of adding a duplicate; else synthesise one.
            $expression = '{{'.$qty['slug'].' * '.$priceSlug.'}}';
            $existingAmount = $this->subtotalFieldOf($lineDef);
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

            $productDef = $built[$productRel['targetIndex']]['def'];
            $specs[] = [
                'order_object_id' => $orderDefRef['id'],
                'line_object_id' => $lineDef['id'],
                'product_object_id' => $productDef['id'],
                'product_title_field_id' => $productTitle['id'],
                'product_price_field_id' => $productPrice['id'],
                'product_image_field_id' => $this->imageFieldOf($productDef)['id'] ?? null,
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
        }

        return $specs;
    }

    /**
     * Seed values for the "new order" button so create_record gets a non-empty
     * object: the status' first option when there is a status, else the first
     * scalar field (blank). Empty ⇒ caller skips POS generation.
     *
     * @param  array<string, mixed>  $orderDef
     * @param  array<string, mixed>|null  $status
     * @return array<string, mixed>
     */
    private function posNewOrderValues(array $orderDef, ?array $status): array
    {
        if ($status !== null && ! empty($status['options'])) {
            return [$status['slug'] => $status['options'][0]['value']];
        }
        $seed = $this->firstDefFieldOfType($orderDef, ['string', 'number', 'currency', 'boolean']);
        if ($seed === null) {
            return [];
        }

        return [$seed['slug'] => ($seed['type'] === 'boolean' ? false : '')];
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
    private function quantityFieldOf(array $def): ?array
    {
        foreach ($def['fields'] as $field) {
            if (($field['type'] ?? '') === 'number'
                && preg_match('/cant|qty|quantity|unidad|piezas|count/i', ($field['slug'] ?? '').' '.($field['name'] ?? '')) === 1) {
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
    private function subtotalFieldOf(array $def): ?array
    {
        foreach ($def['fields'] as $field) {
            if (($field['type'] ?? '') !== 'currency') {
                continue;
            }
            $haystack = ($field['slug'] ?? '').' '.($field['name'] ?? '');
            if (preg_match('/subtotal|sub_total|importe|monto|amount|total/i', $haystack) === 1
                && preg_match('/unit|unitario|precio|price/i', $haystack) === 0) {
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
    private function imageFieldOf(array $def): ?array
    {
        foreach ($def['fields'] as $field) {
            if (($field['type'] ?? '') === 'string'
                && preg_match('/image|imagen|photo|foto|picture|thumbnail|avatar|url/i', ($field['slug'] ?? '').' '.($field['name'] ?? '')) === 1) {
                return $field;
            }
        }

        return null;
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
        return str_starts_with(strtolower((string) $locale), 'es') ? 'es' : 'en';
    }

    private function labelNew(string $lang, string $singular): string
    {
        return $lang === 'es' ? "Agregar {$singular}" : "New {$singular}";
    }

    private function labelSubmit(string $lang): string
    {
        return $lang === 'es' ? 'Guardar' : 'Create';
    }

    private function toastSaved(string $lang, string $singular): string
    {
        return $lang === 'es' ? 'Guardado' : "{$singular} created";
    }

    private function labelCreatedColumn(string $lang): string
    {
        return $lang === 'es' ? 'Creado' : 'Created';
    }

    private function labelByStatus(string $lang, string $name): string
    {
        return $lang === 'es' ? "{$name} por estado" : "{$name} by status";
    }

    private function labelTotal(string $lang, string $name): string
    {
        return $lang === 'es' ? "Total {$name}" : "{$name} total";
    }

    private function labelAverage(string $lang, string $name): string
    {
        return $lang === 'es' ? "Promedio {$name}" : "{$name} average";
    }

    private function labelOverTime(string $lang, string $name): string
    {
        return $lang === 'es' ? "{$name} en el tiempo" : "{$name} over time";
    }

    private function labelValueByStatus(string $lang, string $name): string
    {
        return $lang === 'es' ? "Valor de {$name} por estado" : "{$name} value by status";
    }

    /**
     * @return array{pos: string, new_order: string, order: string, qty: string, unit_price: string, subtotal: string, total: string, empty: string}
     */
    private function posLabels(string $lang): array
    {
        return $lang === 'es'
            ? ['pos' => 'Punto de venta', 'new_order' => 'Nueva orden', 'order' => 'Pedido', 'qty' => 'Cantidad', 'unit_price' => 'Precio unitario', 'subtotal' => 'Subtotal', 'total' => 'Total', 'empty' => 'Abre una orden y agrega productos.']
            : ['pos' => 'Point of Sale', 'new_order' => 'New order', 'order' => 'Order', 'qty' => 'Quantity', 'unit_price' => 'Unit price', 'subtotal' => 'Subtotal', 'total' => 'Total', 'empty' => 'Open an order and add products.'];
    }
}
