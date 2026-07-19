<?php

namespace App\Services\Manifest;

use App\Enums\AppKind;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\User;
use App\Support\Html\LandingHtmlSanitizer;
use App\Support\Locale\PromptLanguage;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the lifecycle of an App's manifest: read the active version, create new
 * versions (full snapshot or via JSON Patch), and roll back to an earlier one.
 * Validation runs before any version is persisted; failure raises
 * InvalidManifestException so callers never see a half-applied state.
 */
class AppManifestService
{
    private const CACHE_TTL_SECONDS = 3600;

    /** Mirrors the manifest schema's maxLength on `description`. */
    private const MAX_DESCRIPTION_LENGTH = 500;

    public function __construct(
        private ManifestValidator $validator,
        private CacheRepository $cache,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveManifest(App $app): ?array
    {
        if ($app->current_version_id === null) {
            return null;
        }

        return $this->cache->remember(
            $this->cacheKey($app->id, $app->current_version_id),
            self::CACHE_TTL_SECONDS,
            function () use ($app): ?array {
                $version = AppVersion::query()
                    ->where('id', $app->current_version_id)
                    ->where('app_id', $app->id)
                    ->first();

                return $version?->manifest;
            },
        );
    }

    /**
     * The minimal valid manifest for a freshly-created App: empty objects/pages
     * and two default roles. Used to seed the first version on create.
     *
     * @return array<string, mixed>
     */
    public function initialManifest(App $app): array
    {
        $manifest = [
            'schema_version' => '1.0.0',
            'id' => $app->id,
            'slug' => $app->slug,
            'name' => $app->name,
            'version' => 1,
            'objects' => [],
            'pages' => [],
            'permissions' => [
                'roles' => [
                    ['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
                    ['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'user', 'name' => 'User', 'is_default' => true],
                ],
            ],
            'settings' => [
                // Language of the app's chrome (labels/POS/heuristics), derived from
                // the name + description so an English/Portuguese/French brief gets a
                // native app instead of Spanish. Undetermined falls back to es-MX,
                // the product default. Timezone/currency are a separate concern.
                'default_locale' => $this->deriveLocale($app),
                'default_timezone' => 'America/Mexico_City',
                'default_currency' => 'MXN',
            ],
        ];

        foreach (['description', 'icon', 'color'] as $optional) {
            if ($app->{$optional} !== null) {
                $manifest[$optional] = $app->{$optional};
            }
        }

        // The app's description doubles as the (deliberately rich, up to ~2000
        // char) scaffold prompt, but manifest.description is capped at 500. Clamp
        // the manifest copy so a long prompt doesn't fail validation on the very
        // first version; the full text stays on the App row and in the prompt.
        if (isset($manifest['description']) && mb_strlen($manifest['description']) > self::MAX_DESCRIPTION_LENGTH) {
            $manifest['description'] = rtrim(mb_substr($manifest['description'], 0, self::MAX_DESCRIPTION_LENGTH - 1)).'…';
        }

        // Seed the brand: a new app starts on the org's Brandbook (accent/font/
        // theme/logo) where it hasn't chosen otherwise. The runtime applies the
        // same brand as a live fallback, so this only persists the initial look.
        $organization = $app->organization;
        if ($organization !== null) {
            $manifest['settings'] = $organization->brandbook()->applyToAppSettings($manifest['settings']);
        }

        return $manifest;
    }

    /**
     * The app's chrome language as a region-qualified locale, detected from its
     * name + description (the description doubles as the scaffold brief, so it is
     * a rich language sample). Falls back to es-MX — the product default — when
     * the language can't be told, preserving prior behaviour for terse names.
     */
    private function deriveLocale(App $app): string
    {
        $sample = trim(((string) $app->name).' '.((string) $app->description));

        return match (PromptLanguage::detect($sample)) {
            'en' => 'en-US',
            'pt' => 'pt-BR',
            'fr' => 'fr-FR',
            default => 'es-MX',
        };
    }

    /**
     * Create a new version with a full manifest snapshot. Validates first;
     * throws InvalidManifestException if invalid. Atomically increments
     * version_number and sets it as current.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function createVersion(App $app, array $manifest, ?User $user = null, ?string $summary = null): AppVersion
    {
        // Sanitise bespoke `html`-block markup before it is validated or stored,
        // so the persisted manifest (what the runtime renders) can never carry a
        // <script>, an event handler or an inline style — the trust boundary
        // against a prompt-injected author. No-op for manifests without html blocks.
        $manifest = $this->sanitizeHtmlBlocks($manifest);

        $result = $this->validator->validate($manifest);
        if (! $result->valid) {
            throw new InvalidManifestException($result);
        }

        // Defensive bound: keep a runaway summary from bloating the row. The
        // column is text (no 255 limit), so a normal descriptive summary is kept
        // in full; this only trims pathologically long input.
        $summary = $summary === null ? null : Str::limit($summary, 2000, '');

        return DB::transaction(function () use ($app, $manifest, $user, $summary): AppVersion {
            // Re-read inside the transaction with FOR UPDATE so two concurrent
            // creators can't end up with the same version_number.
            $locked = App::query()->lockForUpdate()->find($app->id);
            $nextNumber = ((int) AppVersion::query()
                ->where('app_id', $locked->id)
                ->max('version_number')) + 1;

            $manifest['version'] = $nextNumber;

            $version = AppVersion::create([
                'app_id' => $locked->id,
                'organization_id' => $locked->organization_id,
                'version_number' => $nextNumber,
                'manifest' => $manifest,
                'created_by_user_id' => $user?->id,
                'change_summary' => $summary,
            ]);

            // Re-classify the product (App vs Dashboard) from the manifest's
            // content on every write, so the tag tracks what was actually built.
            $locked->update([
                'current_version_id' => $version->id,
                'kind' => AppKind::classify($manifest)->value,
            ]);

            return $version->refresh();
        });
    }

    /**
     * Run the LandingHtmlSanitizer over every `html` block's `content`, descending
     * through layout containers so a nested section is covered too. Returns the
     * manifest with sanitised markup; a manifest with no html blocks is unchanged.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function sanitizeHtmlBlocks(array $manifest): array
    {
        if (empty($manifest['pages']) || ! is_array($manifest['pages'])) {
            return $manifest;
        }

        $sanitizer = new LandingHtmlSanitizer;
        foreach ($manifest['pages'] as &$page) {
            if (is_array($page) && isset($page['blocks']) && is_array($page['blocks'])) {
                $page['blocks'] = $this->sanitizeBlockList($page['blocks'], $sanitizer);
            }
        }
        unset($page);

        return $manifest;
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    private function sanitizeBlockList(array $blocks, LandingHtmlSanitizer $sanitizer): array
    {
        foreach ($blocks as &$block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'html' && isset($block['content']) && is_string($block['content'])) {
                $block['content'] = $sanitizer->sanitize($block['content']);
            }
            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    $block[$key] = $this->sanitizeBlockList($block[$key], $sanitizer);
                }
            }
            foreach (['tabs', 'sections'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    foreach ($block[$key] as &$sub) {
                        if (is_array($sub) && isset($sub['blocks']) && is_array($sub['blocks'])) {
                            $sub['blocks'] = $this->sanitizeBlockList($sub['blocks'], $sanitizer);
                        }
                    }
                    unset($sub);
                }
            }
        }
        unset($block);

        return $blocks;
    }

    /**
     * Load the active manifest, apply an RFC 6902 JSON Patch, validate, and
     * persist the result as a new version. Returns the new version.
     *
     * @param  list<array<string, mixed>>  $jsonPatchOps  RFC 6902 ops
     */
    public function applyPatch(App $app, array $jsonPatchOps, ?User $user = null, ?string $summary = null): AppVersion
    {
        $current = $this->getActiveManifest($app);
        if ($current === null) {
            throw new \RuntimeException("App {$app->id} has no active manifest to patch.");
        }

        // Fill the ids the schema requires on added nodes when the caller omitted
        // them, so a patch can be written without hand-minting every id.
        $jsonPatchOps = ManifestIdFiller::fill(array_values($jsonPatchOps));

        $patched = $this->applyJsonPatch($current, $jsonPatchOps);

        return $this->createVersion($app, $patched, $user, $summary);
    }

    /**
     * Create a new version by copying the manifest of a prior version and
     * pointing current_version_id at it. Append-only — does not delete history.
     */
    public function rollbackTo(App $app, AppVersion $version, ?User $user = null): AppVersion
    {
        if ($version->app_id !== $app->id) {
            throw new \InvalidArgumentException('Version does not belong to this app.');
        }

        $summary = "Rollback to v{$version->version_number}";

        return $this->createVersion($app, $version->manifest, $user, $summary);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $ops
     * @return array<string, mixed>
     */
    private function applyJsonPatch(array $document, array $ops): array
    {
        return ManifestPatch::apply($document, $ops);
    }

    /**
     * Mirror the App model's identity (slug/name/description) onto its ACTIVE
     * version's manifest, in place. Used when an app is renamed from its first
     * builder prompt: the initial manifest baked the "Nueva app" placeholder, so
     * without this the manifest stays desynced and every version built on top of
     * it inherits the stale name/slug. Safe because the active version at rename
     * time is the fresh, empty initial one; later versions read the corrected
     * manifest via getActiveManifest and carry the right identity forward.
     */
    public function syncManifestIdentity(App $app): void
    {
        if ($app->current_version_id === null) {
            return;
        }
        $version = AppVersion::query()
            ->where('id', $app->current_version_id)
            ->where('app_id', $app->id)
            ->first();
        if ($version === null || ! is_array($version->manifest)) {
            return;
        }

        $manifest = $version->manifest;
        $manifest['slug'] = $app->slug;
        $manifest['name'] = $app->name;
        $description = trim((string) $app->description);
        if ($description !== '') {
            $manifest['description'] = mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH
                ? rtrim(mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH - 1)).'…'
                : $description;
        }

        $version->manifest = $manifest;
        $version->save();
        $this->cache->forget($this->cacheKey($app->id, $app->current_version_id));
    }

    private function cacheKey(string $appId, string $versionId): string
    {
        return "manifest:{$appId}:{$versionId}";
    }
}
