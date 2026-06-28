<?php

namespace App\Ai\Tools\Builder;

use App\Support\Icons\IconCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The named icons the runtime can render anywhere a block accepts an `icon`.
 * Hard-coded to the renderer's registry so the model only emits names that
 * actually draw (an unknown name would fall back to plain text).
 */
class ListAvailableIconsTool implements Tool
{
    public function name(): string
    {
        return 'list_available_icons';
    }

    public function description(): string
    {
        return 'List the named icons you can use for any block `icon` field (button, feature_grid item, stat, metric_grid item, insight, flow step, table action column, page/nav icon). Use these kebab-case names for crisp, consistent UI icons — they render as Lucide icons and inherit the surface colour. An emoji also works as an `icon`, but prefer a named icon for UI chrome. Unknown names fall back to plain text, so pick from this list.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        return json_encode([
            'icons' => IconCatalog::NAMES,
            'count' => count(IconCatalog::NAMES),
            'note' => 'Use a name verbatim (e.g. "shopping-cart") in any block `icon`. Emojis are also accepted. Named icons inherit the current text/accent colour and size to the block context.',
        ], JSON_THROW_ON_ERROR);
    }
}
