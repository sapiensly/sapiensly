<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\AppVersion;
use App\Models\User;
use App\Services\Manifest\ManifestDiffService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Semantic diff between two app versions — what a build turn (or a stretch of them) actually changed, entity by entity: objects/fields/pages/roles added, removed or modified, and for a modified field which properties moved (type, cardinality, options, …). Matches on stable ids, so it ignores reordering and generated-id churn a text diff would drown in. Defaults to the last change (previous version → current); pass `from`/`to` version numbers (from list_app_versions) to compare any two. Returns a rolled-up count summary plus the detail.')]
class DiffAppVersionsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'from' => ['sometimes', 'integer', 'min:1'],
            'to' => ['sometimes', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $maxNumber = (int) AppVersion::query()->where('app_id', $app->id)->max('version_number');
        if ($maxNumber === 0) {
            return Response::error("App '{$validated['app_slug']}' has no versions to diff.");
        }

        $toNumber = $validated['to'] ?? $maxNumber;
        $fromNumber = $validated['from'] ?? ($toNumber - 1);

        if ($fromNumber < 1) {
            return Response::error('Nothing precedes version 1 (the initial scaffold). Pass explicit `from`/`to` version numbers to diff a later pair.');
        }
        if ($fromNumber >= $toNumber) {
            return Response::error("`from` ({$fromNumber}) must be an earlier version than `to` ({$toNumber}).");
        }

        $fromVersion = $this->version($app->id, $fromNumber);
        $toVersion = $this->version($app->id, $toNumber);
        if ($fromVersion === null || $toVersion === null) {
            $missing = $fromVersion === null ? $fromNumber : $toNumber;

            return Response::error("Version {$missing} does not exist for this app (max is {$maxNumber}).");
        }

        $diff = app(ManifestDiffService::class)->diff(
            is_array($fromVersion->manifest) ? $fromVersion->manifest : [],
            is_array($toVersion->manifest) ? $toVersion->manifest : [],
        );

        return Response::json([
            'app_slug' => $app->slug,
            'from' => $this->versionRef($fromVersion),
            'to' => $this->versionRef($toVersion),
            ...$diff,
        ]);
    }

    private function version(string $appId, int $number): ?AppVersion
    {
        return AppVersion::query()
            ->where('app_id', $appId)
            ->where('version_number', $number)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function versionRef(AppVersion $version): array
    {
        return [
            'version_number' => $version->version_number,
            'change_summary' => $version->change_summary,
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the app.')->required(),
            'from' => $schema->integer()->description('Earlier version number (default: the version before `to`).'),
            'to' => $schema->integer()->description('Later version number (default: the current version).'),
        ];
    }
}
