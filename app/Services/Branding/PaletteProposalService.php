<?php

namespace App\Services\Branding;

use App\Ai\ChatAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * Proposes brand accent colours for the organization Brandbook. The model only
 * picks the accents (a small, constrained JSON spec); the full palette each
 * accent implies — the tint/shade ramp, soft surface, contrast colour and chart
 * series every app surface derives at runtime — is expanded deterministically
 * via {@see ColorPalette}, so what the user previews is exactly what apps get.
 *
 * Falls back to a curated set of executive accents when no AI provider is
 * available or the model reply cannot be parsed, so the feature always answers.
 */
class PaletteProposalService
{
    private const SYSTEM = <<<'SYS'
        You are a senior brand designer choosing the single accent colour for a company's design system.
        Given a description of the organization (and possibly its current accent), respond with ONLY a single minified JSON object — no markdown, no code fences, no commentary — using exactly this schema:
        {"proposals":[{"name": string, "accent": "#RRGGBB", "rationale": string}]}
        - EXACTLY 4 proposals, each a clearly DISTINCT direction (e.g. corporate/trustworthy, warm/human, bold/energetic, calm/premium).
        - accent: a mid-tone with enough saturation to work as the colour of primary buttons and links on BOTH white and dark surfaces. Never near-black, near-white, grey, or neon.
        - name: 2-4 words naming the direction. rationale: ONE sentence on why it fits the brand. Write name and rationale in the SAME LANGUAGE as the description.
        SYS;

    /** Curated executive accents used when the AI path is unavailable. */
    private const FALLBACK = [
        ['name' => 'Executive Indigo', 'accent' => '#4f46e5', 'rationale' => 'Modern and trustworthy; the safest professional default for SaaS surfaces.'],
        ['name' => 'Deep Teal', 'accent' => '#0f766e', 'rationale' => 'Calm and dependable, with a fresher feel than the classic corporate blue.'],
        ['name' => 'Warm Amber', 'accent' => '#b45309', 'rationale' => 'Approachable and energetic while staying readable as an action colour.'],
        ['name' => 'Bold Berry', 'accent' => '#be185d', 'rationale' => 'Distinctive and confident for brands that want to stand out.'],
    ];

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
    ) {}

    /**
     * Propose accent colours (AI when available, curated otherwise), each
     * expanded with the full derived palette for preview.
     *
     * @return array{proposals: list<array<string, mixed>>, generated_by: string}
     */
    public function propose(string $brief, ?string $currentAccent = null, ?User $user = null): array
    {
        $proposals = $this->fromAi($brief, $currentAccent, $user);
        $generatedBy = 'ai';

        if ($proposals === []) {
            $proposals = $this->fallbackProposals($currentAccent);
            $generatedBy = 'fallback';
        }

        return [
            'proposals' => array_map(
                fn (array $p): array => [...$p, 'palette' => ColorPalette::fromAccent($p['accent'])],
                $proposals,
            ),
            'generated_by' => $generatedBy,
        ];
    }

    /**
     * The curated proposals; when a current accent is set it leads the list so
     * "keep what we have" is always a visible, comparable option.
     *
     * @return list<array{name: string, accent: string, rationale: string}>
     */
    public function fallbackProposals(?string $currentAccent = null): array
    {
        $proposals = self::FALLBACK;

        if ($currentAccent !== null && $currentAccent !== OrganizationBrand::DEFAULT_ACCENT) {
            array_unshift($proposals, [
                'name' => 'Current accent',
                'accent' => $currentAccent,
                'rationale' => 'The accent your brand uses today, for comparison.',
            ]);
            $proposals = array_slice($proposals, 0, 4);
        }

        return $proposals;
    }

    /**
     * Validate and trim a decoded model reply down to well-formed proposals.
     *
     * @return list<array{name: string, accent: string, rationale: string}>
     */
    public static function normalizeProposals(mixed $decoded): array
    {
        $items = is_array($decoded) ? ($decoded['proposals'] ?? null) : null;
        if (! is_array($items)) {
            return [];
        }

        $proposals = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $accent = is_string($item['accent'] ?? null) ? strtolower(trim($item['accent'])) : '';
            $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
            if ($name === '' || ! preg_match('/^#[0-9a-f]{6}$/', $accent)) {
                continue;
            }
            $proposals[] = [
                'name' => Str::limit($name, 60),
                'accent' => $accent,
                'rationale' => Str::limit(trim((string) ($item['rationale'] ?? '')), 240),
            ];
            if (count($proposals) === 4) {
                break;
            }
        }

        return $proposals;
    }

    /**
     * @return list<array{name: string, accent: string, rationale: string}>
     */
    private function fromAi(string $brief, ?string $currentAccent, ?User $user): array
    {
        $model = $this->aiDefaults->model('summary_short');
        $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;

        $prompt = 'Organization / brand description: '.($brief !== '' ? $brief : '(none given — propose versatile professional directions)');
        if ($currentAccent !== null) {
            $prompt .= PHP_EOL.'Current accent: '.$currentAccent.' (propose alternatives, not near-duplicates of it).';
        }

        try {
            $agent = new ChatAgent(instructions: self::SYSTEM, messages: [], tools: []);
            $response = $agent->prompt(
                Str::limit($prompt, 1500),
                provider: $provider,
                model: $model,
                timeout: (int) config('ai.request_timeout', 180),
            );

            return self::normalizeProposals(self::extractJson((string) ($response->text ?? '')));
        } catch (Throwable $e) {
            Log::warning('PaletteProposalService: AI proposal failed, using curated fallback', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Pull the first JSON object out of a model reply, tolerating code fences
     * and surrounding prose.
     */
    private static function extractJson(string $text): mixed
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        try {
            return json_decode(substr($text, $start, $end - $start + 1), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }
}
