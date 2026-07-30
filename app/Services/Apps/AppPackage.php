<?php

namespace App\Services\Apps;

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Support\Apps\AppNaming;
use App\Support\Apps\ManifestIdRemap;
use InvalidArgumentException;

/**
 * An app as a file: export it, hand it to someone else, import it there.
 *
 * The whole difficulty is that a manifest is not self-contained. It points at
 * things that live outside it and outside the tenant — a connected object needs
 * an integration, an `agent.invoke` step needs an agent, a landing can bind a
 * chatbot. Carrying those ids across would produce an app that validates,
 * installs, and then fails at the first run against a reference that means
 * nothing in the new tenant, or worse means something.
 *
 * So the package keeps everything that can work anywhere and drops — LOUDLY —
 * everything that cannot. The policy per kind of dependency:
 *
 *  - A CONNECTED object becomes a plain internal object. Its fields, and every
 *    page and block built on them, survive; only the live source is lost. The
 *    alternative (dropping the object) would take half the app with it.
 *  - A WORKFLOW touching an integration, agent, tool or channel is dropped
 *    whole. Dropping only the offending step would leave a workflow that still
 *    runs and silently does nothing, which is worse than one that is absent.
 *  - A CHATBOT binding and any brand asset URL are removed; the importing org's
 *    own Brandbook fills the gap at runtime.
 *
 * Every removal is reported, in the words someone re-wiring the app needs.
 */
class AppPackage
{
    public const FORMAT = 'sapiensly.app-package';

    public const FORMAT_VERSION = 1;

    public function __construct(
        private readonly AppManifestService $manifests,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the app has nothing to export
     */
    public function export(App $app): array
    {
        $manifest = $this->manifests->getActiveManifest($app);
        if ($manifest === null) {
            throw new InvalidArgumentException("'{$app->slug}' has no published version to export.");
        }

        $notes = [];
        $manifest = $this->scrub($manifest, $notes);

        // Identity belongs to the installation, not the package: the importing
        // tenant mints its own id and slug.
        unset($manifest['id'], $manifest['slug'], $manifest['created_at'], $manifest['updated_at']);

        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'app' => [
                'name' => $app->name,
                'description' => $app->description,
                'icon' => $app->icon,
                'color' => $app->color,
                'kind' => $app->kind->value,
            ],
            'manifest' => $manifest,
            'portability' => ['removed' => $notes],
        ];
    }

    /**
     * Install a package as a NEW app owned by $user.
     *
     * Every id in the manifest is remapped, so two apps installed from the same
     * package share no identifiers — a later export of either is independent,
     * and a stray cross-reference cannot resolve into a sibling.
     *
     * @param  array<string, mixed>  $package
     * @return array{app: App, notes: list<string>}
     *
     * @throws InvalidArgumentException when the package is not one of ours
     */
    public function import(array $package, User $user, ?string $name = null): array
    {
        if (($package['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('That file is not a Sapiensly app package.');
        }
        if ((int) ($package['format_version'] ?? 0) > self::FORMAT_VERSION) {
            throw new InvalidArgumentException(
                'That package was made by a newer version of Sapiensly and cannot be installed here.',
            );
        }

        $manifest = $package['manifest'] ?? null;
        if (! is_array($manifest)) {
            throw new InvalidArgumentException('That package carries no manifest.');
        }

        // Re-scrub on the way IN as well. A package can be hand-edited, and a
        // foreign integration id smuggled into one must not become a live
        // reference just because it arrived in a file.
        $notes = [];
        $manifest = $this->scrub($manifest, $notes);
        $manifest = ManifestIdRemap::apply($manifest);

        $appName = trim((string) ($name ?? ($package['app']['name'] ?? 'App importada'))) ?: 'App importada';
        $slug = AppNaming::uniqueSlug($appName, $user->organization_id);

        $app = App::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'slug' => $slug,
            'name' => $appName,
            'description' => $package['app']['description'] ?? null,
            'icon' => $package['app']['icon'] ?? null,
            'color' => $package['app']['color'] ?? null,
            'visibility' => 'organization',
        ]);

        $manifest['id'] = $app->id;
        $manifest['slug'] = $slug;
        $manifest['name'] = $appName;
        $manifest['version'] = 1;

        $this->manifests->createVersion($app, $manifest, $user, 'Imported from a package');

        return ['app' => $app->refresh(), 'notes' => $notes];
    }

