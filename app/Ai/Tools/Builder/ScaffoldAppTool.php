<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * One-shot app skeleton generator. The model describes the app at a HIGH level
 * (a list of objects, each with simple fields) and this tool deterministically
 * assembles a fully VALID manifest fragment — generating ids, unique slugs, the
 * exact required props, an option {value,label} per choice, and a list page with
 * a table per object — then submits it through the same propose_change gate
 * (validation + checkpoint + auto-apply).
 *
 * This exists because the cold start ("create an app for X") is where the model
 * thrashes hardest: it hand-crafts every object/field/page op and learns the
 * exact shapes (option value-not-slug, page.path, heading.content, id pattern,
 * required props) only by hitting validation errors — many round-trips, often
 * a timeout. Moving shape-correctness into code collapses that to one step.
 * After scaffolding, the model adds the richer pieces (forms, modals, actions,
 * workflows, relations, derived fields) with normal propose_change calls.
 */
class ScaffoldAppTool implements Tool
{
    /**
     * Field types this tool can emit without extra references. Each only needs
     * the base id/slug/name/type (plus options for selects, currency_code for
     * currency, both auto-filled). Relation/formula/lookup/rollup and the media
     * types are intentionally excluded — they need refs/expressions and must be
     * added with propose_change after scaffolding.
     */
    private const SIMPLE_TYPES = ['string', 'long_text', 'number', 'boolean', 'date', 'datetime', 'currency', 'single_select', 'multi_select'];

