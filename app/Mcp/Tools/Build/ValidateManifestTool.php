<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestPatch;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Dry-run validation (schema + semantic rules) WITHOUT applying. Two modes: pass `manifest` to validate a complete draft, OR pass `app_slug` + `ops` (RFC 6902) to validate a proposed change against the app\'s live manifest — the easy way to check a workflow/page/object change before propose_change. Returns the exact errors and warnings to fix.')]
class ValidateManifestTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'manifest' => ['sometimes', 'array'],
            'app_slug' => ['sometimes', 'string'],
            'ops' => ['sometimes', 'array'],
        ]);

        $manifest = $validated['manifest'] ?? null;

        // Patch mode: apply the ops to the live manifest first, then validate the
        // result — so the model can check a change exactly as propose_change would.
        if ($manifest === null && isset($validated['app_slug'], $validated['ops'])) {
            /** @var User $user */
            $user = $request->user();

            try {
                $app = $this->resolveApp($validated['app_slug'], $user);
            } catch (ModelNotFoundException) {
                return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
            }

            $current = app(AppManifestService::class)->getActiveManifest($app);
            if ($current === null) {
                return Response::error("App '{$validated['app_slug']}' has no active manifest to patch.");
            }

            try {
                $manifest = ManifestPatch::apply($current, $validated['ops']);
            } catch (\Throwable $e) {
                // A malformed patch (bad path/op) never reaches the validator — report it as the error.
                return Response::json([
                    'valid' => false,
                    'errors' => [['path' => 'ops', 'message' => $e->getMessage(), 'code' => 'patch_failed']],
                    'warnings' => [],
                ]);
            }
        }

        if ($manifest === null) {
            return Response::error('Provide either `manifest` (a full draft) or `app_slug` + `ops` (a change to dry-run).');
        }

        $result = app(ManifestValidator::class)->validate($manifest);

        return Response::json([
            'valid' => $result->valid,
            'errors' => $result->errorsArray(),
            'warnings' => $result->warningsArray(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'manifest' => $schema->object()->description('A full draft manifest to validate. Omit when using app_slug + ops.'),
            'app_slug' => $schema->string()->description('App whose live manifest the `ops` are validated against (patch mode).'),
            'ops' => $schema->array()->description('RFC 6902 JSON Patch operations to dry-run against the app\'s live manifest.'),
        ];
    }
}