    /**
     * Copy an app within the same tenant — export and import in one step, so a
     * duplicate is built by exactly the path an installed package takes and
     * cannot drift from it.
     *
     * @return array{app: App, notes: list<string>}
     */
    public function duplicate(App $app, User $user, ?string $name = null): array
    {
        return $this->import(
            $this->export($app),
            $user,
            $name ?? $app->name.' (copia)',
        );
    }

    /**
     * Strip everything that cannot travel, recording why.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    private function scrub(array $manifest, array &$notes): array
    {
        foreach ($manifest['objects'] ?? [] as $i => $object) {
            if (($object['source']['type'] ?? null) !== 'connected') {
                continue;
            }
            // Keep the shape, lose the live wire. Everything built on this
            // object's fields keeps working; it just holds no rows until the
            // new owner reconnects a source.
            unset($manifest['objects'][$i]['source']);
            $notes[] = "The object «{$object['name']}» read live from a connected system. Its fields were kept, but it arrives empty — reconnect a source to fill it.";
        }
        $manifest['objects'] = array_values($manifest['objects'] ?? []);

        $keptWorkflows = [];
        foreach ($manifest['workflows'] ?? [] as $workflow) {
            $reason = $this->externalDependency($workflow);
            if ($reason === null) {
                $keptWorkflows[] = $workflow;

                continue;
            }
            $notes[] = "The workflow «{$workflow['name']}» was removed: it {$reason}, which does not exist in another account. Rebuild it after connecting the equivalent here.";
        }
        if (($manifest['workflows'] ?? []) !== []) {
            $manifest['workflows'] = $keptWorkflows;
        }

        if (isset($manifest['settings']['chatbot'])) {
            unset($manifest['settings']['chatbot']);
            $notes[] = 'The chatbot this page carried was removed — bind one of your own after importing.';
        }

        // A brand asset URL points into the exporting organization's storage.
        // The importing org's Brandbook fills these in at runtime anyway.
        foreach (['logo', 'logo_dark', 'icon'] as $key) {
            $value = $manifest['settings']['brand'][$key] ?? null;
            if (is_string($value) && str_contains($value, '/brand-asset/')) {
                unset($manifest['settings']['brand'][$key]);
                $notes[] = "A brand image ({$key}) belonged to the exporting organization and was removed; your own Brandbook applies instead.";
            }
        }

        return $manifest;
    }

    /**
     * Why a workflow cannot travel, in plain words — or null when it can.
     *
     * @param  array<string, mixed>  $workflow
     */
    private function externalDependency(array $workflow): ?string
    {
        $trigger = $workflow['trigger'] ?? [];
        foreach (['integration_id' => 'is triggered by an external system', 'channel_id' => 'is triggered by a messaging channel', 'tool_id' => 'polls a connected tool'] as $key => $phrase) {
            if (! empty($trigger[$key])) {
                return $phrase;
            }
        }

        return $this->stepDependency($workflow['steps'] ?? []);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function stepDependency(array $steps): ?string
    {
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            if (! empty($step['agent_id'])) {
                return 'invokes a configured agent';
            }
            if (! empty($step['tool_id'])) {
                return 'calls a connected system';
            }
            if (! empty($step['integration_id'])) {
                return 'depends on an integration';
            }

            // branch and foreach nest their own steps.
            foreach (['steps', 'default_steps'] as $key) {
                if (isset($step[$key]) && is_array($step[$key])) {
                    $nested = $this->stepDependency($step[$key]);
                    if ($nested !== null) {
                        return $nested;
                    }
                }
            }
            foreach ($step['cases'] ?? [] as $case) {
                $nested = $this->stepDependency($case['steps'] ?? []);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
