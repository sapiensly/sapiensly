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
        return 'Derivando el spec del dashboard desde los datos…';
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        $ordered = $this->orderByRichness($context->objects);
        if ($ordered === []) {
            throw new \RuntimeException('No connected object available to build the dashboard from.');
        }
        $primary = $ordered[0];

        $manifest = $this->manifests->getActiveManifest($context->app->fresh());
        $lang = AppScaffolder::langForLocale($manifest['settings']['default_locale'] ?? null);

        // Every acquired object earns its place on the board: the primary
        // drives the skeleton, the rest contribute their trend/breakdown/KPI
        // tagged with object_slug (before this, 3 of 4 acquired objects were
        // paid for and never rendered).
        $context->spec = $this->suggester->suggestMulti($ordered, $lang, $context->rowsByObject) + ['object_slug' => $primary['slug']];
        $context->facts = $this->facts->build($primary, $context->rowsByObject[$primary['id']] ?? []);

        // Compact facts per contributing secondary, so the insight gate can
        // narrate THEIR numbers too (name, volume, sums — not the full drill).
        $secondaryFacts = [];
        foreach (array_slice($ordered, 1) as $obj) {
            $rows = $context->rowsByObject[$obj['id']] ?? [];
            if ($rows === []) {
                continue;
            }
            $secondaryFacts[] = array_intersect_key(
                $this->facts->build($obj, $rows),
                array_flip(['object', 'row_count', 'numeric', 'rates']),
            );
        }
        if ($secondaryFacts !== []) {
            $context->facts['objetos_secundarios'] = $secondaryFacts;
        }

        // Joined story: when several objects carry a time axis, the insights
        // can point at the same week from different angles.
        $cross = $this->facts->crossFacts($context->objects, $context->rowsByObject);
        if ($cross !== []) {
            $context->facts['cross'] = $cross;
        }
    }

    /**
     * Primary-first ordering: date + categorical axes make the richest
     * canvas; the rest follow in descending usefulness.
     *
     * @param  list<array<string, mixed>>  $objects
     * @return list<array<string, mixed>>
     */
    private function orderByRichness(array $objects): array
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
            ->values()->all();
    }
}