    /**
     * Forgiving aliases for the type names a model is likely to reach for.
     */
    private const TYPE_ALIASES = [
        'text' => 'string',
        'email' => 'string',
        'phone' => 'string',
        'url' => 'string',
        'textarea' => 'long_text',
        'longtext' => 'long_text',
        'int' => 'number',
        'integer' => 'number',
        'float' => 'number',
        'decimal' => 'number',
        'money' => 'currency',
        'bool' => 'boolean',
        'checkbox' => 'boolean',
        'datetime-local' => 'datetime',
        'timestamp' => 'datetime',
        'select' => 'single_select',
        'dropdown' => 'single_select',
        'enum' => 'single_select',
        'multiselect' => 'multi_select',
        'tags' => 'multi_select',
    ];

    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private ProposeChangeTool $proposeTool,
    ) {}

    public function name(): string
    {
        return 'scaffold_app';
    }

    public function description(): string
    {
        return <<<'DESC'
Generate a complete, valid app skeleton in ONE step. Use this FIRST for any
"create an app for X" / "build me an app that …" request instead of hand-building
objects and pages op-by-op — it produces correct ids, slugs, required props and
a list page (heading + table) per object for you, then submits it through the
normal gate (it auto-applies at turn end like any change).

Pass `objects`: an array where each object is
  {"name": "Leads", "fields": [ {"name": "Nombre", "type": "string"}, {"name": "Estado", "type": "single_select", "options": ["Nuevo", "Contactado", "Ganado"]} ]}
- `name` (required) is the human display name; the slug is derived for you.
- `type` defaults to "string". Supported: string, long_text, number, boolean,
  date, datetime, currency, single_select, multi_select (plus friendly aliases
  like text/email/phone→string, select→single_select, money→currency). Unknown
  types fall back to string. Relations / formula / lookup / rollup / file are NOT
  scaffolded — add those with propose_change afterwards.
- `options` (array of plain strings) is for single_select / multi_select; the
  tool turns each into a proper {value,label} option.

Optional `include_pages` (default true): also create one list page per object.

Returns {ok, created:[{object_id, slug, field_ids, page_id}], notes} on success
(use those ids to keep building: forms, modals, action columns, workflows), or
{ok:false, errors} if the assembled manifest failed validation.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'objects' => $schema
                ->array()
                ->description('Array of objects to create. Each: {name: string (required), fields?: [{name: string, type?: string, options?: string[]}]}. If an object has no fields, a single "Nombre" text field is added (every object needs at least one).')
                ->required(),
            'include_pages' => $schema
                ->boolean()
                ->description('Whether to also generate a list page (heading + table) per object. Default true.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $objectsSpec = $args['objects'] ?? [];
        $includePages = $args['include_pages'] ?? true;

        if (! is_array($objectsSpec) || $objectsSpec === []) {
            return $this->fail('`objects` must be a non-empty array of {name, fields?}.');
        }

        $base = $this->proposeTool->runningDraft() ?? $this->manifestService->getActiveManifest($this->appModel);
        if (! is_array($base)) {
            return $this->fail('No active manifest exists for this app yet.');
        }

        $usedObjectSlugs = array_filter(array_map(fn ($o) => $o['slug'] ?? null, $base['objects'] ?? []));
        $usedPageSlugs = array_filter(array_map(fn ($p) => $p['slug'] ?? null, $base['pages'] ?? []));
        $usedPaths = array_filter(array_map(fn ($p) => $p['path'] ?? null, $base['pages'] ?? []));

        $ops = [];
        $created = [];
        $notes = [];

        foreach ($objectsSpec as $spec) {
            if (! is_array($spec) || trim((string) ($spec['name'] ?? '')) === '') {
                $notes[] = 'Skipped an object with no name.';

                continue;
            }

            $objectName = trim((string) $spec['name']);
            $objectSlug = $this->uniqueSlug($objectName, $usedObjectSlugs);
            $usedObjectSlugs[] = $objectSlug;
            $objectId = $this->id('obj');

            [$fields, $fieldNotes] = $this->buildFields($spec['fields'] ?? [], $objectName);
            $notes = array_merge($notes, $fieldNotes);

            $ops[] = ['op' => 'add', 'path' => '/objects/-', 'value' => [
                'id' => $objectId,
                'slug' => $objectSlug,
                'name' => $objectName,
                'primary_display_field_id' => $fields[0]['id'],
                'fields' => $fields,
            ]];

            $entry = [
                'object_id' => $objectId,
                'slug' => $objectSlug,
                'field_ids' => array_map(fn ($f) => ['id' => $f['id'], 'slug' => $f['slug'], 'type' => $f['type']], $fields),
            ];

            if ($includePages) {
                $pageSlug = $this->uniqueSlug($objectSlug, $usedPageSlugs);
                $usedPageSlugs[] = $pageSlug;
                $path = $this->uniquePath($pageSlug, $usedPaths);
                $usedPaths[] = $path;
                $pageId = $this->id('pag');

                $ops[] = ['op' => 'add', 'path' => '/pages/-', 'value' => [
                    'id' => $pageId,
                    'slug' => $pageSlug,
                    'name' => $objectName,
                    'path' => $path,
                    'blocks' => [
                        ['id' => $this->id('blk'), 'type' => 'heading', 'content' => $objectName, 'level' => 2],
                        ['id' => $this->id('blk'), 'type' => 'table',
                            'data_source' => ['object_id' => $objectId],
                            'columns' => array_map(fn ($f) => ['id' => $this->id('col'), 'field_id' => $f['id']], $fields),
                        ],
                    ],
                ]];

                $entry['page_id'] = $pageId;
            }

            $created[] = $entry;
        }

        if ($ops === []) {
            return $this->fail('Nothing to scaffold — every object spec was missing a name.');
        }

        $objectCount = count($created);
        $summary = $objectCount === 1
            ? "Creé el objeto «{$created[0]['slug']}»".($includePages ? ' con su página' : '')
            : "Creé {$objectCount} objetos".($includePages ? ' con sus páginas' : '');

        $result = $this->proposeTool->recordProposal($ops, $summary);

        if (($result['ok'] ?? false) === true) {
            $result['created'] = $created;
            if ($notes !== []) {
                $result['notes'] = $notes;
            }
            $result['message'] = 'Skeleton scaffolded and recorded. Continue with propose_change to add forms, action columns, relations, workflows, etc. Use the returned ids.';
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function buildFields(mixed $fieldsSpec, string $objectName): array
    {
        $notes = [];
        $fields = [];
        $usedFieldSlugs = [];

        if (! is_array($fieldsSpec) || $fieldsSpec === []) {
            $fields[] = $this->makeField('Nombre', 'string', [], $usedFieldSlugs);
            $notes[] = "«{$objectName}» had no fields, so a «Nombre» text field was added.";

            return [$fields, $notes];
        }

        foreach ($fieldsSpec as $fieldSpec) {
            if (! is_array($fieldSpec) || trim((string) ($fieldSpec['name'] ?? '')) === '') {
                continue;
            }

            $fieldName = trim((string) $fieldSpec['name']);
            $rawType = strtolower(trim((string) ($fieldSpec['type'] ?? 'string')));
            $type = self::TYPE_ALIASES[$rawType] ?? $rawType;
            $options = is_array($fieldSpec['options'] ?? null) ? $fieldSpec['options'] : [];

            if (! in_array($type, self::SIMPLE_TYPES, true)) {
                $notes[] = "Field «{$fieldName}»: type «{$rawType}» is not scaffoldable, used string instead (add the real type with propose_change).";
                $type = 'string';
            }

            if (in_array($type, ['single_select', 'multi_select'], true) && $options === []) {
                $notes[] = "Field «{$fieldName}»: a select needs options, none given — used a text field instead.";
                $type = 'string';
            }

            $fields[] = $this->makeField($fieldName, $type, $options, $usedFieldSlugs);
        }

        if ($fields === []) {
            $fields[] = $this->makeField('Nombre', 'string', [], $usedFieldSlugs);
            $notes[] = "«{$objectName}» had no usable fields, so a «Nombre» text field was added.";
        }

        return [$fields, $notes];
    }

    /**
     * @param  list<mixed>  $options
     * @param  list<string>  $usedFieldSlugs
     * @return array<string, mixed>
     */
    private function makeField(string $name, string $type, array $options, array &$usedFieldSlugs): array
    {
        $slug = $this->uniqueSlug($name, $usedFieldSlugs);
        $usedFieldSlugs[] = $slug;

        $field = [
            'id' => $this->id('fld'),
            'slug' => $slug,
            'name' => $name,
            'type' => $type,
        ];

        if ($type === 'currency') {
            $field['currency_code'] = 'MXN';
        }

        if (in_array($type, ['single_select', 'multi_select'], true)) {
            $usedOptionValues = [];
            $field['options'] = [];
            foreach ($options as $option) {
                $label = trim((string) $option);
                if ($label === '') {
                    continue;
                }
                $value = $this->uniqueSlug($label, $usedOptionValues);
                $usedOptionValues[] = $value;
                $field['options'][] = [
                    'id' => $this->id('opt'),
                    'value' => $value,
                    'label' => $label,
                ];
            }
        }

        return $field;
    }

    /**
     * @param  list<string>  $used
     */
    private function uniqueSlug(string $name, array $used): string
    {
        $base = $this->slugify($name);
        $slug = $base;
        $i = 2;
        while (in_array($slug, $used, true)) {
            $slug = $base.'_'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * @param  list<string>  $used
     */
    private function uniquePath(string $slug, array $used): string
    {
        $path = '/'.$slug;
        $i = 2;
        while (in_array($path, $used, true)) {
            $path = '/'.$slug.'_'.$i;
            $i++;
        }

        return $path;
    }

    private function slugify(string $value): string
    {
        $slug = Str::slug($value, '_');
        if ($slug === '') {
            $slug = 'campo';
        }
        if (! preg_match('/^[a-z]/', $slug)) {
            $slug = 'f_'.$slug;
        }

        return mb_substr($slug, 0, 50);
    }

    private function id(string $prefix): string
    {
        return $prefix.'_'.strtolower((string) Str::ulid());
    }

    private function fail(string $message): string
    {
        return json_encode([
            'ok' => false,
            'errors' => [['path' => '/', 'message' => $message, 'code' => 'bad_input']],
        ], JSON_THROW_ON_ERROR);
    }
}
