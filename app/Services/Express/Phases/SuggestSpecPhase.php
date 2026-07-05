<?php

namespace App\Services\Express\Phases;

use App\Models\PipelineRun;
use App\Services\Express\ComputedFactsBuilder;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\DashboardSpecSuggester;

/**
 * F-3: derive the complete spec from the PRIMARY object's schema (the one
 * with a temporal axis and the most fields — the richest canvas) and compute
 * the real facts the insight gate will narrate. Zero model.
 */
class SuggestSpecPhase implements ExpressPhase
{
    public function __construct(
        private readonly DashboardSpecSuggester $suggester,
        private readonly ComputedFactsBuilder $facts,
        private readonly AppManifestService $manifests,
    ) {}

    public function name(): string
    {
        return 'suggest_spec';
    }

    public function announce(ExpressContext $context): string
    {
        return '📐 Derivando el spec del dashboard desde los datos…';
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        $primary = $this->pickPrimary($context->objects);
        if ($primary === null) {
            throw new \RuntimeException('No connected object available to build the dashboard from.');
        }

        $manifest = $this->manifests->getActiveManifest($context->app->fresh());
        $lang = AppScaffolder::langForLocale($manifest['settings']['default_locale'] ?? null);

        $context->spec = $this->suggester->suggest($primary, $lang) + ['object_slug' => $primary['slug']];
        $context->facts = $this->facts->build($primary, $context->rowsByObject[$primary['id']] ?? []);
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, mixed>|null
     */
    private function pickPrimary(array $objects): ?array
    {
        return collect($objects)
            ->sortByDesc(function (array $o): int {
                $fields = collect($o['fields'] ?? []);
                $hasDate = $fields->contains(
                    fn ($f) => in_array($f['type'] ?? '', ['date', 'datetime'], true)
                );
                // A categorical axis is worth more than raw field count: an
                // object with categories yields breakdowns; a numbers-only
                // comparison table yields a chartless board.
                $hasCategory = $fields->contains(
                    fn ($f) => in_array($f['type'] ?? '', ['string', 'single_select'], true)
                );

                return ($hasDate ? 100 : 0) + ($hasCategory ? 50 : 0) + $fields->count();
            })
            ->first();
    }
}
