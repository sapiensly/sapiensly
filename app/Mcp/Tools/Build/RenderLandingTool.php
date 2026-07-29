<?php

namespace App\Mcp\Tools\Build;

use App\Enums\AppKind;
use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Landing\HeadlessLandingShot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Render a LANDING to a real screenshot and return the image — the eyes a headless/external agent otherwise lacks. Drives the landing in headless Chrome (full applied design: html sections + custom_css + web fonts, prefers-reduced-motion forced so every scroll-reveal is at its final state) and returns a full-page JPEG plus metadata. Use it to SEE what you authored via propose_change before (and instead of blindly trusting) critique_landing_design: author → render → inspect the pixels → fix → repeat. Works on a draft too (no publish needed). On a MULTILINGUAL landing pass `language` (a code from settings.languages) to see that translation — otherwise you only ever review the default and a translation ships unseen. Returns an error (not a broken image) when no headless browser is available in the environment.')]
class RenderLandingTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'language' => ['sometimes', 'string', 'max:12'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        if ($app->kind !== AppKind::Landing) {
            return Response::error("'{$app->slug}' is not a landing (settings.surface must be \"landing\"). render_landing only shoots landing surfaces.");
        }

        $shots = app(HeadlessLandingShot::class);
        $shot = $shots->capture($app, $user, (string) ($validated['language'] ?? ''));

        if ($shot === null) {
            return Response::error('Could not render the landing — no headless browser answered (Chromium/puppeteer missing, or the render timed out). The design can still be authored/validated via propose_change + validate_manifest; pixel review needs a headless renderer in this environment.');
        }

        try {
            $bytes = $shot->content();
        } finally {
            $shots->cleanup($shot);
        }

        return Response::make([
            Response::image($bytes, 'image/jpeg'),
            Response::json([
                'app_slug' => $app->slug,
                'kind' => $app->kind->value,
                'published' => $app->published_at !== null,
                'language' => (string) ($validated['language'] ?? ''),
                'note' => 'Full-page screenshot of the current applied landing (reduced-motion, fonts loaded).',
            ]),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()
                ->description('Slug of the landing app to render (from list_apps).')
                ->required(),
            'language' => $schema->string()
                ->description('For a MULTILINGUAL landing, the language code to render (one of settings.languages). Omit for the default. Without this you only ever review the default language, and a translation ships unseen.'),
        ];
    }
}
