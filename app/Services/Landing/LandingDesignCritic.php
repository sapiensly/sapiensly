<?php

namespace App\Services\Landing;

use App\Ai\ExpressGateAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiUsageRecorder;
use App\Services\AiProviderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\StoredImage;

/**
 * The design director: the quality gate that pushes a landing toward VANGUARD,
 * best-in-market design and refuses to settle for "competent". Two layers,
 * mirroring the Express philosophy (deterministic floor + model judgment):
 *
 *  1. A deterministic scan for the generic "AI-cluster" tells and the absences
 *     that cap quality (no bespoke CSS, no display type scale, no motion,
 *     everything centered, a timid palette). Cheap, reliable, blocking.
 *  2. A design-director model pass: a demanding critic judges the actual
 *     HTML + custom CSS against a vanguard rubric, grounded in the user's
 *     INTENT, and returns specific, actionable pushback. This is what turns
 *     "fine" into "the best in the market" — it never ships generic work.
 *
 * The model layer degrades gracefully: if it can't run, the verdict falls back
 * to the deterministic floor (and says so) so a build never hard-blocks.
 */
class LandingDesignCritic
{
    /**
     * The director call's HTTP timeout. Frontier director models (Fable/Opus
     * tier) routinely deliberate 45-100s on a full-page critique; at the old
     * 45s two of three live passes timed out and the gate silently shipped on
     * the deterministic floor alone (observed 2026-07-25).
     */
    private const TIMEOUT_SECONDS = 120;

    /** Bound the prompt so a huge page can't blow the context/cost. */
    private const MAX_CHARS = 9000;

    /**
     * Convergence policy. The director is deliberately demanding, so a binary
     * "ship" would let it chase perfection forever. A genuinely strong page
     * (score ≥ SHIP_SCORE) ships, and after MAX_ROUNDS iterations the remaining
     * notes demote to non-blocking polish — the gate pushes hard but converges.
     */
    private const SHIP_SCORE = 85;

    private const MAX_ROUNDS = 3;

