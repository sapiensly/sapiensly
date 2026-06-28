<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\GeneratePaletteTool as BuilderTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Generate a professional colour palette from a base accent (pass the app/brand accent hex, or omit for the platform default). Returns a tint/shade ramp, a soft surface tint, an on-accent contrast colour and a 6-colour chart series — all also live at runtime as CSS vars (var(--sp-accent-50…900), var(--sp-accent-soft), var(--sp-chart-1…6)). Use for section tints, KPI cards, chart colours and hover states; keep it restrained so UIs stay executive.')]
class GeneratePaletteTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;

    /**
     * @return array<string, mixed>
     */
    protected function arguments(Request $request): array
    {
        return ['base' => $request->get('base', '')];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'base' => $schema->string()->description('Base accent as #RRGGBB. Omit to use the platform default accent.'),
        ];
    }
}
