<?php

namespace App\Services\Tools;

use App\Models\Integration;
use App\Models\Tool;

/**
 * Resolves the Connection (Integration) a tool runs against.
 *
 * A tool is the *action*; an integration is the *connection* (base URL + auth +
 * environments) it borrows. HTTP-shaped tools (rest_api, graphql) reference a
 * connection by id in `config.integration_id` — the same pattern mcp tools
 * already use. Tools without one are legacy self-contained tools that still
 * carry their own embedded connection in config (handled by the executors'
 * legacy path until they're migrated).
 */
class ToolConnectionResolver
{
    public function resolve(Tool $tool): ?Integration
    {
        $integrationId = $tool->config['integration_id'] ?? null;

        if (empty($integrationId)) {
            return null;
        }

        return Integration::find($integrationId);
    }
}
