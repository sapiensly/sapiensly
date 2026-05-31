<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use App\Services\ToolConfigService;
use App\Services\Tools\McpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lists (and caches) the tools an MCP server exposes for an MCP tool. The
 * listing runs as the current user, so OAuth servers see that member's token.
 */
class ToolMcpController extends Controller
{
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly McpClient $client,
    ) {}

    public function refresh(Request $request, Tool $tool): JsonResponse
    {
        $this->authorize('view', $tool);

        if ($tool->type->value !== 'mcp') {
            return response()->json(['message' => __('This tool is not an MCP tool.')], 422);
        }

        $config = $this->configService->decryptConfig($tool->type, $tool->config ?? []);

        try {
            $tools = $this->client->listTools($config, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Persist against the raw config so the encrypted auth_config is kept.
        $raw = $tool->config ?? [];
        $raw['mcp_tools'] = $tools;
        $raw['mcp_tools_synced_at'] = now()->toIso8601String();
        $tool->update(['config' => $raw]);

        return response()->json([
            'tools' => $tools,
            'synced_at' => $raw['mcp_tools_synced_at'],
        ]);
    }
}
