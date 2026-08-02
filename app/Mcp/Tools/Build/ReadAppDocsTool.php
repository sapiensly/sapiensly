<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Apps\Docs\AppDocs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Read an app\'s two generated documents as Markdown: the USER GUIDE (`manual`) — what the app is for, what each screen shows, and the steps to do each thing — and the TECHNICAL SHEET (`technical`) — every object, field, relation, block, action, workflow and permission with its id resolved to a name, plus the JSON pointers a patch is written against. Both are derived from the current manifest, so they are never stale. Read the technical sheet before changing an app you did not just build: it is `read_manifest` with the ids already joined up, at a fraction of the tokens.')]
class ReadAppDocsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'document' => ['sometimes', 'in:manual,technical,both'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        if ($app->currentVersion === null) {
            return Response::error("App '{$app->slug}' has no active version yet.");
        }

        $docs = app(AppDocs::class);
        $wanted = $validated['document'] ?? 'technical';

        $payload = [
            'app_slug' => $app->slug,
            'version' => $app->currentVersion->version_number,
            'url' => route('apps.docs', ['app' => $app->id]),
        ];

        foreach ($wanted === 'both' ? AppDocs::KINDS : [$wanted] as $kind) {
            $payload[$kind] = $docs->of($app, $kind)->toMarkdown();
        }

        return Response::json($payload);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()
                ->description('The slug of the app to document.')
                ->required(),
            'document' => $schema->string()
                ->description("Which one: 'technical' (the default — the data model, blocks, actions and pointers), 'manual' (how a person uses it), or 'both'."),
        ];
    }
}
