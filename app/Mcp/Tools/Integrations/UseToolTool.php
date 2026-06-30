<?php

namespace App\Mcp\Tools\Integrations;

use App\Enums\ToolType;
use App\Mcp\Tools\SapiensTool;
use App\Models\Tool;
use App\Models\User;
use App\Services\Connectors\ConnectorCallGate;
use App\Services\ToolExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Run a tool the safe way: read-effect operations execute and return their result; a write-effect operation is REFUSED (returns its blast radius) unless the tool is marked safe. Use this to use any tool in the account without binding it first. For a confirmed write, use execute_tool instead.')]
class UseToolTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'tool_id' => ['required', 'string'],
            'parameters' => ['sometimes', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $tool = Tool::query()->forAccountContext($user)->findOrFail($validated['tool_id']);
        } catch (ModelNotFoundException) {
            return Response::error("No tool '{$validated['tool_id']}' is visible to you.");
        }

        // Only the single-operation executable types route through the effect
        // gate. mcp/function have no executor here, and a group would bypass
        // per-member gating, so they are refused with guidance.
        if (! in_array($tool->type, [ToolType::RestApi, ToolType::Graphql, ToolType::Database], true)) {
            return Response::json([
                'refused' => true,
                'reason' => 'unsupported_type',
                'type' => $tool->type?->value,
                'message' => match ($tool->type) {
                    ToolType::Mcp => 'MCP tools are not directly executable here — bind the MCP server to an agent so its tools expand, or call the MCP server directly.',
                    ToolType::Group => 'Tool groups are not run through use_tool — call each member tool individually so every operation is effect-gated.',
                    default => 'This tool type cannot be run with use_tool.',
                },
            ]);
        }

        $decision = app(ConnectorCallGate::class)->inspect($tool);
        $contract = $decision->contract;

        // Propose-don't-mutate: a non-`safe` write is refused (never executed)
        // and returns what it WOULD touch so the caller can confirm out of band.
        if ($decision->mustGate) {
            return Response::json([
                'refused' => true,
                'reason' => 'unconfirmed_write',
                'effect' => $contract->effect->value,
                'safe' => $contract->safe,
                'blast_radius' => $contract->blastRadius,
                'message' => 'This is a write-effect operation and is not marked safe, so it was not executed. Confirm with the user and run it via execute_tool, or have an admin mark the tool safe.',
            ]);
        }

        $result = app(ToolExecutionService::class)->execute($tool, $validated['parameters'] ?? []);

        return Response::json([
            'success' => $result->success,
            'effect' => $contract->effect->value,
            'data' => $result->data,
            'status_code' => $result->statusCode,
            'error' => $result->error,
            'execution_time_ms' => $result->executionTimeMs,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()->description('The tool id to use.')->required(),
            'parameters' => $schema->object()->description('Parameters to pass to the tool.'),
        ];
    }
}
