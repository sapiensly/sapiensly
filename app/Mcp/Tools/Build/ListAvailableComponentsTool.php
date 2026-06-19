<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\ListAvailableComponentsTool as BuilderTool;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the UI block types you may use inside a page (form, table, button, chart, etc.). The runtime only renders these — any other type fails validation.')]
class ListAvailableComponentsTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;
}
