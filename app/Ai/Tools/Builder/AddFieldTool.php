<?php

namespace App\Ai\Tools\Builder;

use App\Ai\Tools\Builder\Concerns\EditsTheDraftModel;
use App\Services\Manifest\ManifestEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Add a field to an object — and wire it into that object's tables and create
 * forms — without naming a single id.
 *
 * See {@see EditsTheDraftModel} for why this exists on the builder at all.
 */
class AddFieldTool implements Tool
{
    use EditsTheDraftModel;

    public function __construct(
        private ProposeChangeTool $proposeTool,
        private ManifestEditor $editor,
    ) {}

    public function name(): string
    {
        return 'add_field';
    }

    public function description(): string
    {
        return <<<'DESC'
Add ONE field to an existing object and wire it into that object's tables and
create forms automatically. PREFER THIS over propose_change for any new field:
you name the object by slug and the field by name, and the ids — the field's own,
and the copy of it that every form, column and detail block needs — are minted
and wired server-side. Nothing to look up, nothing to copy.

Pass `object_slug`, `name`, and `type` (default string). Type-specific settings
go in `config`, e.g.
  string  → {"capture":"barcode"}  scan it with the camera or a scanner gun
  file    → {"capture":"camera"} a photo taken on the spot, or {"capture":"signature"} a signed PNG
  formula → {"expression":"{{a * b}}", "return_type":"number"}
  rollup  → {"via_relation_field_id":"lineas", "aggregator":"sum", "target_field_id":"subtotal"}
  lookup  → {"via_relation_field_id":"refaccion", "target_field_id":"precio"}
  slider  → {"min":0, "max":10}
`config` also takes required/unique/indexed/help_text. Call
list_available_field_types for the exact props per type. Inside config you may
name related fields by SLUG. For a belongs-to link use add_relation instead — it
builds the inverse and its count too.

Computed types (formula/lookup/rollup) are made read-only and stay out of create
forms. Returns {ok, field:{slug, type}} or {ok:false, errors}.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_slug' => $schema->string()
                ->description('Slug of the object that gets the field.')
                ->required(),
            'name' => $schema->string()
                ->description('Human label, e.g. «Fecha de entrega». The slug is derived from it unless you pass one.')
                ->required(),
            'slug' => $schema->string()
                ->description('Optional explicit slug (^[a-z][a-z0-9_]*$). Derived from the name when omitted.'),
            'type' => $schema->string()
                ->description('Field type (default string). Call list_available_field_types for the catalog.'),
            'options' => $schema->array()
                // Bare object items: Gemini rejects an array schema with no
                // `items` outright, so the shape lives in the description.
                ->items($schema->object())
                ->description('For single_select/multi_select: the choices, as [{"value":"abierta","label":"Abierta"}].'),
            'config' => $schema->object()
                ->description('Type-specific props — see the description. Related fields may be named by slug.'),
            'add_to_page' => $schema->boolean()
                ->description('Also wire it into the object\'s tables + create forms (default true).'),
        ];
    }

    public function handle(Request $request): string
    {
        $input = $request->all();
        $objectSlug = trim((string) ($input['object_slug'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));

        if ($objectSlug === '' || $name === '') {
            return $this->encode($this->failure('`object_slug` and `name` are both required.'));
        }

        $before = $this->proposeTool->currentManifest();
        if (! is_array($before)) {
            return $this->encode($this->failure('No active manifest exists for this app yet.'));
        }

        if (collect($before['objects'] ?? [])->firstWhere('slug', $objectSlug) === null) {
            $known = collect($before['objects'] ?? [])->pluck('slug')->filter()->implode(', ');

            return $this->encode($this->failure(
                "No object with slug '{$objectSlug}' exists.".($known === '' ? '' : " This app has: {$known}.")
            ));
        }

        $spec = array_filter([
            'name' => $name,
            'slug' => $input['slug'] ?? null,
            'type' => $input['type'] ?? null,
            'options' => $input['options'] ?? null,
            'config' => $input['config'] ?? null,
        ], fn ($v): bool => $v !== null);

        $coercions = [];

        try {
            $after = $this->editor->applyAddField(
                $before,
                $objectSlug,
                $spec,
                ($input['add_to_page'] ?? true) !== false,
                $coercions,
            );
        } catch (\Throwable $e) {
            return $this->encode($this->failure($e->getMessage()));
        }

        $result = $this->stackOntoDraft($this->proposeTool, $before, $after, "Agregué el campo «{$name}» a {$objectSlug}");

        if (($result['ok'] ?? false) === true) {
            $added = collect($after['objects'] ?? [])->firstWhere('slug', $objectSlug)['fields'] ?? [];
            $field = end($added) ?: [];
            $result['field'] = ['slug' => $field['slug'] ?? null, 'type' => $field['type'] ?? null];
            $result['message'] = "«{$name}» added to {$objectSlug} and wired into its tables and forms.";
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
