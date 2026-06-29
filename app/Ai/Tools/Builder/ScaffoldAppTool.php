<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
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
        private AppScaffolder $scaffolder,
    ) {}

    public function name(): string
    {
        return 'scaffold_app';
    }

    public function description(): string
    {
        return <<<'DESC'
Generate a complete, valid app in ONE step — THE first move for any "create an
app for X" / "build me an app that …" request, instead of hand-building objects,
relations and pages op-by-op (which is slow and fragile). On an EMPTY app it
assembles the whole thing: objects with correct ids/slugs, the belongs-to
RELATIONS from `links`, derived fields (a parent count + money total, and for an
order→line→priced-product shape a unit-price lookup + line subtotal), a list
page per object, master-detail pages, a dashboard, and — when the data looks like
a point of sale — a ready POS screen (product grid + live cart). It submits
through the normal gate (auto-applies at turn end). You send a COMPACT spec; the
big manifest is built server-side, so nothing huge crosses the wire.

Pass `objects`: an array where each is
  {"name": "Platillos", "slug": "platillos", "fields": [ {"name":"Nombre","type":"string"}, {"name":"Precio","type":"currency"}, {"name":"Estado","type":"single_select","options":["Abierta","Pagada"]} ]}
- `name` (required) + a short snake_case `slug` (recommended; derived if omitted).
- `type` defaults to "string". Supported: string, long_text, number, boolean,
  date, datetime, currency, single_select, multi_select (aliases like
  text/email→string, select→single_select, money→currency). Unknown → string.
- `options` (plain strings) for single_select / multi_select.

Pass `links` for belongs-to relations: [{"from":"renglones","to":"comandas","name":"comanda"}]
means "a renglón belongs to one comanda" (from/to are object slugs). Model an
order with line items, and a line that references a priced product, as links —
the lookup/subtotal/total/POS screen are then generated for you. Do NOT add a
field to hold another object's id; use a link.

Optional `include_pages` (default true).

Returns {ok, created:[{object_id, slug}], pages:[…], notes} on success (use the
ids to keep refining with propose_change), or {ok:false, errors}.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'objects' => $schema
                ->array()
                ->description('Objects to create. Each: {name: string (required), slug?: string, fields?: [{name: string, type?: string, options?: string[]}]}. An object with no fields gets a "Nombre" text field.')
                ->required(),
            'links' => $schema
                ->array()
                ->description('Belongs-to relations: [{from: <object slug>, to: <object slug>, name: <label on the from side>}]. A <from> belongs to one <to>. Only applied when scaffolding a fresh (empty) app.'),
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

        // Cold start (empty app): assemble the WHOLE app — relations from `links`,
        // derived economics, master-detail and the POS screen — in one step. On an
        // app that already has objects we fall back to the incremental path below
        // so we never wipe existing work.
        if (($base['objects'] ?? []) === []) {
            return $this->scaffoldFullApp($objectsSpec, $args['links'] ?? [], $base);
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
     * Cold-start path: run the full AppScaffolder pipeline (relations from links,
     * derived economics, master-detail + POS recipe, dashboard) over the empty
     * base and submit the assembled objects + pages as one proposal. The model
     * only sent a compact spec; the heavy manifest is built here, server-side.
     *
     * @param  list<mixed>  $objectsSpec
     * @param  array<string, mixed>  $base
     */
    private function scaffoldFullApp(array $objectsSpec, mixed $links, array $base): string
    {
        $spec = $this->scaffolder->normalizeSpec([
            'objects' => $objectsSpec,
            'links' => is_array($links) ? $links : [],
        ]);

        if (($spec['objects'] ?? []) === []) {
            return $this->fail('Every object spec was missing a name.');
        }

        $assembled = $this->scaffolder->assemble($base, $spec);

        $ops = [
            ['op' => 'replace', 'path' => '/objects', 'value' => $assembled['objects']],
            ['op' => 'replace', 'path' => '/pages', 'value' => $assembled['pages']],
        ];

        $count = count($assembled['objects']);
        $summary = "Generé {$count} ".($count === 1 ? 'objeto' : 'objetos').' con sus relaciones, cálculos y páginas';

        $result = $this->proposeTool->recordProposal($ops, $summary);

        if (($result['ok'] ?? false) === true) {
            $result['created'] = array_map(
                fn (array $o): array => ['object_id' => $o['id'], 'slug' => $o['slug']],
                $assembled['objects'],
            );
            $result['pages'] = array_map(fn (array $p): string => $p['slug'], $assembled['pages']);
            $result['message'] = 'Full app scaffolded: objects, belongs-to relations, derived fields (counts/totals + any lookup/subtotal), a page per object, master-detail pages, a dashboard, and a POS screen when the data fits. Refine details with propose_change using the returned ids.';
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
