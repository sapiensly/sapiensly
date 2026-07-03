<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\GeneratePaletteTool as BuilderTool;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Generate a professional colour palette from a base accent (pass a hex, or omit to use the organization\'s Brandbook accent — the platform default only when no brand is set). Returns a tint/shade ramp, a soft surface tint, an on-accent contrast colour and a 6-colour chart series — all also live at runtime as CSS vars (var(--sp-accent-50…900), var(--sp-accent-soft), var(--sp-chart-1…6)). Use for section tints, KPI cards, chart colours and hover states; keep it restrained so UIs stay executive.')]
class GeneratePaletteTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;

    protected function builderTool(Request $request): object
    {
        /** @var User|null $user */
        $user = $request->user();
        $organization = $user?->organization_id !== null
            ? Organization::find($user->organization_id)
            : null;

        return new BuilderTool($organization?->brandbook());
    }

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
            'base' => $schema->string()->description('Base accent as #RRGGBB. Omit to use the organization\'s Brandbook accent (or the platform default when no brand is set).'),
        ];
    }
}
