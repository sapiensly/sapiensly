<?php

namespace App\Mcp\Tools\Build;

use App\Ai\Tools\Builder\ListAvailableIconsTool as BuilderTool;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the named icons you can use for any block `icon` field (kebab-case, e.g. "shopping-cart"). They render as crisp Lucide icons across the UI; emojis also work but prefer a named icon for chrome. Unknown names fall back to plain text.')]
class ListAvailableIconsTool extends BuilderCatalogTool
{
    protected const BUILDER_TOOL = BuilderTool::class;
}
