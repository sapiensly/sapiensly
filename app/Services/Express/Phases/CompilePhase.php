<?php

namespace App\Services\Express\Phases;

use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Models\PipelineRun;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\InvalidManifestException;
use App\Services\Manifest\ManifestValidationError;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Support\Str;

/**
 * F-4: compile the dashboard through the shared compiler, enforce the lints,
 * and apply it as a version. This runs BEFORE the semantic gates so the user
 * gets a DETERMINISTIC dashboard banked first — whatever happens during the
 * model enrichment that follows (a hung provider, an OOM, a deploy restart)
 * can never cost the page. The gates then enrich in place ({@see
 * RefineDashboardPhase}); everything after only raises the ceiling.
 */
class CompilePhase implements ExpressPhase
{
    public function __construct(
        protected readonly AppScaffolder $scaffolder,
        protected readonly AppManifestService $manifests,
    ) {}

    public function name(): string
    {
        return 'compile';
    }

    public function announce(ExpressContext $context): string
    {
        return 'Compilando y aplicando el dashboard…';
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        $manifest = $this->manifests->getActiveManifest($context->app->fresh());
        if (! is_array($manifest)) {
            throw new \RuntimeException('The app lost its active manifest mid-run.');
        }

        $page = $this->buildPage($context, $manifest);
        $this->applyPage($context, $page);
    }

    /**
     * Merge the spec with any semantic gate outputs present and compile+lint
     * the page. On the first (pre-gate) pass $context->semantic is empty, so
     * this yields the deterministic dashboard; on a refine pass it folds in the
     * model's overrides/voice/insights. Throws when neither the merged nor the
     * bare suggestion compiles.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    protected function buildPage(ExpressContext $context, array $manifest): array
    {
        $spec = $context->spec ?? [];
        $overrides = is_array($context->semantic['overrides'] ?? null) ? $context->semantic['overrides'] : [];
        $voice = is_array($context->semantic['voice'] ?? null) ? $context->semantic['voice'] : [];
        $insights = $context->semantic['insights'] ?? ($spec['insights'] ?? []);

        $args = array_merge($spec, $overrides);
        // Model-authored strings get clamped to the manifest's limits — a slow
        // model once returned a 1,207-char paragraph AS the title, failing the
        // whole compile against page name maxLength 100.
        $args['insights'] = array_map(function (array $card): array {
            $card['title'] = Str::limit(trim((string) ($card['title'] ?? '')), 120, '…');
            if (isset($card['body'])) {
                $card['body'] = Str::limit(trim((string) $card['body']), 600, '…');
            }

            return $card;
        }, array_values(array_filter($insights, 'is_array')));
        if (trim((string) ($voice['title'] ?? '')) !== '') {
            $args['title'] = $this->clampLine((string) $voice['title'], 95);
        }
        if (trim((string) ($voice['purpose'] ?? '')) !== '') {
            $args['purpose'] = $this->clampLine((string) $voice['purpose'], 300);
        }

        $objectSlug = (string) ($spec['object_slug'] ?? '');
        $object = collect($manifest['objects'] ?? [])->firstWhere('slug', $objectSlug);
        if ($object === null) {
            throw new \RuntimeException("Primary object '{$objectSlug}' is missing from the manifest.");
        }

        $lang = AppScaffolder::langForLocale($manifest['settings']['default_locale'] ?? null);
        $takenSlugs = array_values(array_filter(array_map(fn ($p) => $p['slug'] ?? null, $manifest['pages'] ?? [])));
        $palette = ColorPalette::fromAccent((string) ($manifest['settings']['accent'] ?? OrganizationBrand::DEFAULT_ACCENT));
        // Multi-object boards: any other manifest object is addressable per
        // kpi/chart via object_slug.
        $extraObjects = collect($manifest['objects'] ?? [])
            ->filter(fn ($o) => is_array($o) && ($o['slug'] ?? null) !== $objectSlug)
            ->values()->all();

        $built = $this->scaffolder->buildDashboardFromSpec($args, $object, $takenSlugs, $palette, $lang, $extraObjects);
        if (($built['ok'] ?? false) !== true) {
            // The semantic overrides were already judged, so a compile failure
            // here means the SUGGESTION itself broke — retry without overrides
            // before giving up.
            $built = $this->scaffolder->buildDashboardFromSpec(
                array_merge($spec, ['insights' => $insights]),
                $object, $takenSlugs, $palette, $lang, $extraObjects,
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

        foreach ($built['dropped_charts'] ?? [] as $droppedChart) {
            $context->note('Gráfica descartada al compilar: '.$droppedChart);
        }

        return $built['page'];
    }

    /**
     * Bank the freshly built page as a new version — appended to the manifest —
     * and record its identity on the context. {@see RefineDashboardPhase}
     * overrides this to REPLACE the already-banked page in place.
     *
     * @param  array<string, mixed>  $page
     */
    protected function applyPage(ExpressContext $context, array $page): void
    {
        try {
            $version = $this->manifests->applyPatch(
                $context->app->fresh(),
                [['op' => 'add', 'path' => '/pages/-', 'value' => $page]],
                $context->user,
                "Agregué el dashboard «{$page['name']}» (Express)",
            );
        } catch (InvalidManifestException $e) {
            // Never let a single unrepresentable block cost the whole board. The
            // suggester should not emit one (see DashboardSpecSuggester::demoteRateKpis),
            // but if a rule we can't foresee rejects a block, drop just that block
            // (or KPI) and bank the rest instead of failing the build outright.
            $repaired = $this->repairPage($page, $e->result->errors);
            if ($repaired === null) {
                throw $e;
            }
            $context->note('Se omitieron KPIs que la fuente no puede expresar como una tasa honesta; el resto del dashboard se aplicó.');
            $page = $repaired;
            $version = $this->manifests->applyPatch(
                $context->app->fresh(),
                [['op' => 'add', 'path' => '/pages/-', 'value' => $page]],
                $context->user,
                "Agregué el dashboard «{$page['name']}» (Express, con KPIs omitidos)",
            );
        }

        $context->page = [
            'slug' => $page['slug'],
            'path' => $page['path'],
            'name' => $page['name'],
            'version' => $version->version_number,
            'version_id' => $version->id,
        ];
    }

