<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\InvalidManifestException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Apply a set of RFC 6902 JSON Patch operations to an app\'s manifest. The patch is validated and, if valid, saved as a new (reversible) app version. Read the manifest first to target the right paths. If rejected, returns {applied:false, valid:false, errors:[{path, message, code, expected?, value?}], warnings} — the same structured detail as validate_manifest — so you can fix every error and retry.')]
class ProposeChangeTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'ops' => ['required', 'array', 'min:1'],
            'change_summary' => ['required', 'string', 'max:500'],
        ], [
            'ops.required' => 'Provide at least one RFC 6902 operation, e.g. {"op":"add","path":"/objects/-","value":{...}}.',
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        try {
            $version = app(AppManifestService::class)->applyPatch(
                $app,
                $validated['ops'],
                $user,
                $validated['change_summary'],
            );
        } catch (InvalidManifestException $e) {
            // The patch applied but the result failed validation. Return the SAME
            // structured {errors, warnings} contract as validate_manifest — each
            // error with {path, message, code, expected?, value?} — so the model
            // can self-correct from machine-readable detail instead of parsing a
            // flattened, truncated string.
            return Response::json([
                'applied' => false,
                'valid' => false,
                'errors' => $e->result->errorsArray(),
                'warnings' => $e->result->warningsArray(),
            ]);
        } catch (\Throwable $e) {
            // A malformed patch (bad path/op) never reaches the validator — report
            // it the same way validate_manifest's patch mode does.
            return Response::json([
                'applied' => false,
                'valid' => false,
                'errors' => [['path' => 'ops', 'message' => $e->getMessage(), 'code' => 'patch_failed']],
                'warnings' => [],
            ]);
        }

        return Response::json([
            'applied' => true,
            'app_slug' => $app->slug,
            'version_number' => $version->version_number,
            'change_summary' => $version->change_summary,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()
                ->description('The slug of the app to modify.')
                ->required(),
            'ops' => $schema->array()
                ->description('RFC 6902 JSON Patch operations to apply to the active manifest.')
                ->required(),
            'change_summary' => $schema->string()
                ->description('A short human-readable summary of the change (stored on the version).')
                ->required(),
        ];
    }
}
