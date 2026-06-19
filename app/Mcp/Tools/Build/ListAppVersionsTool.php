<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\AppVersion;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("List an app's version history (each propose_change / rollback creates a version), newest first.")]
class ListAppVersionsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $versions = AppVersion::query()
            ->where('app_id', $app->id)
            ->orderByDesc('version_number')
            ->limit($validated['limit'] ?? 25)
            ->get(['id', 'version_number', 'change_summary', 'created_at']);

        return Response::json([
            'current_version_id' => $app->current_version_id,
            'versions' => $versions->map(fn (AppVersion $v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'change_summary' => $v->change_summary,
                'created_at' => $v->created_at?->toIso8601String(),
                'is_current' => $v->id === $app->current_version_id,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'limit' => $schema->integer()->description('Max versions to return (default 25).'),
        ];
    }
}
