<?php

namespace App\Mcp\Tools\Integrations\Concerns;

use App\Models\Tool;
use App\Services\Connectors\ConnectorActionResolver;
use App\Services\ToolConfigService;

/**
 * Shared presentation for the tool management MCP tools. Sensitive config
 * fields are masked (never returned in plaintext) and the resolved connector
 * contract (typed inputs/outputs + effect) is attached so a client sees the
 * operation shape without a separate call.
 */
trait PresentsTool
{
    /**
     * The JSON shape returned for a single tool.
     *
     * @return array<string, mixed>
     */
    protected function toolPayload(Tool $tool): array
    {
        $configService = app(ToolConfigService::class);

        $config = $tool->config ?? [];
        if ($configService->hasSensitiveFields($tool->type)) {
            $config = $configService->maskSensitiveFields($tool->type, $config);
        }

        $payload = [
            'id' => $tool->id,
            'name' => $tool->name,
            'description' => $tool->description,
            'type' => $tool->type?->value,
            'effect' => $tool->effect?->value,
            'safe' => $tool->safe,
            'status' => $tool->status?->value,
            'visibility' => $tool->visibility?->value,
            'config' => $config,
        ];

        if ($tool->type?->value === 'group') {
            $payload['tool_ids'] = $tool->groupItems()
                ->orderBy('order')
                ->pluck('tool_id')
                ->all();
        }

        // The resolved connector contract (typed IO + inferred effect). Best
        // effort — a tool type the resolver can't describe just omits it.
        try {
            $payload['contract'] = app(ConnectorActionResolver::class)->resolve($tool)->jsonSerialize();
        } catch (\Throwable) {
            $payload['contract'] = null;
        }

        return $payload;
    }
}
