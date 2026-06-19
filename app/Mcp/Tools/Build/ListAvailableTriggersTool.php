<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\ListAvailableTriggersTool as BuilderTool;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the workflow trigger types you may use (manual, schedule, webhook, record.create, etc.) and their context tokens.')]
class ListAvailableTriggersTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;
}
