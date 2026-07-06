<?php

namespace App\Ai\Tools\Builder;

use App\Support\Icons\IconCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The named icons the runtime can render anywhere a block accepts an `icon`.
 * Returns a curated shortlist (instant, no async fetch) — but the runtime
 * actually renders ANY real Lucide icon (~1,700, lazy-loaded on demand), so a
 * model isn't limited to this list: a plausible lucide.dev kebab-case name
 * works too (an unknown one falls back to plain text).
 */
class ListAvailableIconsTool implements Tool
{
    public function name(): string
    {
        return 'list_available_icons';
    }

    public function description(): string
    {
        return 'List commonly-used named icons for any block `icon` field (button, feature_grid item, stat, metric_grid item, insight, flow step, table action column, page/nav icon). Use these kebab-case names for crisp, consistent UI icons — they render as Lucide icons and inherit the surface colour. Beyond this shortlist, ANY real Lucide icon name also works (see lucide.dev/icons) — a sensible guess renders fine; an unknown name falls back to plain text. An emoji also works as an `icon`, but prefer a named icon for UI chrome.';
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
            'note' => 'This is a curated shortlist, not the full set — any real Lucide icon name (kebab-case, e.g. "chart-column", "circle-alert") also renders; see lucide.dev/icons. Emojis are also accepted. Named icons inherit the current text/accent colour and size to the block context.',
        ], JSON_THROW_ON_ERROR);
    }
}
