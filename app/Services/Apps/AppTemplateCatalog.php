<?php

namespace App\Services\Apps;

use App\Models\App;
use App\Models\AppTemplate;
use App\Models\User;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * The starter apps an account can begin from instead of an empty page, from two
 * places that stay deliberately different:
 *
 *  - BUILT-IN templates are package FILES shipped with the code. Being files is
 *    the point: a template can never rely on something the portable format does
 *    not support, because it travels through the same door as any uploaded
 *    package, and a broken one surfaces as a failed import in development
 *    rather than as a shape only the catalog can produce.
 *  - AN ORGANIZATION'S OWN live in a tenant table under RLS, because a saved
 *    template holds a whole manifest — and possibly rows — from an app someone
 *    built, and must be invisible to every other tenant.
 *
 * Both install through the same {@see AppPackage::import()}. Nothing here has a
 * shortcut the format does not have.
 */
class AppTemplateCatalog
{
    private const DIRECTORY = 'app-templates';

    /**
     * Every template, with just enough to render a picker.
     *
     * @return list<array{slug: string, name: string, description: string, icon: string|null, kind: string, objects: int, pages: int}>
     */
    public function all(): array
    {
        $templates = [];

        foreach ($this->files() as $path) {
            $package = $this->read($path);
            if ($package === null) {
                continue;
            }

            $templates[] = [
                'slug' => basename($path, '.json'),
                'name' => (string) ($package['app']['name'] ?? basename($path, '.json')),
                'description' => (string) ($package['app']['description'] ?? ''),
                'icon' => $package['app']['icon'] ?? null,
                'kind' => (string) ($package['app']['kind'] ?? 'app'),
                'objects' => count($package['manifest']['objects'] ?? []),
                'pages' => count($package['manifest']['pages'] ?? []),
                'source' => 'builtin',
                'records' => is_array($package['records'] ?? null),
            ];
        }

        usort($templates, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        // The organization's own come FIRST: they are the ones this account
        // deliberately made, and the built-ins are the fallback for a new one.
        $own = AppTemplate::query()
            ->latest('created_at')
            ->get()
            ->map(fn (AppTemplate $t): array => $t->toCatalogEntry())
            ->all();

        return [...$own, ...$templates];
    }

    /**
     * Save an app as a template for this organization.
     *
     * The whole package is snapshotted rather than referenced, so the template
     * survives the app being rewritten or deleted. The portability scrub runs on
     * the way in as usual, so a template can never carry a connected source or a
     * workflow bound to an integration.
     *
     *
     * @throws InvalidArgumentException when the app has nothing to export
     */
    public function saveFrom(
        App $app,
        User $user,
        ?string $name = null,
        ?string $description = null,
        bool $includeRecords = false,
    ): AppTemplate {
        $package = app(AppPackage::class)->export($app, $includeRecords);

        return AppTemplate::create([
            'organization_id' => $app->organization_id,
            'name' => trim((string) ($name ?? $app->name)) ?: $app->name,
            'description' => $description ?? $app->description,
            'icon' => $app->icon,
            'kind' => $app->kind->value,
            'source_app_id' => $app->id,
            'package' => $package,
        ]);
    }

    /**
     * One template's package, or null when the slug names none.
     *
     * @return array<string, mixed>|null
     */
    public function package(string $slug): ?array
    {
        // An organization's own are addressed by their id. RLS scopes the
        // lookup, so an id from another tenant simply finds nothing.
        if (str_starts_with($slug, 'tpl_')) {
            $template = AppTemplate::find($slug);

            return $template?->package;
        }

        // A built-in reaches this from a request and becomes a PATH. Anything
        // that is not a plain name is refused rather than sanitised, so no
        // traversal is even attempted.
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $slug) !== 1) {
            return null;
        }

        return $this->read($this->path($slug.'.json'));
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $directory = $this->path('');
        if (! is_dir($directory)) {
            return [];
        }

        return array_values(array_map(
            fn ($file): string => $file->getPathname(),
            File::files($directory),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) && ($decoded['format'] ?? null) === AppPackage::FORMAT
            ? $decoded
            : null;
    }

    private function path(string $file): string
    {
        return resource_path(self::DIRECTORY.($file === '' ? '' : '/'.$file));
    }
}
