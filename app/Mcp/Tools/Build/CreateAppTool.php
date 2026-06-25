<?php

namespace App\Mcp\Tools\Build;

use App\Enums\Visibility;
use App\Mcp\Tools\SapiensTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a new app. It starts with an empty, valid manifest (no objects or pages yet) saved as version 1; use read_manifest + propose_change to build it out. The slug must be unique within your account and match ^[a-z][a-z0-9_]*$.')]
class CreateAppTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'visibility' => ['nullable', Rule::enum(Visibility::class)],
            'idempotency_key' => ['nullable', 'string', 'max:200'],
        ]);

        // Replay a prior create for this key instead of erroring on the slug a
        // timed-out-but-succeeded first attempt already took.
        if (($replay = $this->idempotentReplay($user, $validated['idempotency_key'] ?? null)) !== null) {
            return Response::json($replay);
        }

        // Slug is unique per tenant (organization_id, slug); fail with a legible
        // message rather than a raw DB constraint violation.
        $slugExists = App::query()->forAccountContext($user)->where('slug', $validated['slug'])->exists();
        if ($slugExists) {
            return Response::error("An app with slug '{$validated['slug']}' already exists in your account.");
        }

        $manifestService = app(AppManifestService::class);

        $app = App::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'icon' => $validated['icon'] ?? null,
            'color' => $validated['color'] ?? null,
            'visibility' => isset($validated['visibility']) ? Visibility::from($validated['visibility']) : Visibility::Private,
        ]);

        try {
            $version = $manifestService->createVersion(
                $app,
                $manifestService->initialManifest($app),
                $user,
                'Initial version',
            );
        } catch (\Throwable $e) {
            // Roll back the orphaned app row if seeding the first version fails.
            $app->delete();

            return Response::error('The app could not be created: '.$e->getMessage());
        }

        $payload = [
            'created' => true,
            'app_slug' => $app->slug,
            'app_id' => $app->id,
            'name' => $app->name,
            'visibility' => $app->visibility?->value,
            'version_number' => $version->version_number,
        ];
        $this->rememberIdempotent($user, $validated['idempotency_key'] ?? null, $payload);

        return Response::json($payload);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The app name (max 100 chars).')->required(),
            'slug' => $schema->string()->description('URL-safe id, unique in your account: ^[a-z][a-z0-9_]*$ (e.g. "support_desk").')->required(),
            'description' => $schema->string()->description('What the app is for.'),
            'icon' => $schema->string()->description('Optional icon name.'),
            'color' => $schema->string()->description('Optional hex accent color (#RRGGBB).'),
            'visibility' => $schema->string()->enum(array_column(Visibility::cases(), 'value'))->description('private (default), organization, global, or public.'),
            'idempotency_key' => $schema->string()->description('Optional. A unique client token; retrying with the same key replays the original result instead of creating a duplicate app (safe retry after a timeout).'),
        ];
    }
}
