<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The W3C web app manifest for ONE built app.
 *
 * Per app, not per platform, and that is the whole point: a technician installs
 * «Servicio Campo» on their phone and gets that app's name and icon on the home
 * screen, opening at its own start url. One shared "Sapiensly" icon for every
 * app an organization builds would make the install worthless the moment they
 * built a second one.
 *
 * Served under the app's own path so its scope covers the runtime and nothing
 * else — an installed app must not capture the admin panel.
 */
class AppWebManifestController extends Controller
{
    public function __construct(private AppManifestService $manifestService) {}

    public function __invoke(Request $request, string $appSlug): JsonResponse
    {
        $user = $request->user();

        $app = App::query()
            ->forAccountContext($user)
            ->where('slug', $appSlug)
            ->first();

        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        $manifest = $this->manifestService->getActiveManifest($app);
        $settings = $manifest['settings'] ?? [];

        $accent = (string) ($app->color ?: ($settings['accent'] ?? '#0059ff'));

        return response()->json([
            'name' => $app->name,
            // Home screens truncate hard; the short name is what actually shows.
            'short_name' => mb_substr($app->name, 0, 12),
            'description' => $app->description,
            'start_url' => "/r/{$app->slug}",
            // Scope decides what the installed window keeps: this app, never the
            // platform around it.
            'scope' => "/r/{$app->slug}",
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#0b1220',
            'theme_color' => $accent,
            'lang' => (string) ($settings['default_locale'] ?? 'en'),
            'icons' => $this->icons($settings),
        ])->header('Cache-Control', 'private, max-age=300');
    }

    /**
     * The icons an install uses.
     *
     * SVG, because that is what the Brandbook holds and it scales to every size
     * a launcher asks for. Some Android launchers still prefer a raster
     * `maskable` icon; when that becomes a complaint the fix is to render PNGs
     * at 192/512 from this same source, not to change what is stored.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array<string, string>>
     */
    private function icons(array $settings): array
    {
        $source = $settings['brand']['icon'] ?? $settings['brand']['logo'] ?? null;

        $icon = is_string($source) && $source !== '' ? $source : '/favicon.svg';

        return [[
            'src' => $icon,
            'sizes' => 'any',
            'type' => str_ends_with($icon, '.svg') ? 'image/svg+xml' : 'image/png',
            'purpose' => 'any',
        ]];
    }
}
