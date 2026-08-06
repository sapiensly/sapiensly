<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\InvalidManifestException;
use App\Services\Manifest\ManifestPatch;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Apply a set of RFC 6902 JSON Patch operations to an app\'s manifest. The patch is validated and, if valid, saved as a new (reversible) app version. Read the manifest first to target the right paths. On success returns {applied:true, version_number, changed_paths:[{op, path, from?}]} — array appends ("/-") are resolved to the concrete index they landed at, so you can target follow-up patches without re-reading — plus {warnings} when the patch applied but built something that will do nothing (a button aimed at no page, a list you cannot open from); fix those or say what you could not complete before reporting success. If rejected, returns {applied:false, valid:false, errors:[{path, message, code, expected?, value?}], warnings} — the same structured detail as validate_manifest — so you can fix every error and retry.')]
class ProposeChangeTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'ops' => ['required', 'array', 'min:1'],
            'change_summary' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:200'],
        ], [
            'ops.required' => 'Provide at least one RFC 6902 operation, e.g. {"op":"add","path":"/objects/-","value":{...}}. One extension: {"op":"append","path","value"} concatenates onto a string value — use it to write/revise LONG strings (settings.custom_css) in chunks instead of resending the whole value in a replace.',
        ]);

        /** @var User $user */
        $user = $request->user();

        // Replay a prior successful apply for this key rather than creating a
        // duplicate version when a client retries after a timeout.
        if (($replay = $this->idempotentReplay($user, $validated['idempotency_key'] ?? null)) !== null) {
            return Response::json($replay);
        }

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $summary = $validated['change_summary'] ?? 'Patch via propose_change';
        $manifests = app(AppManifestService::class);
        // Snapshot the pre-patch manifest (cached) so we can resolve where each op
        // landed — array appends ('/-') become concrete indices in the response.
        $before = $manifests->getActiveManifest($app) ?? [];

        try {
            $version = $manifests->applyPatch(
                $app,
                $validated['ops'],
                $user,
                $summary,
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

        $payload = [
            'applied' => true,
            'app_slug' => $app->slug,
            'version_number' => $version->version_number,
            'change_summary' => $version->change_summary,
            // Where each op landed, with '/-' appends resolved to the concrete
            // index — so you can target follow-up patches without re-reading.
            'changed_paths' => ManifestPatch::changedPaths($validated['ops'], $before),
        ];

        // The design lint, on the path an EXTERNAL agent builds through.
        //
        // The builder's own propose_change has returned these since the rules
        // were written; this one returned them only when the patch was
        // REJECTED, so a patch that applied with warnings came back as a bare
        // success. Measured: an app built here whose «Abrir» button pointed at
        // a page that does not exist, and whose central object could be listed
        // but never opened, was answered `{"applied":true}` and nothing else —
        // both warnings sat waiting in `audit_app` for a caller with no reason
        // to suspect it needed to ask. Same validator, same codes, same
        // instruction not to claim success on top of them.
        $warnings = app(ManifestValidator::class)->validate($version->manifest)->warningsArray();
        if ($warnings !== []) {
            $payload['warnings'] = $warnings;
            $payload['message'] = 'Applied, but some things are wired to do nothing (see `warnings`). Do NOT report success until each is fixed or you have told the user exactly what you could not complete.';
        }

        $this->rememberIdempotent($user, $validated['idempotency_key'] ?? null, $payload);

        return Response::json($payload);
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
                ->description('Optional short human-readable summary of the change (stored on the version).'),
            'idempotency_key' => $schema->string()
                ->description('Optional. A unique client token; retrying with the same key replays the original result instead of applying the patch again (safe retry after a timeout).'),
        ];
    }
}
