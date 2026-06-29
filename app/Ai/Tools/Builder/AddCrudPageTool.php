<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Compact page builder: from just an object slug, assemble a full CRUD list page
 * (heading + "new" modal/form + table, and a kanban when the object has a status
 * field) server-side and submit it through propose_change. The model describes
 * intent in one tiny call instead of hand-writing the whole page's blocks — no
 * giant tool argument, no id thrash, correct shapes guaranteed.
 */
class AddCrudPageTool implements Tool
{
    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private ProposeChangeTool $proposeTool,
        private AppScaffolder $scaffolder,
    ) {}

    public function name(): string
    {
        return 'add_crud_page';
    }

    public function description(): string
    {
        return <<<'DESC'
Add a complete CRUD list page for ONE existing object in a single step: heading +
a "new" button/modal/form (entering all the object's editable fields) + a table of
its records, plus a kanban board when the object has a status (single_select)
field. Pass `object_slug`. Use this instead of hand-building the page's blocks
op-by-op. Returns {ok, page:{slug, path}} or {ok:false, errors}.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_slug' => $schema
                ->string()
                ->description('Slug of the object to build the list page for (must already exist).')
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

        $object = collect($base['objects'] ?? [])->firstWhere('slug', $slug);
        if ($object === null) {
            return $this->fail("No object with slug '{$slug}' exists. Create it first (scaffold_app or add_object).");
        }

        $lang = AppScaffolder::langForLocale($base['settings']['default_locale'] ?? null);
        $fieldIndex = array_map(
            fn (array $f): array => ['id' => $f['id'], 'slug' => $f['slug'], 'type' => $f['type']],
            $object['fields'],
        );
        $pageSlug = $this->uniquePageSlug($object['slug'], $base);

        $page = $this->scaffolder->buildPage(
            ['name' => $object['name'], 'slug' => $pageSlug],
            $object['id'],
            $fieldIndex,
            $lang,
        );

        $result = $this->proposeTool->recordProposal(
            [['op' => 'add', 'path' => '/pages/-', 'value' => $page]],
            "Agregué la página de {$object['name']}",
        );

        if (($result['ok'] ?? false) === true) {
            $result['page'] = ['slug' => $page['slug'], 'path' => $page['path']];
            $result['message'] = "CRUD page for «{$object['name']}» added at {$page['path']}.";
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $base
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
