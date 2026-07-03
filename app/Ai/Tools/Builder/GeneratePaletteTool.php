<?php

namespace App\Ai\Tools\Builder;

use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Derives a professional colour palette from a base accent (the app/brand accent,
 * or the platform default) so the builder can compose richer-but-executive UIs:
 * tinted section backgrounds, a cohesive chart series, hover/soft surfaces. The
 * runtime already exposes the SAME palette as CSS variables on every app surface;
 * this tool returns the concrete hexes + the variable names so the model can apply
 * them deliberately in block `style` or `custom_css`.
 */
class GeneratePaletteTool implements Tool
{
    public function __construct(private readonly ?OrganizationBrand $brand = null) {}

    public function name(): string
    {
        return 'generate_palette';
    }

    public function description(): string
    {
        return 'Generate a professional colour palette from a base accent (pass a hex, or omit to use the organization\'s Brandbook accent — the platform default only when no brand is set). Returns a tint/shade ramp (50–900), a soft surface tint, an on-accent contrast colour, and a 6-colour chart series — all also available at runtime as CSS variables: var(--sp-accent-50…900), var(--sp-accent-soft), var(--sp-accent-contrast), var(--sp-chart-1…6). Use these for section backgrounds, KPI tints, chart colours and hover states — keep it restrained (a soft tint behind a section, the accent for primary actions) so UIs stay executive, not loud.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'base' => $schema->string()->description('Base accent as #RRGGBB. Omit to use the organization\'s Brandbook accent (platform default '.OrganizationBrand::DEFAULT_ACCENT.' when no brand is set).'),
        ];
    }

    public function handle(Request $request): string
    {
        $base = $request->all()['base'] ?? null;
        $accent = is_string($base) && preg_match('/^#?[0-9A-Fa-f]{6}$/', $base)
            ? '#'.ltrim($base, '#')
            : ($this->brand?->effectiveAccent() ?? OrganizationBrand::DEFAULT_ACCENT);

        $palette = ColorPalette::fromAccent($accent);

        return json_encode([
            'base' => $accent,
            'ramp' => $palette['ramp'],
            'soft' => $palette['soft'],
            'contrast' => $palette['contrast'],
            'chart' => $palette['chart'],
            'css_variables' => [
                'ramp' => 'var(--sp-accent-50) … var(--sp-accent-900)',
                'soft' => 'var(--sp-accent-soft)',
                'contrast' => 'var(--sp-accent-contrast)',
                'chart' => 'var(--sp-chart-1) … var(--sp-chart-6)',
                'accent' => 'var(--sp-accent)',
            ],
            'note' => 'These CSS vars are already set on every app surface — prefer them over hard-coding hexes (they track the brand). Charts already use the series automatically. Use a soft tint (--sp-accent-50/100 or --sp-accent-soft) for section backgrounds and the accent for primary actions; avoid saturating whole pages.',
        ], JSON_THROW_ON_ERROR);
    }
}
