<?php

namespace App\Ai\Tools\Builder;

use App\Ai\Tools\Builder\Concerns\EditsTheDraftModel;
use App\Services\Manifest\ManifestEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Add an object to an app that already exists, with its fields and its CRUD page.
 *
 * `scaffold_app` builds a whole app at once and `add_crud_page` needs an object
 * that is already there — so adding ONE object to a built app was the gap
 * between them, and it was filled by hand-written patches.
 *
 * See {@see EditsTheDraftModel} for the reasoning.
 */
class AddObjectTool implements Tool
{
    use EditsTheDraftModel;

    public function __construct(
        private ProposeChangeTool $proposeTool,
        private ManifestEditor $editor,
    ) {}

    public function name(): string
    {
        return 'add_object';
    }

    public function description(): string
    {
        return <<<'DESC'
Add ONE object to an app that already exists — its fields, its access policy row,
and (by default) a ready-to-use list+create page. PREFER THIS over propose_change
for a new object: you describe the fields by name and type, and every id is minted
and wired server-side.

Pass `name` and `fields` as [{"name":"SKU","type":"string"}]. Creating an object
covers the BASIC field types only — for a computed field, or for settings like
capture/min/max/expression, add the object first and then call add_field, which
applies them. Passing them here is reported back to you, never dropped in
silence. Use `scaffold_app` instead when building a whole app from nothing, and
`add_relation` afterwards to link this object to the others.

Returns {ok, object:{slug}} or {ok:false, errors}.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Human label for the object, e.g. «Refacciones».')
                ->required(),
            'slug' => $schema->string()
                ->description('Optional explicit slug (^[a-z][a-z0-9_]*$). Derived from the name when omitted.'),
            'fields' => $schema->array()
                // Bare object items: Gemini rejects an array schema with no
                // `items` outright, so the shape lives in the description.
                ->items($schema->object())
                ->description('Field specs: [{name, type?, slug?, options?, config?}]. Defaults to a single «Name» string when empty.'),
            'with_page' => $schema->boolean()
                ->description('Also add a list+create page for it (default true).'),
        ];
    }

    public function handle(Request $request): string
    {
        $input = $request->all();
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return $this->encode($this->failure('`name` is required.'));
        }

        $before = $this->proposeTool->currentManifest();
        if (! is_array($before)) {
            return $this->encode($this->failure('No active manifest exists for this app yet.'));
        }

        $fields = $input['fields'] ?? [];
        $coercions = [];

        try {
            $after = $this->editor->applyAddObject(
                $before,
                $name,
                isset($input['slug']) ? trim((string) $input['slug']) : null,
                is_array($fields) ? $fields : [],
                ($input['with_page'] ?? true) !== false,
                $coercions,
            );
        } catch (\Throwable $e) {
            return $this->encode($this->failure($e->getMessage()));
        }

        $result = $this->stackOntoDraft($this->proposeTool, $before, $after, "Agregué el objeto «{$name}»");

        if (($result['ok'] ?? false) === true) {
            $objects = $after['objects'] ?? [];
            $object = end($objects) ?: [];
            $result['object'] = ['slug' => $object['slug'] ?? null];
            $result['message'] = "«{$name}» added"
                .(($input['with_page'] ?? true) !== false ? ' with its list page.' : '.')
                .' Use add_relation to link it to the other objects.';
            if ($coercions !== []) {
                $result['coercions'] = $coercions;
            }
        }

        return $this->encode($result);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
