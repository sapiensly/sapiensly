<?php

namespace App\Services;

use App\Ai\Tools\DynamicTool;
use App\Enums\ToolType;
use App\Models\Tool;
use Illuminate\Support\Collection;

class ToolBuilderService
{
    public function __construct(
        private readonly ToolExecutionService $executionService,
        private readonly ToolConfigService $configService,
    ) {}

    /**
     * Build SDK Tool objects from a collection of database Tool models.
     *
     * @param  Collection<int, Tool>  $tools
     * @return array<DynamicTool>
     */
    public function buildTools(Collection $tools): array
    {
        return $tools
            ->filter(fn (Tool $tool) => $this->isExecutable($tool))
            ->map(fn (Tool $tool) => new DynamicTool($tool, $this->executionService))
            ->values()
            ->all();
    }

    /**
     * Check if a tool type is executable.
     */
    private function isExecutable(Tool $tool): bool
    {
        return in_array($tool->type, [
            ToolType::Database,
            ToolType::RestApi,
            ToolType::Graphql,
        ]);
    }
}
