<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\ListAvailableStepsTool as BuilderTool;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the workflow step types you may use (connector.call, script.run, branch, log, etc.), their props and outputs.')]
class ListAvailableStepsTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;
}
