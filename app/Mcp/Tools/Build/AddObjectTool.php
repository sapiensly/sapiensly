<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\ManifestEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Add a new data object (like a table) to an existing app, with its fields — and, by default, a ready-to-use list+create page for it. The typed, reliable alternative to hand-writing RFC 6902 patches with propose_change. Saved as a new reversible version.')]
class AddObjectTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:50'],
            'fields' => ['required', 'array', 'min:1'],
            'with_page' => ['nullable', 'boolean'],
        ], [
            'fields.required' => 'Provide at least one field, e.g. [{"name":"Title","type":"string"}].',
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $coercions = [];

        try {
            $version = app(ManifestEditor::class)->addObject(
                $app,
                $validated['name'],
                $validated['slug'] ?? null,
                $validated['fields'],
                $validated['with_page'] ?? true,
                $user,
                $coercions,
            );
        } catch (\Throwable $e) {
            return Response::error('The object could not be added: '.$e->getMessage());
        }

        return Response::json(array_filter([
            'added' => true,
            'app_slug' => $app->slug,
            'object' => $validated['name'],
            'version_number' => $version->version_number,
            // Any field spec that had to be adjusted to stay valid.
            'warnings' => $coercions ?: null,
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app to add the object to.')->required(),
            'name' => $schema->string()->description('Human name of the object, e.g. "Comments".')->required(),
            'slug' => $schema->string()->description('Optional snake_case slug; derived from the name when omitted.'),
            'fields' => $schema->array()->description('The object\'s fields. Each item: {name (required), slug? (snake_case, derived if omitted), type (one of: string, long_text, number, currency, boolean, date, datetime, single_select, multi_select, rating; defaults to string), options? (REQUIRED only for single_select/multi_select: an array of {value, label})}. Put a short title/name field first.')->required(),
            'with_page' => $schema->boolean()->description('Also generate a list+create page for the object (default true).'),
        ];
    }
}
