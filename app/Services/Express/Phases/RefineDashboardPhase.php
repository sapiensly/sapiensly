<?php

namespace App\Services\Express\Phases;

use App\Models\PipelineRun;
use App\Services\Express\ExpressContext;

/**
 * F-4b: fold the semantic gate outputs into the ALREADY-BANKED deterministic
 * dashboard, replacing it in place with a refined version. Runs only when the
 * gates actually shaped the board ({@see ExpressContext::$semanticEnriched});
 * when every gate fell back (a hung/absent model), the deterministic page from
 * {@see CompilePhase} is the final one and this is a silent no-op — no
 * redundant identical version. The page keeps its original slug/path so the
 * runtime URL never changes across the refine.
 */
class RefineDashboardPhase extends CompilePhase
{
    public function name(): string
    {
        return 'refine_dashboard';
    }

    public function announce(ExpressContext $context): string
    {
        // Silent unless there's real enrichment to apply (progress() drops '').
        return $context->semanticEnriched && $context->page !== null
            ? 'Refinando el dashboard con los insights…'
            : '';
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        // Nothing the model contributed → the banked deterministic page stands.
        if (! $context->semanticEnriched || $context->page === null) {
            return;
        }

        $manifest = $this->manifests->getActiveManifest($context->app->fresh());
        if (! is_array($manifest)) {
            return; // the baseline page is already live; never fail the run here
        }

        // A refined compile that breaks must NOT discard the working baseline —
        // swallow it and leave the deterministic page as the final one.
        try {
            $page = $this->buildPage($context, $manifest);
        } catch (\Throwable) {
            $context->note('El refinamiento no compiló; se conservó el dashboard base.');

            return;
        }

        $this->applyPage($context, $page);
    }

    /**
     * REPLACE the already-banked page in place, preserving its slug/path so the
     * runtime URL is stable, and bank it as a new (refined) version.
     *
     * @param  array<string, mixed>  $page
     */
    protected function applyPage(ExpressContext $context, array $page): void
    {
        // Keep the URL identity of the baseline page — the compiler may have
        // derived a fresh slug from an enriched title; the page is the same one.
        $page['slug'] = $context->page['slug'];
        $page['path'] = $context->page['path'];

        $manifest = $this->manifests->getActiveManifest($context->app->fresh());
        $index = collect($manifest['pages'] ?? [])->search(
            fn ($p) => is_array($p) && ($p['slug'] ?? null) === $context->page['slug'],
        );
        if ($index === false) {
            return; // baseline vanished from under us; leave what's live
        }

        $version = $this->manifests->applyPatch(
            $context->app->fresh(),
            [['op' => 'replace', 'path' => '/pages/'.$index, 'value' => $page]],
            $context->user,
            "Refiné el dashboard «{$page['name']}» con los insights (Express)",
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
