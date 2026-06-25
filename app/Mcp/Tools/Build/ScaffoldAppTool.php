<?php

namespace App\Mcp\Tools\Build;

use App\Enums\Visibility;
use App\Mcp\Tools\SapiensTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a COMPLETE working app from a plain-language description in ONE step — the easy alternative to create_app + a long chain of propose_change patches. It generates the data objects and their fields, the belongs-to relations between them (e.g. a draft belongs to one idea), a ready-to-use list+create page for each (with a kanban board when the object has a status field), and a dashboard landing page with KPIs and a status chart — saved as version 1. Use this to start any new app; then refine details with the add_object / add_field / add_relation tools or propose_change.')]
class ScaffoldAppTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'slug' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:50'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'visibility' => ['nullable', Rule::enum(Visibility::class)],
            'idempotency_key' => ['nullable', 'string', 'max:200'],
        ]);

        // A scaffold is an expensive LLM call; replay a prior result for this key
        // instead of generating (and billing) a second near-duplicate app.
        if (($replay = $this->idempotentReplay($user, $validated['idempotency_key'] ?? null)) !== null) {
            return Response::json($replay);
        }

        $slug = $validated['slug'] ?? $this->deriveSlug($validated['name'], $user);
        if ($slug === null) {
            return Response::error('Could not derive a unique slug from the name; pass an explicit `slug`.');
        }

        if (App::query()->forAccountContext($user)->where('slug', $slug)->exists()) {
            return Response::error("An app with slug '{$slug}' already exists in your account. Pass a different `slug`.");
        }

        $manifestService = app(AppManifestService::class);

        $app = App::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'slug' => $slug,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'icon' => $validated['icon'] ?? null,
            'color' => $validated['color'] ?? null,
            'visibility' => isset($validated['visibility']) ? Visibility::from($validated['visibility']) : Visibility::Private,
        ]);

        try {
            $manifest = app(AppScaffolder::class)->scaffold(
                $manifestService->initialManifest($app),
                $validated['description'],
                $user,
            );

            $version = $manifestService->createVersion($app, $manifest, $user, 'Scaffolded from description');
        } catch (\Throwable $e) {
            // Roll back the orphaned app row so a failed scaffold leaves nothing behind.
            $app->delete();

            return Response::error('The app could not be scaffolded: '.$e->getMessage());
        }

        $payload = [
            'created' => true,
            'app_slug' => $app->slug,
            'app_id' => $app->id,
            'name' => $app->name,
            'version_number' => $version->version_number,
            'objects' => array_map(fn (array $o): array => [
                'slug' => $o['slug'],
                'name' => $o['name'],
                'fields' => count($o['fields']),
            ], $manifest['objects']),
            'pages' => array_map(fn (array $p): string => $p['path'], $manifest['pages']),
            'next' => 'Open the app to use it, or refine it with read_manifest + propose_change.',
        ];
        $this->rememberIdempotent($user, $validated['idempotency_key'] ?? null, $payload);

        return Response::json($payload);
    }

    /**
     * Derive a unique, schema-valid slug from the app name, suffixing on collision.
     */
    private function deriveSlug(string $name, User $user): ?string
    {
        $base = trim((string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($name)), '_');
        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = 'app_'.$base;
        }
        $base = (string) Str::limit($base, 40, '');

        $slug = $base;
        $n = 2;
        while (App::query()->forAccountContext($user)->where('slug', $slug)->exists()) {
            if ($n > 50) {
                return null;
            }
            $slug = $base.'_'.$n++;
        }

        return $slug;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The app name (max 100 chars), e.g. "Content Engine".')->required(),
            'description' => $schema->string()->description('Plain-language description of what the app should track and do — the entities, their fields, and the workflow. The richer this is, the better the generated objects and pages.')->required(),
            'slug' => $schema->string()->description('Optional URL-safe id (^[a-z][a-z0-9_]*$). Derived from the name when omitted.'),
            'icon' => $schema->string()->description('Optional icon name.'),
            'color' => $schema->string()->description('Optional hex accent color (#RRGGBB).'),
            'visibility' => $schema->string()->enum(array_column(Visibility::cases(), 'value'))->description('private (default), organization, global, or public.'),
            'idempotency_key' => $schema->string()->description('Optional. A unique client token; retrying with the same key replays the original result instead of scaffolding a second app (safe retry after a timeout — and avoids a duplicate LLM call).'),
        ];
    }
}
