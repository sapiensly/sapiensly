<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\ListAvailableActionsTool as BuilderTool;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the action types you may wire to on_click / on_submit (navigate, open_modal, create_record, run_workflow, etc.).')]
class ListAvailableActionsTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;
}
