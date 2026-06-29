<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Compact master-detail builder: from a parent object slug, assemble its detail
 * page (breadcrumb + record_detail of the parent + per child relationship an
 * inline "add child" form and a related_list of that parent's children) and wire
 * an "open" row action into the parent's list table — all server-side, in one
 * call. Children are discovered from the manifest (any object with a many_to_one
 * relation pointing at the parent).
 */
class AddDetailPageTool implements Tool
{
    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private ProposeChangeTool $proposeTool,
        private AppScaffolder $scaffolder,
    ) {}

    public function name(): string
    {
        return 'add_detail_page';
    }

    public function description(): string
    {
        return <<<'DESC'
Add a master-detail page for ONE parent object: the parent's fields (record_detail)
plus, for each child object that belongs to it, an inline "add child" form and a
related_list of that parent's children — and an "open" row action on the parent's
list table so each row links to its detail. Pass `object_slug` (the parent). The
parent must already have at least one child (an object with a belongs-to relation
to it). Returns {ok, page:{slug, path}} or {ok:false, errors}.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_slug' => $schema
                ->string()
                ->description('Slug of the PARENT object (the one whose record + children the detail page shows).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $slug = trim((string) ($request->all()['object_slug'] ?? ''));
        if ($slug === '') {
            return $this->fail('`object_slug` is required.');
        }

        $base = $this->proposeTool->currentManifest();
        if (! is_array($base)) {
            return $this->fail('No active manifest exists for this app yet.');
        }

        $parent = collect($base['objects'] ?? [])->firstWhere('slug', $slug);
        if ($parent === null) {
            return $this->fail("No object with slug '{$slug}' exists.");
        }

        $children = $this->childrenOf($parent, $base['objects'] ?? []);
        if ($children === []) {
            return $this->fail("«{$parent['name']}» has no child objects (nothing links to it). Add a relation first (add_relation), then call add_detail_page.");
        }

        $lang = AppScaffolder::langForLocale($base['settings']['default_locale'] ?? null);
        $parentPageFields = array_map(
            fn (array $f): array => ['id' => $f['id'], 'slug' => $f['slug'], 'type' => $f['type']],
            $parent['fields'],
        );
        $detailSlug = $this->uniquePageSlug($parent['slug'].'_detail', $base);

        $page = $this->scaffolder->buildDetailPage($parent, $parentPageFields, $detailSlug, $children, $lang);

        $ops = [['op' => 'add', 'path' => '/pages/-', 'value' => $page]];

        // Link the parent's list table to the detail page, when one exists.
        $columnsPath = $this->parentTableColumnsPath($base, $parent['id']);
        if ($columnsPath !== null) {
            $ops[] = ['op' => 'add', 'path' => $columnsPath, 'value' => [
                'id' => $this->scaffolder->id('col'),
                'type' => 'action',
                'label' => $lang === 'es' ? 'Abrir' : 'Open',
                'icon' => 'arrow-right',
                'variant' => 'ghost',
                'on_click' => [['type' => 'navigate', 'to' => '/'.$detailSlug.'?id={{row.id}}']],
            ]];
        }

        $result = $this->proposeTool->recordProposal($ops, "Agregué la vista de detalle de {$parent['name']}");

        if (($result['ok'] ?? false) === true) {
            $result['page'] = ['slug' => $page['slug'], 'path' => $page['path']];
            $result['message'] = "Detail page for «{$parent['name']}» added at {$page['path']}".($columnsPath !== null ? ' and linked from its list.' : '.');
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    /**
     * Child entries for buildDetailPage: every object with a many_to_one relation
     * pointing at the parent, with that relation's field id/slug.
     *
     * @param  array<string, mixed>  $parent
     * @param  list<array<string, mixed>>  $objects
     * @return list<array<string, mixed>>
     */
    private function childrenOf(array $parent, array $objects): array
    {
        $children = [];
        foreach ($objects as $object) {
            foreach ($object['fields'] ?? [] as $field) {
                if (($field['type'] ?? null) === 'relation'
                    && ($field['cardinality'] ?? null) === 'many_to_one'
                    && ($field['target_object_id'] ?? null) === $parent['id']) {
                    $children[] = [
                        'def' => $object,
                        'pageFields' => array_map(
                            fn (array $f): array => ['id' => $f['id'], 'slug' => $f['slug'], 'type' => $f['type']],
                            $object['fields'],
                        ),
                        'childFieldId' => $field['id'],
                        'childFieldSlug' => $field['slug'],
                    ];
                }
            }
        }

        return $children;
    }

    /**
     * Path to append a column to the (first) top-level table over the parent
     * object, or null when the parent has no list table to link from.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function parentTableColumnsPath(array $manifest, string $parentId): ?string
    {
        foreach ($manifest['pages'] ?? [] as $pi => $page) {
            foreach ($page['blocks'] ?? [] as $bi => $block) {
                if (($block['type'] ?? null) === 'table'
                    && ($block['data_source']['object_id'] ?? null) === $parentId) {
                    return "/pages/{$pi}/blocks/{$bi}/columns/-";
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function uniquePageSlug(string $base, array $manifest): string
    {
        $taken = array_filter(array_map(fn ($p) => $p['slug'] ?? null, $manifest['pages'] ?? []));
        $slug = $base;
        $n = 2;
        while (in_array($slug, $taken, true)) {
            $slug = $base.'_'.$n++;
        }

        return $slug;
    }

    private function fail(string $message): string
    {
        return json_encode([
            'ok' => false,
            'errors' => [['path' => '/', 'message' => $message, 'code' => 'bad_input']],
        ], JSON_THROW_ON_ERROR);
    }
}
