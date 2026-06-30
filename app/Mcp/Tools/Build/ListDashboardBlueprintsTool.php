<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\ListDashboardBlueprintsTool as BuilderTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Sector-specialised dashboard blueprints (KPIs, charts, insights an expert analyst would use). Call with no sector to list sectors (support, sales_crm, ecommerce_retail, saas_subscriptions, general), then call again with a sector before composing metric_grid/chart/insight blocks so the dashboard reads as domain-specific. Blueprints are semantic: map each KPI/chart field hint to the app\'s real field ids and verify with simulate_query.')]
class ListDashboardBlueprintsTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;

    /**
     * @return array<string, mixed>
     */
    protected function arguments(Request $request): array
    {
        return ['sector' => (string) $request->get('sector', '')];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sector' => $schema->string()->description('The sector blueprint to read; omit to list available sectors.'),
        ];
    }
}
