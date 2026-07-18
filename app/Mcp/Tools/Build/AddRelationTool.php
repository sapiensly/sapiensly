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

#[Description('Link two existing objects. Default is a BELONGS-TO: each `from` record belongs to one `to` record (e.g. each Draft belongs to one Idea) — it creates the picker on `from`, its inverse "has many" on `to`, and a child-count rollup column on the `to` table. Set kind="many_to_many" for a SYMMETRIC link where both sides hold many (e.g. a Scene features many Cast AND a Cast appears in many Scenes) — it puts a multi-picker on BOTH objects (no parent/child, no count). The typed alternative to hand-writing relation fields with propose_change. Saved as a new reversible version.')]
class AddRelationTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'from_object' => ['required', 'string'],
            'to_object' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:100'],
            'add_to_page' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'string', 'in:belongs_to,many_to_many'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        try {
            $kind = $validated['kind'] ?? 'belongs_to';
            $version = app(ManifestEditor::class)->addRelation(
                $app,
                $validated['from_object'],
                $validated['to_object'],
                $validated['name'] ?? null,
                $validated['add_to_page'] ?? true,
                $user,
                $kind,
            );
        } catch (\Throwable $e) {
            return Response::error('The relation could not be added: '.$e->getMessage());
        }

        return Response::json([
            'added' => true,
            'app_slug' => $app->slug,
            'relation' => $kind === 'many_to_many'
                ? "{$validated['from_object']} ↔ {$validated['to_object']} (many-to-many)"
                : "{$validated['from_object']} belongs to {$validated['to_object']}",
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
            'from_object' => $schema->string()->description('Slug of the "many" object — the one that belongs to the other (e.g. "drafts").')->required(),
            'to_object' => $schema->string()->description('Slug of the "one" object — the parent each `from` record points to (e.g. "ideas").')->required(),
            'name' => $schema->string()->description('Optional human label of the link on the `from` side (e.g. "Idea"); derived from the `to` object when omitted.'),
            'add_to_page' => $schema->boolean()->description('Also add the picker to the `from` object\'s create form + table (default true).'),
            'kind' => $schema->string()->description('Relation kind: "belongs_to" (default — from belongs to one to) or "many_to_many" (symmetric, both sides hold many; from_object/to_object order does not matter).'),
        ];
    }
}