    /**
     * How many director models one pass may try (the configured director, then
     * its backup). Each attempt can burn up to TIMEOUT_SECONDS, so the list is
     * deliberately short — the gate loop provides further retries.
     */
    private const MAX_DIRECTOR_ATTEMPTS = 2;

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
    ) {}

    /**
     * How the last director pass ended: 'ok' (verdict received), 'failed'
     * (attempted but timed out / errored / unparseable — a retry may succeed),
     * or 'skipped' (no user / no provider — retrying cannot help). Drives the
     * degraded-ship policy in {@see self::critique()}.
     */
    protected string $directorStatus = 'skipped';

    /** The app/conversation the director passes bill against (see forSubject). */
    protected ?string $subjectAppId = null;

    protected ?string $subjectConversationId = null;

    /**
     * Tag the director passes with the build they belong to, so each pass lands
     * in the usage ledger against the app (and conversation) like the builder's
     * own turns do — without it the director's spend is invisible to
     * get_build_cost (observed: a 3-pass hybrid build billed only the
     * constructor).
     */
    public function forSubject(?string $appId, ?string $conversationId = null): static
    {
        $this->subjectAppId = $appId;
        $this->subjectConversationId = $conversationId;

        return $this;
    }

    /**
     * Judge a landing's authored design. `$round` is the 1-based iteration of the
     * revise→re-critique loop; it drives the max-rounds half of the convergence
     * policy.
     *
     * @return array{ship: bool, score: ?int, must_fix: list<string>, tells: list<string>, direction: list<string>, strengths: list<string>, judged_by: string, director: string, converged: bool, round: int}
     */
    public function critique(
        string $intent,
        string $html,
        string $css,
        ?User $user = null,
        ?string $modelOverride = null,
        int $round = 1,
        ?StoredImage $screenshot = null,
        bool $screenshotIsCurrentDraft = false,
    ): array {
        $det = $this->deterministicTells($html, $css);
        $floorFix = $det['must_fix'];
        $ai = $this->directorCritique($intent, $html, $css, $user, $modelOverride, $screenshot, $screenshotIsCurrentDraft);

        $mustFix = $floorFix;
        $direction = [];
        $strengths = [];
        $score = null;
        $judgedBy = 'deterministic';
        $converged = false;

        if ($ai !== null) {
            $judgedBy = 'design-director';
            $aiShip = (bool) ($ai['ship'] ?? false);
            $score = is_numeric($ai['score'] ?? null) ? (int) $ai['score'] : null;
            $aiFix = $this->strings($ai['must_fix'] ?? []);
            $direction = $this->strings($ai['direction'] ?? []);
            $strengths = $this->strings($ai['strengths'] ?? []);

            // Converge on a strong score, an explicit director "ship", or the
            // round cap — otherwise the demanding director loops on perfection.
            $converged = $aiShip
                || ($score !== null && $score >= self::SHIP_SCORE)
                || $round >= self::MAX_ROUNDS;

            if ($converged) {
                // The floor is still hard-blocking; the director's remaining
                // fixes demote to non-blocking polish so the page can ship.
                $direction = array_values(array_merge($aiFix, $direction));
            } else {
                $mustFix = array_values(array_merge($floorFix, $aiFix));
            }
        }

        // Ships only with the floor clean AND either the director's blessing /
        // convergence, or (director unavailable) the deterministic pass.
        $ship = $mustFix === [] && ($ai === null || $converged);

        // A FAILED director pass (timeout / error) is not the same as an
        // unavailable one: the verdict is retryable, so a clean floor must not
        // count as the final blessing before the round cap. Ship stays true on
        // 'skipped' (no user/provider — a retry cannot help) and at the cap
        // (the gate never hard-blocks a build).
        $director = $ai !== null ? 'ok' : $this->directorStatus;
        if ($ship && $director === 'failed' && $round < self::MAX_ROUNDS) {
            $ship = false;
        }

        return [
            'ship' => $ship,
            'score' => $score,
            'must_fix' => array_values(array_unique($mustFix)),
            'tells' => $det['tells'],
            'direction' => $direction,
            'strengths' => $strengths,
            'judged_by' => $judgedBy,
            'director' => $director,
            'converged' => $converged,
            'round' => $round,
        ];
    }

    /**
     * Collect the judgeable surfaces from a manifest: every `html` block's
     * content (descending through layout containers) plus the custom CSS.
     * Shared by the builder gate (draft manifest) and the MCP tool (active
     * manifest) so both judge the same thing.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{html: string, css: string}
     */
    public static function extractSurfaces(array $manifest): array
    {
        $parts = [];
        foreach ($manifest['pages'] ?? [] as $page) {
            if (is_array($page) && isset($page['blocks']) && is_array($page['blocks'])) {
                self::walkHtmlBlocks($page['blocks'], $parts);
            }
        }

        return [
            'html' => implode("\n\n", $parts),
            'css' => (string) ($manifest['settings']['custom_css'] ?? ''),
        ];
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  list<string>  $parts
     */
    private static function walkHtmlBlocks(array $blocks, array &$parts): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'html' && is_string($block['content'] ?? null)) {
                $parts[] = $block['content'];
            }
            // Surface the platform-rendered form to the judges: the floor
            // requires it styled + slotted, and the director should judge the
            // page KNOWING a form renders here.
            if (($block['type'] ?? null) === 'lead_form') {
                $parts[] = '<!-- platform block: lead_form (renders .sp-lead-form here) -->';
            }
            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    self::walkHtmlBlocks($block[$key], $parts);
                }
            }
            foreach (['tabs', 'sections'] as $key) {
                foreach ($block[$key] ?? [] as $sub) {
                    if (is_array($sub) && isset($sub['blocks']) && is_array($sub['blocks'])) {
                        self::walkHtmlBlocks($sub['blocks'], $parts);
                    }
                }
            }
        }
    }

    /**
     * Reliable, blocking checks for the absences that cap a landing's ceiling,
     * plus softer "tells" of generic design. Kept conservative — it only blocks
     * on the unambiguous ones so it never fights a genuinely bespoke page.
     *
     * @return array{must_fix: list<string>, tells: list<string>}
     */
    public function deterministicTells(string $html, string $css): array
    {
        $must = [];
        $tells = [];
        $css = trim($css);
        $html = trim($html);

        // The bespoke look must live in custom_css.
        if (strlen($css) < 400) {
            $must[] = 'Almost no custom_css — a vanguard landing IS bespoke CSS, not default block styling. Author the look (type scale, palette, layout, motion) in settings.custom_css targeting your html classes.';
        }

        // The page must be composed as bespoke html sections.
        if (strlen($html) < 200 || (! str_contains($html, '<section') && ! str_contains($html, 'class='))) {
            $must[] = 'No bespoke html sections — compose the page as `html` blocks with your own semantic markup + classes, not a stack of the generic marketing blocks.';
        }

        // A confident display type scale.
        $hasBigType =
            preg_match('/font-size\s*:\s*clamp\([^)]*?(?:[2-9]\.\d+|[3-9]|\d{2})rem/i', $css) === 1
            || preg_match('/font-size\s*:\s*(?:[2-9]\.\d+|[3-9]|\d{2})rem/i', $css) === 1;
        if (! $hasBigType) {
            $must[] = 'No confident display type — open with big, tightly-tracked headline type (e.g. font-size: clamp(2.5rem, 6vw, 4.5rem); letter-spacing: -.03em). One flat body size reads generic.';
        }

        // Motion is not optional for a top-tier landing.
        if (! str_contains($html, 'data-sp-')) {
            $must[] = 'The page is static — add motion: at minimum data-sp-reveal on sections; ideally an ambient (data-sp-motion="ambient-field") or a live sequence (data-sp-sequence) moment in the hero.';
        }

        // A lead_form left unstyled/unplaced renders as raw white default
        // inputs orphaned after the last section — the single ugliest thing a
        // shipped landing can do to its ONE conversion point (observed live).
        if (str_contains($html, 'platform block: lead_form')) {
            if (! str_contains($css, '.sp-lead-form')) {
                $must[] = 'The lead_form block is UNSTYLED — it will render as raw default inputs. Style .sp-lead-form (and .sp-lead-field label/input, the submit button) in custom_css so the form belongs to this design.';
            }
            if (! str_contains($html, 'data-sp-slot')) {
                $must[] = 'The lead_form has no placement slot — as a sibling block it renders AFTER your sections (often below the footer). Put an empty <div data-sp-slot="lead_form"></div> inside the section where the form belongs (e.g. the CTA panel); the runtime moves the form into it.';
            }
        }

        // ---- softer tells (reported, not blocking) ----
        $centerCount = (int) preg_match_all('/text-align\s*:\s*center/i', $css);
        if ($centerCount >= 4) {
            $tells[] = "Much of the page is centered ({$centerCount}× text-align:center) — centered-everything is the classic generic tell. Commit to asymmetry and tension: a left-aligned hero, an off-balance grid.";
        }

        preg_match_all('/#[0-9a-fA-F]{6}\b/', $css, $hexes);
        $distinctHex = count(array_unique(array_map('strtolower', $hexes[0] ?? [])));
        if ($distinctHex < 3) {
            $tells[] = 'Timid palette — too few colours. Commit to a palette: a chosen neutral (a grey with a hue bias, or a deep ground), a confident accent, maybe a second colour for tension.';
        }

        if (preg_match('/text-transform\s*:\s*uppercase/i', $css) !== 1) {
            $tells[] = 'No label/eyebrow system — small uppercase, letter-spaced (ideally mono) labels give a page editorial structure and hierarchy.';
        }

        return ['must_fix' => $must, 'tells' => $tells];
    }

    /**
     * The design-director model pass. Tries the director candidates in order
     * (the configured director, then its backup) and returns the first decoded
     * critique — or null when every attempt fails (timeout, error, unparseable)
     * so the caller falls back to the deterministic floor.
     *
     * @return array<string, mixed>|null
     */
    protected function directorCritique(
        string $intent,
        string $html,
        string $css,
        ?User $user,
        ?string $modelOverride,
        ?StoredImage $screenshot = null,
        bool $screenshotIsCurrentDraft = false,
    ): ?array {
        $this->directorStatus = 'skipped';

        if ($user === null) {
            return null;
        }

        try {
            $this->providers->applyRuntimeConfig($user);
            $candidates = $this->directorCandidates($modelOverride);
        } catch (\Throwable) {
            return null;
        }

        foreach ($candidates as $model) {
            $decoded = $this->directorAttempt($intent, $html, $css, $user, $model, $screenshot, $screenshotIsCurrentDraft);

            if ($decoded !== null) {
                $this->directorStatus = 'ok';

                return $decoded;
            }

            $this->directorStatus = 'failed';
        }

        return null;
    }

    /**
     * The ordered models the director pass may run on, capped at the primary +
     * one backup (each attempt can burn up to TIMEOUT_SECONDS, and the gate
     * loop itself retries). Resolution: an explicit override, then the
     * dedicated `landing_director` module (primary, fallback), then — so a
     * platform without a dedicated director behaves exactly as before — the
     * `landing_builder` chain and finally the general builder chain.
     *
     * @return list<string>
     */
    public function directorCandidates(?string $explicit = null): array
    {
        $explicit = $explicit !== null && trim($explicit) !== '' ? trim($explicit) : null;

        $chain = array_values(array_unique(array_filter([
            $explicit,
            $this->aiDefaults->primary('landing_director'),
            $this->aiDefaults->fallback('landing_director'),
            $this->aiDefaults->primary('landing_builder'),
            $this->aiDefaults->fallback('landing_builder'),
            ...$this->aiDefaults->candidates('builder'),
        ])));

        return array_slice($chain, 0, self::MAX_DIRECTOR_ATTEMPTS);
    }

    /**
     * One director pass on one model. Returns the decoded critique, or null on
     * any failure (missing provider, timeout, unparseable) so the caller can
     * advance to the backup model.
     *
     * @return array<string, mixed>|null
     */
    protected function directorAttempt(
        string $intent,
        string $html,
        string $css,
        User $user,
        string $model,
        ?StoredImage $screenshot = null,
        bool $screenshotIsCurrentDraft = false,
    ): ?array {
        $schemaFn = fn (JsonSchema $schema): array => [
            'ship' => $schema->boolean()
                ->description('true ONLY if this landing is genuinely vanguard — distinctive, specific to the intent, and crafted, the kind of page a design-led company ships. "Competent" is false.')
                ->required(),
            'score' => $schema->integer()
                ->description('0-100 vanguard quality. 90+ = best-in-market; 70-89 = strong but revise; <70 = generic.')
                ->required(),
            'must_fix' => $schema->array()
                ->description('Blocking, SPECIFIC, actionable design changes (name the section, the property, the direction). Empty only when ship is true.'),
            'direction' => $schema->array()
                ->description('Concrete art-direction pushes toward vanguard, grounded in the intent — where to add tension, motion, a bolder idea.'),
            'strengths' => $schema->array()
                ->description('What already works and must be kept.'),
        ];

        try {
            $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;

            $agent = (new ExpressGateAgent(self::DIRECTOR_INSTRUCTIONS, $schemaFn))->forModel($model);

            $response = $agent->prompt(
                $this->buildPrompt($intent, $html, $css, $screenshot !== null, $screenshotIsCurrentDraft),
                attachments: $screenshot !== null ? [$screenshot] : [],
                provider: $provider,
                model: $model,
                timeout: self::TIMEOUT_SECONDS,
            );

            // Every pass bills — including ones whose verdict fails to decode —
            // so it lands in the ledger either way. Best-effort, like every
            // other usage-recording call site.
            try {
                app(AiUsageRecorder::class)->record(
                    'landing_director', $model, $user, $user->organization_id,
                    $response->usage ?? null,
                    appId: $this->subjectAppId, conversationId: $this->subjectConversationId,
                );
            } catch (\Throwable) {
                // Usage accounting is best-effort.
            }

            $decoded = $response instanceof Arrayable ? $response->toArray() : null;
            if (! is_array($decoded) || $decoded === []) {
                $decoded = $this->decodeLenient($response->text ?? null);
            }

            return is_array($decoded) && $decoded !== [] ? $decoded : null;
        } catch (\Throwable $e) {
            Log::info('LandingDesignCritic: director pass failed', ['model' => $model, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function buildPrompt(string $intent, string $html, string $css, bool $hasScreenshot = false, bool $screenshotIsCurrentDraft = false): string
    {
        $intent = trim($intent) === '' ? '(not stated — infer it from the page and judge whether the page makes its own purpose obvious)' : trim($intent);

        $pixels = '';
        if ($hasScreenshot) {
            $freshness = $screenshotIsCurrentDraft
                ? 'The screenshot is a LIVE RENDER of the exact html/css below — pixels and code describe the same draft, so judge them as one.'
                : 'The screenshot reflects the LAST APPLIED version; where it differs from the html/css below (the current draft), trust the code for content and the pixels for rendering quality.';
            $pixels = "A SCREENSHOT of the rendered page is attached — judge the ACTUAL PIXELS first: real hierarchy, contrast, spacing, balance, how the composition actually lands. Motion was settled to its final visible state for the capture (the ambient field shows as a frozen frame). {$freshness}\n\n";
        }

        return "INTENT — what this landing is for (audience + the one job of the page):\n{$intent}\n\n"
            .$pixels
            ."--- HTML (structure) ---\n".$this->clip($html)."\n\n"
            ."--- CUSTOM CSS (the look) ---\n".$this->clip($css)."\n\n"
            ."NOTE ON MOTION: data-sp-* attributes are LIVE motion hydrated by the runtime — you will NOT see their keyframes in the CSS, so credit them as working motion rather than asking for CSS that isn't there. data-sp-reveal = fade + rise on scroll; data-sp-sequence = the element's children stagger in one by one (e.g. a chat animating message-by-message); data-sp-motion=\"ambient-field\" = an animated connected-node field behind the element. Judge whether the motion CHOICES are ambitious enough, not whether CSS keyframes are present.\n\n"
            .'Judge this landing against the vanguard bar. Be demanding — a beautiful-but-generic page is a revise. Ground every note in the intent.';
    }

    private function clip(string $s): string
    {
        return strlen($s) > self::MAX_CHARS ? substr($s, 0, self::MAX_CHARS)."\n… (truncated)" : $s;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeLenient(?string $text): ?array
    {
        $text = trim((string) $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /**
     * The director's brief AND rubric — the taste the critique enforces. Adapted
     * from the studio design discipline: vanguard is SPECIFIC to the subject, and
     * generic-but-polished is a failure.
     */
    private const DIRECTOR_INSTRUCTIONS = <<<'TXT'
You are the design director at a studio whose landing pages are considered the best in the market — vanguard, unmistakably crafted, never templated. Judge ONE landing page draft (its HTML structure + its custom CSS) against that bar and push it there. You are demanding: "competent" is a FAIL. You reject anything that reads as generic, AI-made design.

FIRST, honor the intent. Vanguard design is SPECIFIC to the subject — its audience, the ONE job of the page, the feeling it should provoke. Judge whether the design is grounded in THIS product's world, not generic polish. A beautiful-but-generic page is a revise.

The bar — push toward all of these:
- Typography as identity: a real, decisive type scale; confident display sizing (big, tight tracking, weight/width contrast); a characterful or mono accent for labels. Never one flat size. The platform ships a curated font catalog the page can use directly in custom_css — 'Fraunces', 'Instrument Serif', 'Bricolage Grotesque', 'Archivo' (variable width), 'IBM Plex Mono', 'Alfa Slab One' (poster slab), 'Anton' (condensed poster) — so a landing set entirely in system faces is leaving identity on the table; push toward a catalog display face when the type reads default, and toward the POSTER faces when the intent is loud (an elegant serif on a cartel brief is a miscast, not restraint).
- Commit to the intent's visual GRAMMAR, not just its palette. A loud/poster/street intent has its own vocabulary — hard borders, offset solid shadows, rotated stamps/badges, marquee ribbons, halftone/paper texture, outline display type — and vanguard means COMMITTING to it end to end (every card, button and detail speaking it), exactly as an editorial intent demands its grid and whitespace. Softening a loud brief into tasteful editorial elegance is a FAILURE of nerve; name the grammar the intent calls for and demand it consistently.
- Spatial composition with tension: asymmetry, intentional whitespace as a material, layering/overlap, a clear focal hierarchy. Not everything centered and evenly stacked.
- Committed colour: a deliberate palette used boldly — a chosen neutral with a hue bias (not a default grey), one confident accent, optionally a meaningful second. Not a timid lone accent on pure white.
- Motion with intent: a load or scroll moment, an ambient element, hover micro-interactions — serving the subject, not scattered. A static top-tier landing does not exist.
- One bold idea: the hero opens with the most characteristic thing about the subject — a live demo, a striking device, an interactive moment. A thesis, not a headline over a stock photo.
- Craft in the details: grain/glow/gradient/texture where it earns its place; tabular numerals for figures; balanced headings; visible focus states.

REJECT these generic tells on sight: everything centered; rounded corners on everything; a lone accent on pure white; the purple→blue gradient hero; Inter/Space Grotesk as the "safe" face; emoji as section markers; a uniform 3-column feature grid as the whole page; warm-cream + serif + terracotta; broadsheet hairline rules as decoration.

Return a verdict. ship=true ONLY if the page is genuinely distinctive, specific to the intent, and crafted — a page a design-led company would be proud to ship. Otherwise ship=false with `must_fix`: specific, actionable changes that name the section, the CSS property and the direction (e.g. "the hero is centered and generic — make it asymmetric, with the product's live demo as the right-hand focal element and a clamp() display headline at ~4rem, tracking -.03em"). Add `direction` (art-direction pushes grounded in the intent) and `strengths` (what to keep). Never vague praise; every note must be actionable.
TXT;
}
