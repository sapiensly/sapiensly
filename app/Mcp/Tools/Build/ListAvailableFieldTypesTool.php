<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\ListAvailableFieldTypesTool as BuilderTool;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the field types you may use on an object (string, number, date, single_select, etc.) and their props.')]
class ListAvailableFieldTypesTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;
}