    /**
     * Remove exactly the blocks/KPIs the validator rejected from a page so the
     * rest can still be banked. Each error path is relative to the whole manifest
     * (/pages/{n}/blocks/...); we strip the page prefix and localise it to a
     * top-level block, a metric_grid item, or a hero's embedded stat. Returns the
     * pruned page, or null when nothing localises (don't guess) or nothing
     * survives (let the caller re-throw).
     *
     * @param  array<string, mixed>  $page
     * @param  list<ManifestValidationError>  $errors
     * @return array<string, mixed>|null
     */
    private function repairPage(array $page, array $errors): ?array
    {
        $dropBlocks = [];
        $dropItems = [];
        $stripStat = [];
        foreach ($errors as $error) {
            $rel = (string) preg_replace('#^/pages/\d+#', '', (string) $error->path);
            if (preg_match('#^/blocks/(\d+)/stat(/|$)#', $rel, $m) === 1) {
                $stripStat[(int) $m[1]] = true;
            } elseif (preg_match('#^/blocks/(\d+)/items/(\d+)(/|$)#', $rel, $m) === 1) {
                $dropItems[(int) $m[1]][(int) $m[2]] = true;
            } elseif (preg_match('#^/blocks/(\d+)(/|$)#', $rel, $m) === 1) {
                $dropBlocks[(int) $m[1]] = true;
            } else {
                // An error we can't tie to a top-level block (e.g. nested deep in
                // a container) — don't blindly reshape the page.
                return null;
            }
        }

        $blocks = [];
        foreach ($page['blocks'] ?? [] as $i => $block) {
            if (isset($dropBlocks[$i])) {
                continue;
            }
            if (isset($stripStat[$i])) {
                unset($block['stat']); // a hero without its stat is still a valid title band
            }
            if (isset($dropItems[$i]) && isset($block['items']) && is_array($block['items'])) {
                $block['items'] = array_values(array_filter(
                    $block['items'],
                    fn (int $j): bool => ! isset($dropItems[$i][$j]),
                    ARRAY_FILTER_USE_KEY,
                ));
                if ($block['items'] === []) {
                    continue; // an empty metric_grid is not worth keeping
                }
            }
            $blocks[] = $block;
        }

        if ($blocks === []) {
            return null;
        }
        $page['blocks'] = $blocks;

        return $page;
    }

    /** First line only, hard-capped — titles are titles, not paragraphs. */
    private function clampLine(string $value, int $max): string
    {
        $line = trim((string) preg_split('/\r?\n/', trim($value))[0]);
        // No markup-ish characters in a human title (a prod title ended
        // "…for <targe…": an angle bracket sliced mid-word by the cap).
        $line = trim((string) preg_replace('/[<>`|{}]+/', ' ', $line));
        $line = trim((string) preg_replace('/\s{2,}/', ' ', $line));

        if (mb_strlen($line) <= $max) {
            return $line;
        }

        // Cut at a word boundary, not mid-word.
        $cut = mb_substr($line, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($max * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, ' :,-–—').'…';
    }
}
