<?php

namespace App\Mcp\Tools\Build;

use App\Models\App;
use App\Models\User;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Shared resolution for the app-access MCP tools (list/assign/revoke roles):
 * load the app + its active manifest and confirm the caller administers it (the
 * same bypass set the runtime resolver and the HTTP access controller require —
 * sysadmin / org owner / app owner). Mirroring that gate keeps the MCP surface
 * exactly as privileged as the builder's Access panel.
 */
trait ManagesAppAccess
{
    /**
     * @return array{ok: true, app: App, manifest: array<string, mixed>}|array{ok: false, error: string}
     */
    protected function loadManageableApp(User $user, string $slug): array
    {
        try {
            $app = $this->resolveApp($slug, $user);
        } catch (ModelNotFoundException) {
            return ['ok' => false, 'error' => "No app named '{$slug}' is visible to you."];
        }

        $manifest = app(AppManifestService::class)->getActiveManifest($app);
        if ($manifest === null) {
            return ['ok' => false, 'error' => "App '{$app->slug}' has no published manifest yet."];
        }

        if (! app(AppAccessResolver::class)->resolve($app, $manifest, $user)->bypass) {
            return ['ok' => false, 'error' => 'Only an app or organization administrator can manage access.'];
        }

        return ['ok' => true, 'app' => $app, 'manifest' => $manifest];
    }
}
