<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Manifest\AppAuditService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Full health check of a LIVE app across both layers: (1) the applied manifest, run through the same schema + semantic validator as validate_manifest (dangling field/relation/expression references, bad cardinality, …), and (2) the actual DATA — which nothing else audits — flagging dangling relation FKs (a link pointing at a deleted record), invalid single/multi-select values no longer in the field options, and orphaned records whose object was removed. Read-only and tenant-scoped. Returns a per-object breakdown with capped example rows plus a summary {ok, manifest_errors, data_issues}. Use it to answer "is this app actually sound?" after a build, a bad edit, or a data import.')]
class AuditAppTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        return Response::json(app(AppAuditService::class)->audit($app));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()
                ->description('Slug of the app to audit (from list_apps).'),
        ];
    }
}
