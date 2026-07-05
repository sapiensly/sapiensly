<?php

namespace App\Services\Express\Phases;

use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Models\PipelineRun;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;

/**
 * F-4: merge the suggestion with the semantic gate outputs, compile the page
 * through the shared dashboard compiler, enforce the lints, and apply it as a
 * version. From here the user has a live dashboard on screen; everything
 * after (verification, chat refinements) only raises the ceiling.
 */
class CompilePhase implements ExpressPhase
{
    public function __construct(
        private readonly AppScaffolder $scaffolder,
        private readonly AppManifestService $manifests,
    ) {}

    public function name(): string
    {
        return 'compile';
    }

    public function announce(ExpressContext $context): string
    {
        return '🏗️ Compilando y aplicando el dashboard…';
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        $manifest = $this->manifests->getActiveManifest($context->app->fresh());
        if (! is_array($manifest)) {
            throw new \RuntimeException('The app lost its active manifest mid-run.');
        }

        $spec = $context->spec ?? [];
        $overrides = is_array($context->semantic['overrides'] ?? null) ? $context->semantic['overrides'] : [];
        $voice = is_array($context->semantic['voice'] ?? null) ? $context->semantic['voice'] : [];
        $insights = $context->semantic['insights'] ?? ($spec['insights'] ?? []);

        $args = array_merge($spec, $overrides);
        $args['insights'] = $insights;
        if (trim((string) ($voice['title'] ?? '')) !== '') {
            $args['title'] = trim((string) $voice['title']);
        }
        if (trim((string) ($voice['purpose'] ?? '')) !== '') {
            $args['purpose'] = trim((string) $voice['purpose']);
        }

        $objectSlug = (string) ($spec['object_slug'] ?? '');
        $object = collect($manifest['objects'] ?? [])->firstWhere('slug', $objectSlug);
        if ($object === null) {
            throw new \RuntimeException("Primary object '{$objectSlug}' is missing from the manifest.");
        }

        $lang = AppScaffolder::langForLocale($manifest['settings']['default_locale'] ?? null);
        $takenSlugs = array_values(array_filter(array_map(fn ($p) => $p['slug'] ?? null, $manifest['pages'] ?? [])));
        $palette = ColorPalette::fromAccent((string) ($manifest['settings']['accent'] ?? OrganizationBrand::DEFAULT_ACCENT));

        $built = $this->scaffolder->buildDashboardFromSpec($args, $object, $takenSlugs, $palette, $lang);
        if (($built['ok'] ?? false) !== true) {
            // The semantic overrides were already judged, so a compile failure
            // here means the SUGGESTION itself broke — retry without overrides
            // before giving up.
            $built = $this->scaffolder->buildDashboardFromSpec(
                array_merge($spec, ['insights' => $insights]),
                $object, $takenSlugs, $palette, $lang,
            );
            if (($built['ok'] ?? false) !== true) {
                throw new \RuntimeException('Dashboard compile failed: '.json_encode($built['errors'] ?? [], JSON_UNESCAPED_UNICODE));
            }
            $context->note('Los ajustes del modelo no compilaron; se aplicó el spec sugerido.');
        }

        $lint = PlanDashboardTool::lint($built['purpose'], $built['plan_rows']);
        if (! $lint['ok']) {
            throw new \RuntimeException('Dashboard lints failed: '.implode(' · ', $lint['issues']));
        }

        $page = $built['page'];
        $version = $this->manifests->applyPatch(
            $context->app->fresh(),
            [['op' => 'add', 'path' => '/pages/-', 'value' => $page]],
            $context->user,
            "Agregué el dashboard «{$page['name']}» (Express)",
        );

        $context->page = [
            'slug' => $page['slug'],
            'path' => $page['path'],
            'name' => $page['name'],
            'version' => $version->version_number,
            'version_id' => $version->id,
        ];
    }
}
