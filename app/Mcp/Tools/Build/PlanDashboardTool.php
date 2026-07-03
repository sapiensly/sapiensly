<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\PlanDashboardTool as BuilderTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('MANDATORY before building a dashboard/report page: submit your layout plan (purpose + rows top→bottom, most important first, each {section?, blocks: [{type, chart_type?, col_span?}]}) and get it linted for professional-dashboard qualities — full-width KPI metric_grid first, coherent titled sections, balanced 1-3 block rows with no lone short block leaving a gap, col_span weights for wide+narrow pairs, chart VARIETY (never 3 of the same chart_type), and at least one insight card stating conclusions. Returns {ok, issues, hints}; treat issues like validation errors — fix and re-call until ok:true, THEN build exactly that plan with propose_change.')]
class PlanDashboardTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;

    /**
     * @return array<string, mixed>
     */
    protected function arguments(Request $request): array
    {
        return $request->all();
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'purpose' => $schema->string()->description('Audience + the questions the dashboard answers, and why the top rows are the most important.')->required(),
            'rows' => $schema->array()->description('The layout, top→bottom, most important first. Each row: {section?: string, blocks: [{type: string, chart_type?: string, col_span?: int 1-12}]}. A row\'s blocks render side by side at equal height.')->required(),
        ];
    }
}
