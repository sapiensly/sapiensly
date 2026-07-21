<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Landing\DraftPreviewShot;
use App\Services\Landing\LandingDesignCritic;
use App\Services\Landing\LatestPreviewShot;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The design director as a builder gate. MANDATORY before finishing a landing:
 * the model states the page's intent, and this reads the landing's authored
 * markup + custom_css and judges it against a vanguard, best-in-market bar —
 * deterministic tells plus a demanding design-director model pass. Issues are
 * blocking: the model revises with propose_change and re-calls until it ships.
 *
 * The analog of plan_dashboard, but for the ONE thing that decides whether a
 * landing wins: design quality. It refuses to settle for "competent".
 */
class CritiqueLandingDesignTool implements Tool
{
    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private LandingDesignCritic $critic,
        private ?User $user = null,
        /** Reads the running turn's draft so it judges what you JUST authored. */
        private ?ProposeChangeTool $proposeTool = null,
        /** Set in builder turns: lets Stage 2 ask the open UI for a DRAFT shot. */
        private ?string $conversationId = null,
    ) {}

    public function name(): string
    {
        return 'critique_landing_design';
    }

    public function description(): string
    {
        return <<<'DESC'
MANDATORY before finishing a landing page: get its design judged against a
vanguard, best-in-market bar and pushed there. Pass `intent` — who the page is
for and the ONE job it must do — and this reads the landing's authored html +
custom_css and returns {ship, score, must_fix, tells, direction, strengths}.

A deterministic scan catches the absences that cap quality (no bespoke CSS, no
display type scale, no motion) plus generic tells; a design-director model pass
judges the actual composition against the intent and refuses "competent".
Whenever possible the director judges REAL PIXELS too (hierarchy, contrast,
balance as rendered): the open builder UI renders the CURRENT DRAFT and
screenshots it live for each round. `judged_pixels` in the response says what
it saw — 'draft' (this exact draft), 'applied' (the last applied version), or
false (text-only).

Treat must_fix like blocking errors: revise with propose_change (the design
lives in custom_css + your html sections + data-sp-* motion) and call
critique_landing_design again — passing `round` incremented each time — until
ship:true. The director is demanding but CONVERGES: a strong page (score ≥ 85)
ships, and by round 3 any remaining notes demote to non-blocking polish
(reported as `direction`). Do NOT finish a landing that has not shipped. Weigh
`direction` and `tells` even when they don't block — they are how the page goes
from good to best-in-market.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'intent' => $schema->string()
                ->description('What this landing is for: the audience and the ONE job of the page (e.g. "book a demo for a B2B logistics SaaS; visitors are ops directors who need proof it integrates"). Grounds the critique — vanguard design is specific to the subject.')
                ->required(),
            'round' => $schema->integer()
                ->description('The 1-based iteration of the critique→revise loop. Pass 1 the first time and increment on each re-call. Drives convergence: by round 3 the director stops blocking on polish and ships.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $intent = trim((string) ($args['intent'] ?? ''));
        $round = max(1, (int) ($args['round'] ?? 1));

        $manifest = $this->proposeTool?->currentManifest()
            ?? $this->manifestService->getActiveManifest($this->appModel);
        if (! is_array($manifest)) {
            return json_encode(['error' => 'No active manifest for this app.'], JSON_THROW_ON_ERROR);
        }

        ['html' => $html, 'css' => $css] = LandingDesignCritic::extractSurfaces($manifest);

        // The visual half, freshest first: (Stage 2) ask the open builder UI to
        // render THIS DRAFT and screenshot it — so a brand-new landing gets
        // pixel judgment on round 1, before anything is applied. No browser /
        // capture failed → (Stage 1) the last APPLIED version's shot. Neither →
        // text-only critique.
        $draftShots = $this->conversationId !== null ? app(DraftPreviewShot::class) : null;
        $screenshot = $draftShots?->capture($this->appModel, $this->conversationId, $manifest);
        $pixelSource = $screenshot !== null ? 'draft' : false;
        if ($screenshot === null) {
            $screenshot = app(LatestPreviewShot::class)->for($this->appModel);
            $pixelSource = $screenshot !== null ? 'applied' : false;
        }

        try {
            // The director judges on the landing_builder default when one is
            // configured (design judgment is where a stronger model pays);
            // null keeps the critic's normal builder-module resolution.
            $result = $this->critic->critique(
                $intent, $html, $css, $this->user,
                app(AiDefaults::class)->primary('landing_builder'),
                $round, $screenshot,
                screenshotIsCurrentDraft: $pixelSource === 'draft',
            );
        } finally {
            if ($pixelSource === 'draft') {
                $draftShots?->cleanup($screenshot);
            }
        }

        return json_encode([
            'ship' => $result['ship'],
            'score' => $result['score'],
            'round' => $result['round'],
            'converged' => $result['converged'],
            // 'draft' = live pixels of the html/css judged this round (Stage 2);
            // 'applied' = the last applied version's shot; false = text-only.
            'judged_pixels' => $pixelSource,
            'must_fix' => $result['must_fix'],
            'tells' => $result['tells'],
            'direction' => $result['direction'],
            'strengths' => $result['strengths'],
            'judged_by' => $result['judged_by'],
            'message' => $result['ship']
                ? 'The design director approved this landing — it clears the vanguard bar. You may finish. The `direction` notes are optional polish, not required.'
                : 'NOT vanguard yet. Fix every must_fix (and weigh the direction + tells), re-author with propose_change, then call critique_landing_design again with round='.($round + 1).'. Do not finish the landing until ship:true.',
        ], JSON_THROW_ON_ERROR);
    }
}
