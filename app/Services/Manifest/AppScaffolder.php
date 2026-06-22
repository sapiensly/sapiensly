<?php

namespace App\Services\Manifest;

use App\Ai\ChatAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
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

    private const SYSTEM = <<<'SYS'
        You design simple internal business apps as a set of data objects (like database tables) with fields, and the links between them.
        Given a description, respond with ONLY a single minified JSON object — no markdown, no code fences, no commentary — using exactly this schema:
        {"objects":[{"name":string,"slug":string,"fields":[{"name":string,"slug":string,"type":"string"|"long_text"|"number"|"currency"|"boolean"|"date"|"datetime"|"single_select"|"multi_select"|"rating","options":[{"value":string,"label":string}]|null}]}],"links":[{"from":string,"to":string,"name":string}]|null}
        Rules:
        - objects: the main entities the app tracks (e.g. for a content engine: Ideas, Drafts, Published). At most 6. Each needs a human `name` and a snake_case `slug`.
        - fields: the columns of each object. At most 12 per object. Each needs a `name`, a snake_case `slug`, and a `type`. Give every object a short text title/name field FIRST.
        - type: use "string" for short text, "long_text" for paragraphs, "number" for quantities, "currency" for money, "boolean" for yes/no, "date"/"datetime" for dates, "single_select"/"multi_select" for a fixed set of choices, "rating" for 1-5 stars. There is NO email or url type — use "string".
        - options: REQUIRED and non-empty ONLY for single_select / multi_select (each option a short `value` slug + a human `label`); use null for every other type. Use status/stage fields (single_select) where the workflow implies them.
        - links: "belongs-to" relationships between objects. Each link means "a <from> belongs to one <to>" (e.g. {"from":"drafts","to":"ideas","name":"idea"} = each Draft belongs to one Idea). `from`/`to` are object slugs; `name` is the human label of the link on the <from> side. Use null when there are no relationships. At most 8. Do NOT model a link as a plain field — use this array.
        - Write names/labels in the SAME language as the description.
        SYS;

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
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
            $response = $agent->prompt(Str::limit($description, 2000), provider: $provider, model: $model);

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
     * @param  array<string, mixed>|null  $decoded
     * @return array{objects: array<int, array{name: string, slug: string, fields: array<int, array<string, mixed>>}>, links: array<int, array{from: string, to: string, name: ?string}>}
     */
    private function normalizeSpec(?array $decoded): array
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
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  $takenSlugs
     * @return array{name: string, slug: string, type: string, options: array<int, array{value: string, label: string}>|null}|null
     */
    public function normalizeField(array $field, array $takenSlugs = []): ?array
    {
        $normalized = $this->normalizeFields([$field]);
        if ($normalized === []) {
            return null;
        }

        $only = $normalized[0];
        $only['slug'] = $this->uniqueSlug($only['slug'], $takenSlugs, 'field');

        return $only;
    }

    /**
     * @param  array<int, mixed>  $rawFields
     * @return array<int, array{name: string, slug: string, type: string, options: array<int, array{value: string, label: string}>|null}>
     */
    public function normalizeFields(array $rawFields): array
    {
        $fields = [];
        $usedSlugs = [];
        foreach (array_slice($rawFields, 0, self::MAX_FIELDS_PER_OBJECT) as $i => $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? '')) ?: ('Field '.($i + 1));
            $slug = $this->uniqueSlug($field['slug'] ?? $name, $usedSlugs, 'field_'.($i + 1));

            $type = (string) ($field['type'] ?? 'string');
            $type = in_array($type, self::ALLOWED_TYPES, true) ? $type : 'string';

            $options = null;
            if (in_array($type, ['single_select', 'multi_select'], true)) {
                $options = $this->normalizeOptions($field['options'] ?? null);
                if ($options === []) {
                    // A select with no options is invalid — degrade to free text.
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
        $slug = trim((string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($raw)), '_');
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
        // the `from` object + its one_to_many inverse on the `to` object).
        foreach ($spec['links'] ?? [] as $link) {
            $fromIndex = $indexBySlug[$link['from']] ?? null;
            $toIndex = $indexBySlug[$link['to']] ?? null;
            if ($fromIndex === null || $toIndex === null || $fromIndex === $toIndex) {
                continue;
            }
            $pair = $this->buildRelation($built[$fromIndex]['def'], $built[$toIndex]['def'], $link['name']);
            $built[$fromIndex]['def']['fields'][] = $pair['child_field'];
            $built[$fromIndex]['pageFields'][] = $pair['child_index'];
            $built[$toIndex]['def']['fields'][] = $pair['parent_field'];
        }

        // Pass 3: pages (now that relation fields exist on the objects).
        $objects = [];
        $pages = [];
        $forDashboard = [];
        foreach ($built as $entry) {
            $objects[] = $entry['def'];
            $pages[] = $this->buildPage(['name' => $entry['def']['name'], 'slug' => $entry['def']['slug']], $entry['def']['id'], $entry['pageFields']);
            $forDashboard[] = ['name' => $entry['def']['name'], 'id' => $entry['def']['id'], 'fieldIndex' => $entry['pageFields']];
        }

        // A dashboard landing page summarising every object goes first so it is
        // the app's home. Only worth it once there is something to summarise.
        if ($forDashboard !== []) {
            $dashboardSlug = $this->uniqueSlug('dashboard', array_column($pages, 'slug'), 'dashboard');
            array_unshift($pages, $this->buildDashboard($base['name'] ?? 'Dashboard', $dashboardSlug, $forDashboard));
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
        $definition = [
            'id' => $fieldId,
            'slug' => $field['slug'],
            'name' => $field['name'],
            'type' => $field['type'],
        ];

        if ($field['type'] === 'currency') {
            $definition['currency_code'] = $currency;
        }

        if (in_array($field['type'], ['single_select', 'multi_select'], true)) {
            $definition['options'] = array_map(fn (array $opt): array => [
                'id' => $this->id('opt'),
                'value' => $opt['value'],
                'label' => $opt['label'],
            ], $field['options'] ?? []);
        }

        return [$definition, ['id' => $fieldId, 'slug' => $field['slug'], 'type' => $field['type']]];
    }

    /**
     * Build a bidirectional belongs-to relation pair: a many_to_one field on the
     * `from` object pointing at `to`, plus its one_to_many inverse on `to`. Both
     * carry inverse_field_id so lookups/rollups work later. Returns the two field
     * definitions and the from-side index entry (so the page can show it).
     *
     * @param  array{id: string, name: string, slug: string, fields: array<int, array<string, mixed>>}  $from  the "many" side (a $from belongs to one $to)
     * @param  array{id: string, name: string, slug: string, fields: array<int, array<string, mixed>>}  $to  the "one" side
     * @return array{child_field: array<string, mixed>, parent_field: array<string, mixed>, child_index: array{id: string, slug: string, type: string}}
     */
    public function buildRelation(array $from, array $to, ?string $name = null): array
    {
        $childFieldId = $this->id('fld');
        $parentFieldId = $this->id('fld');

        $relName = ($name !== null && trim($name) !== '') ? trim($name) : (string) Str::singular($to['name']);
        $relSlug = $this->uniqueSlug($relName, array_column($from['fields'], 'slug'), 'related');

        $inverseSlug = $this->uniqueSlug($from['slug'], array_column($to['fields'], 'slug'), 'related');

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

        return [
            'child_field' => $childField,
            'parent_field' => $parentField,
            'child_index' => ['id' => $childFieldId, 'slug' => $relSlug, 'type' => 'relation'],
        ];
    }

    /**
     * One list page per object: heading + "new" button + create modal/form + table.
     *
     * @param  array{name: string, slug: string}  $object
     * @param  array<int, array{id: string, slug: string}>  $fieldIndex
     * @return array<string, mixed>
     */
    public function buildPage(array $object, string $objectId, array $fieldIndex): array
    {
        $modalId = $this->id('blk');

        $formFields = array_map(fn (array $f): array => ['field_id' => $f['id']], $fieldIndex);
        $createValues = [];
        foreach ($fieldIndex as $f) {
            $createValues[$f['slug']] = '{{form.'.$f['slug'].'}}';
        }

        $modal = [
            'id' => $modalId,
            'type' => 'modal',
            'title' => 'New '.Str::singular($object['name']),
            'blocks' => [[
                'id' => $this->id('blk'),
                'type' => 'form',
                'object_id' => $objectId,
                'mode' => 'create',
                'fields' => $formFields,
                'submit_label' => 'Create',
                'on_submit' => [
                    ['type' => 'create_record', 'object_id' => $objectId, 'values' => $createValues],
                    ['type' => 'close_modal'],
                    ['type' => 'show_toast', 'level' => 'success', 'message' => Str::singular($object['name']).' created'],
                    ['type' => 'refresh'],
                ],
            ]],
        ];

        $button = [
            'id' => $this->id('blk'),
            'type' => 'button',
            'label' => 'New '.Str::singular($object['name']),
            'variant' => 'primary',
            'on_click' => [['type' => 'open_modal', 'modal_block_id' => $modalId]],
        ];

        $columns = array_map(fn (array $f): array => [
            'id' => $this->id('col'),
            'field_id' => $f['id'],
        ], $fieldIndex);
        $columns[] = ['id' => $this->id('col'), 'field_id' => 'sys_created_at', 'label_override' => 'Created'];

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
        ];
        if ($meta !== []) {
            $kanban['card_meta_fields'] = $meta;
        }

        return $kanban;
    }

    /**
     * A dashboard landing page: a KPI per object (record count) plus a
     * distribution chart for each object that has a status field (capped).
     *
     * @param  array<int, array{name: string, id: string, fieldIndex: array<int, array{id: string, slug: string, type: string}>}>  $objects
     * @return array<string, mixed>
     */
    private function buildDashboard(string $appName, string $slug, array $objects): array
    {
        $items = array_map(fn (array $o): array => [
            'id' => $this->id('itm'),
            'label' => $o['name'],
            'query' => ['object_id' => $o['id']],
            'aggregation' => 'count',
        ], $objects);

        $blocks = [
            ['id' => $this->id('blk'), 'type' => 'heading', 'content' => $appName],
            ['id' => $this->id('blk'), 'type' => 'metric_grid', 'items' => $items],
        ];

        $charts = 0;
        foreach ($objects as $object) {
            if ($charts >= 3) {
                break;
            }
            $status = $this->firstFieldOfType($object['fieldIndex'], 'single_select');
            if ($status === null) {
                continue;
            }
            $blocks[] = [
                'id' => $this->id('blk'),
                'type' => 'chart',
                'label' => $object['name'].' by status',
                'chart_type' => 'bar',
                'data_source' => ['object_id' => $object['id']],
                'aggregation' => 'count',
                'group_by_field_id' => $status['id'],
            ];
            $charts++;
        }

        return [
            'id' => $this->id('pag'),
            'slug' => $slug,
            'name' => 'Dashboard',
            'path' => '/',
            'blocks' => $blocks,
        ];
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
}
