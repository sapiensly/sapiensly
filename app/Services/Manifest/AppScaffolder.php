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

    /** Field types the model may request; anything else is coerced to `string`. */
    private const ALLOWED_TYPES = [
        'string', 'long_text', 'number', 'currency', 'boolean',
        'date', 'datetime', 'single_select', 'multi_select', 'rating',
    ];

    private const SYSTEM = <<<'SYS'
        You design simple internal business apps as a set of data objects (like database tables) with fields.
        Given a description, respond with ONLY a single minified JSON object — no markdown, no code fences, no commentary — using exactly this schema:
        {"objects":[{"name":string,"slug":string,"fields":[{"name":string,"slug":string,"type":"string"|"long_text"|"number"|"currency"|"boolean"|"date"|"datetime"|"single_select"|"multi_select"|"rating","options":[{"value":string,"label":string}]|null}]}]}
        Rules:
        - objects: the main entities the app tracks (e.g. for a content engine: Ideas, Drafts, Published). At most 6. Each needs a human `name` and a snake_case `slug`.
        - fields: the columns of each object. At most 12 per object. Each needs a `name`, a snake_case `slug`, and a `type`. Give every object a short text title/name field FIRST.
        - type: use "string" for short text, "long_text" for paragraphs, "number" for quantities, "currency" for money, "boolean" for yes/no, "date"/"datetime" for dates, "single_select"/"multi_select" for a fixed set of choices, "rating" for 1-5 stars. There is NO email or url type — use "string".
        - options: REQUIRED and non-empty ONLY for single_select / multi_select (each option a short `value` slug + a human `label`); use null for every other type. Use status/stage fields (single_select) where the workflow implies them.
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
     * @return array{objects: array<int, array{name: string, slug: string, fields: array<int, array<string, mixed>>}>}
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

        return ['objects' => $objects];
    }

    /**
     * @param  array<int, mixed>  $rawFields
     * @return array<int, array{name: string, slug: string, type: string, options: array<int, array{value: string, label: string}>|null}>
     */
    private function normalizeFields(array $rawFields): array
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
        $slug = trim((string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower((string) $raw)), '_');
        if ($slug === '' || ! preg_match('/^[a-z]/', $slug)) {
            $slug = $slug === '' ? $fallback : 'f_'.$slug;
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
     * Deterministically assemble objects + a CRUD page each into the base manifest.
     *
     * @param  array<string, mixed>  $base
     * @param  array{objects: array<int, array{name: string, slug: string, fields: array<int, array<string, mixed>>}>}  $spec
     * @return array<string, mixed>
     */
    public function assemble(array $base, array $spec): array
    {
        $currency = (string) ($base['settings']['default_currency'] ?? 'MXN');

        $objects = [];
        $pages = [];

        foreach ($spec['objects'] as $object) {
            [$objectDef, $fieldIndex] = $this->buildObject($object, $currency);
            $objects[] = $objectDef;
            $pages[] = $this->buildPage($object, $objectDef['id'], $fieldIndex);
        }

        $base['objects'] = $objects;
        $base['pages'] = $pages;

        return $base;
    }

    /**
     * @param  array{name: string, slug: string, fields: array<int, array<string, mixed>>}  $object
     * @return array{0: array<string, mixed>, 1: array<int, array{id: string, slug: string}>}
     */
    private function buildObject(array $object, string $currency): array
    {
        $fields = [];
        $fieldIndex = [];

        foreach ($object['fields'] as $field) {
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

            $fields[] = $definition;
            $fieldIndex[] = ['id' => $fieldId, 'slug' => $field['slug']];
        }

        return [[
            'id' => $this->id('obj'),
            'slug' => $object['slug'],
            'name' => $object['name'],
            'fields' => $fields,
        ], $fieldIndex];
    }

    /**
     * One list page per object: heading + "new" button + create modal/form + table.
     *
     * @param  array{name: string, slug: string}  $object
     * @param  array<int, array{id: string, slug: string}>  $fieldIndex
     * @return array<string, mixed>
     */
    private function buildPage(array $object, string $objectId, array $fieldIndex): array
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

        return [
            'id' => $this->id('pag'),
            'slug' => $object['slug'],
            'name' => $object['name'],
            'path' => '/'.$object['slug'],
            'blocks' => [
                ['id' => $this->id('blk'), 'type' => 'heading', 'content' => $object['name']],
                $modal,
                $button,
                $table,
            ],
        ];
    }

    /**
     * A schema-valid prefixed id: `<prefix>_<lowercased ULID>`.
     */
    private function id(string $prefix): string
    {
        return $prefix.'_'.strtolower((string) Str::ulid());
    }
}
