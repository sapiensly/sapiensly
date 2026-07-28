<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Landing\HeadlessLandingShot;
use App\Services\Landing\LandingDesignCritic;
use App\Services\Landing\LatestPreviewShot;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("The design director for landings, callable on demand: judges a landing's APPLIED design (its bespoke html sections + custom_css, plus the freshest builder preview screenshot when one exists) against a vanguard, best-in-market bar and returns {ship, score, must_fix, tells, direction, strengths, judged_pixels}. Use it when hand-authoring a landing with propose_change — author, critique, apply the must_fix, re-critique (passing `round` incremented) until ship:true. The same demanding critic the in-app builder gate runs; a landing that hasn't shipped is not done.")]
class CritiqueLandingDesignTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'intent' => ['required', 'string', 'max:1000'],
            'round' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'mode' => ['sometimes', 'string', 'in:design,replicate'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $manifest = app(AppManifestService::class)->getActiveManifest($app);
        if (! is_array($manifest)) {
            return Response::error("App '{$app->slug}' has no active manifest.");
        }

        ['html' => $html, 'css' => $css, 'fonts' => $fonts] = LandingDesignCritic::extractSurfaces($manifest);
        $round = max(1, (int) ($validated['round'] ?? 1));

        // Pixel resolution for the director's eyes, best available first:
        //   1. an upload from an OPEN builder tab (freshest, what the user sees),
        //   2. else a HEADLESS render — so a fully external/headless caller (this
        //      MCP tool with no browser attached) still judges real pixels instead
        //      of degrading to text-only (judged_pixels was always false before).
        $screenshot = app(LatestPreviewShot::class)->for($app);
        $headlessShots = null;
        if ($screenshot === null) {
            $headlessShots = app(HeadlessLandingShot::class);
            $screenshot = $headlessShots->capture($app, $user);
        }

        // Same bar, same eyes, same MODEL as the builder gate: the critic
        // resolves the director chain itself (`landing_director` default + its
        // backup, inheriting landing_builder → builder when unset).
        $result = app(LandingDesignCritic::class)->forSubject($app->id)->critique(
            trim($validated['intent']),
            $html,
            $css,
            $user,
            null,
            $round,
            $screenshot,
            declaredFonts: $fonts,
            mode: ($validated['mode'] ?? '') === LandingDesignCritic::MODE_REPLICATE
                ? LandingDesignCritic::MODE_REPLICATE
                : LandingDesignCritic::MODE_DESIGN,
        );

        // The critic has consumed the pixels; drop the headless temp file.
        if ($headlessShots !== null) {
            $headlessShots->cleanup($screenshot);
        }

        return Response::json([
            'ship' => $result['ship'],
            'score' => $result['score'],
            'round' => $result['round'],
            'converged' => $result['converged'],
            'judged_pixels' => $screenshot !== null,
            'must_fix' => $result['must_fix'],
            'tells' => $result['tells'],
            'direction' => $result['direction'],
            'strengths' => $result['strengths'],
            'judged_by' => $result['judged_by'],
            // 'ok' = director verdict received; 'failed' = the director pass
            // errored/timed out (re-call to retry); 'skipped' = no director.
            'director' => $result['director'],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the landing app to judge.')->required(),
            'intent' => $schema->string()
                ->description('What the landing is for: the audience and the ONE job of the page. Grounds the critique — vanguard design is specific to the subject.')
                ->required(),
            'mode' => $schema->string()
                ->description('"design" (default) judges the page against a vanguard design bar. Use "replicate" ONLY when rebuilding a page that already exists (a design import): the director then judges FIDELITY to that original — missing sections, rewritten copy, wrong numbers, changed composition, missing icons, dead affordances — and stops asking for art direction the original never had. Replicate also converges in 2 rounds instead of 3.'),
            'round' => $schema->integer()
                ->description('The 1-based iteration of your critique→revise loop (default 1). By round 3 the director stops blocking on polish and ships.'),
        ];
    }
}
