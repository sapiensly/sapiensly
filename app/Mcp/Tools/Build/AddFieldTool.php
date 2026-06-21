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

#[Description('Add a field to an existing object in an app — and, by default, wire it into that object\'s table and create form so it is immediately usable. The typed, reliable alternative to hand-writing RFC 6902 patches with propose_change. Saved as a new reversible version.')]
class AddFieldTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'object_slug' => ['required', 'string'],
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:50'],
            'type' => ['nullable', 'string'],
            'options' => ['nullable', 'array'],
            'add_to_page' => ['nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        try {
            $version = app(ManifestEditor::class)->addField(
                $app,
                $validated['object_slug'],
                [
                    'name' => $validated['name'],
                    'slug' => $validated['slug'] ?? null,
                    'type' => $validated['type'] ?? 'string',
                    'options' => $validated['options'] ?? null,
                ],
                $validated['add_to_page'] ?? true,
                $user,
            );
        } catch (\Throwable $e) {
            return Response::error('The field could not be added: '.$e->getMessage());
        }

        return Response::json([
            'added' => true,
            'app_slug' => $app->slug,
            'object_slug' => $validated['object_slug'],
            'field' => $validated['name'],
            'version_number' => $version->version_number,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'object_slug' => $schema->string()->description('The slug of the object to add the field to.')->required(),
            'name' => $schema->string()->description('Human name of the field, e.g. "Priority".')->required(),
            'slug' => $schema->string()->description('Optional snake_case slug; derived from the name when omitted.'),
            'type' => $schema->string()->enum(['string', 'long_text', 'number', 'currency', 'boolean', 'date', 'datetime', 'single_select', 'multi_select', 'rating'])->description('Field type (default string). There is no email/url type — use string.'),
            'options' => $schema->array()->description('REQUIRED only for single_select/multi_select: an array of {value, label}. Ignored for other types.'),
            'add_to_page' => $schema->boolean()->description('Also add the field to the object\'s table + create form (default true).'),
        ];
    }
}
