<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Return the authoritative App manifest JSON Schema so you can author a valid manifest or patch without guessing. Call with no args for the top-level shape (required keys, root properties) plus the catalog of definitions you can drill into. Pass `definition` (e.g. "block_button", "field_relation", "action_create_record", "workflow", "step") to get that exact sub-schema — every required property, allowed enum value and description. This is the same schema validate_manifest / propose_change check against.')]
class GetManifestSchemaTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'definition' => ['sometimes', 'string'],
        ]);

        $schema = app(ManifestValidator::class)->schemaArray();
        $defs = $schema['$defs'] ?? [];

        $requested = $validated['definition'] ?? null;
        if ($requested !== null && $requested !== '') {
            $key = $this->resolveDefinition($requested, $defs);
            if ($key === null) {
                return Response::error(
                    "No definition named '{$requested}'. Call get_manifest_schema with no arguments to list the available definitions."
                );
            }

            return Response::json([
                'definition' => $key,
                'schema' => $defs[$key],
            ]);
        }

        return Response::json([
            'schema_id' => $schema['$id'] ?? null,
            'schema_version_pattern' => $schema['properties']['schema_version']['pattern'] ?? null,
            'root_required' => $schema['required'] ?? [],
            'root_properties' => array_keys($schema['properties'] ?? []),
            'definitions' => $this->groupDefinitions(array_keys($defs)),
            'next' => 'Call get_manifest_schema with definition="<name>" (e.g. "block_table", "field_relation", "action_create_record", "workflow", "step") to get that exact sub-schema.',
        ]);
    }

    /**
     * Resolve a requested definition name to an actual $defs key. Accepts the exact
     * key, and friendly "block:button" / "block.button" forms → "block_button".
     *
     * @param  array<string, mixed>  $defs
     */
    private function resolveDefinition(string $requested, array $defs): ?string
    {
        if (isset($defs[$requested])) {
            return $requested;
        }

        $normalized = str_replace([':', '.', '-', ' '], '_', strtolower(trim($requested)));

        return isset($defs[$normalized]) ? $normalized : null;
    }

    /**
     * Group definition names by their prefix (block/field/action/step/trigger/other)
     * so the model can scan what's available at a glance.
     *
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    private function groupDefinitions(array $names): array
    {
        $groups = ['blocks' => [], 'fields' => [], 'actions' => [], 'steps' => [], 'triggers' => [], 'other' => []];

        foreach ($names as $name) {
            $group = match (true) {
                str_starts_with($name, 'block_') => 'blocks',
                str_starts_with($name, 'field_') => 'fields',
                str_starts_with($name, 'action_') => 'actions',
                str_starts_with($name, 'step_') => 'steps',
                str_starts_with($name, 'trigger_') => 'triggers',
                default => 'other',
            };
            $groups[$group][] = $name;
        }

        return array_filter($groups, fn (array $g): bool => $g !== []);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'definition' => $schema->string()
                ->description('Optional. A definition name from the catalog (e.g. "block_button", "field_relation", "action_create_record", "workflow", "step") to return just that sub-schema. Omit to get the top-level shape and the list of definitions.'),
        ];
    }
}
