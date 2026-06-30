<?php

namespace App\Ai\Tools\Platform;

use App\Ai\Tools\RuntimeToolFactory;
use App\Mcp\Servers\SapiensServer;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool as ToolContract;

/**
 * Bridges the MCP tool catalogue ({@see SapiensServer::TOOLS}) into the set of
 * built-in "platform tools" every internal agent run gets, scoped to the agent's
 * owner. Destructive / irreversible operations are excluded (the user chose
 * "read + safe writes"); each remaining tool runs as the owner so its own
 * authorization + RLS enforce the owner's permissions (see {@see McpBridgeTool}).
 */
class PlatformToolsFactory
{
    /**
     * MCP tool names withheld from internal agents — deletes, real external
     * side effects, and irreversible state changes. The single source of truth
     * for "what an agent must not do on its own".
     *
     * @var list<string>
     */
    public const DENYLIST = [
        'delete_app',
        'delete_agent',
        'delete_tool',
        'delete_chatbot',
        'delete_knowledge_base',
        'delete_document',
        'delete_record',
        'delete_integration',
        // Real external connection probe; agents use read paths, not this.
        'test_integration_connection',
        // Force-run path: writes hit the external system unconditionally. Agents
        // use the effect-gated use_tool instead (reads run, writes need `safe`).
        'execute_tool',
        'run_workflow',
        'approve_workflow_proposal',
        'dismiss_workflow_proposal',
        'rollback_app',
        'test_tool_connection',
        // Spawns a full chat AI turn (token spend + tools); keep it out of
        // agent-initiated runs to avoid recursive/runaway turns.
        'continue_chat',
        // Spawns a full builder AI turn (token spend + tools, mutates the
        // manifest); same recursion/runaway risk as continue_chat.
        'continue_builder_conversation',
    ];

    /** @var array<string, list<ToolContract>> */
    private static array $memo = [];

    /**
     * The platform tools for an owner: every non-denylisted MCP tool, bridged
     * and uniquely class-named so the SDK accepts them.
     *
     * @return list<ToolContract>
     */
    public static function for(User $owner): array
    {
        $key = ($owner->organization_id ?? '-').':'.$owner->id;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $tools = [];
        foreach (SapiensServer::TOOLS as $class) {
            $name = self::toolName($class);
            if (in_array($name, self::DENYLIST, true)) {
                continue;
            }
            $tools[] = RuntimeToolFactory::named($name, new McpBridgeTool($class, $owner));
        }

        return self::$memo[$key] = $tools;
    }

    /**
     * Merge platform tools into an existing SDK tool array, deduped by final
     * name. An explicitly-attached tool wins over a platform tool of the same
     * name (a user who named a tool `query_records` meant theirs).
     *
     * @param  list<ToolContract>  $existing
     * @return list<ToolContract>
     */
    public static function merge(array $existing, User $owner): array
    {
        $taken = [];
        foreach ($existing as $tool) {
            $taken[self::sdkName($tool)] = true;
        }

        $platform = array_values(array_filter(
            self::for($owner),
            fn (ToolContract $tool) => ! isset($taken[class_basename($tool)]),
        ));

        return [...$existing, ...$platform];
    }

    /**
     * The MCP tool name for a tool class, mirroring SapiensTool::name()
     * (ReadManifestTool -> read_manifest) without instantiating the tool.
     */
    private static function toolName(string $mcpToolClass): string
    {
        return (string) Str::of(class_basename($mcpToolClass))->beforeLast('Tool')->snake();
    }

    /**
     * The LLM-facing name of an already-built SDK tool. RuntimeToolFactory-wrapped
     * tools carry it as their class basename; bare DynamicTool/McpServerTool
     * expose it via name().
     */
    private static function sdkName(object $tool): string
    {
        $base = class_basename($tool);

        if (($base === 'DynamicTool' || $base === 'McpServerTool') && method_exists($tool, 'name')) {
            return RuntimeToolFactory::toolName($tool->name());
        }

        return $base;
    }
}
