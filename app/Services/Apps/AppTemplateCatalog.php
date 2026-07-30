<?php

namespace App\Services\Apps;

use Illuminate\Support\Facades\File;

/**
 * The starter apps a new account can begin from instead of an empty page.
 *
 * A template is just an app PACKAGE on disk, and it is installed through the
 * same {@see AppPackage::import()} every uploaded package takes. That is the
 * point of shipping them as files rather than as a special code path: a
 * template can never rely on something the portable format does not support,
 * because it travels through the same door — and a broken template surfaces as
 * a failed import in development, not as a shape only the catalog can produce.
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
            ];
        }

        usort($templates, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $templates;
    }

    /**
     * One template's package, or null when the slug names none.
     *
     * @return array<string, mixed>|null
     */
    public function package(string $slug): ?array
    {
        // The slug reaches this from a request, and it becomes a path. Anything
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
