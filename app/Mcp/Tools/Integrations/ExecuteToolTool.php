<?php

namespace App\Mcp\Tools\Integrations;

use App\Mcp\Tools\SapiensTool;
use App\Models\Tool;
use App\Models\User;
use App\Services\Connectors\ConnectorActionResolver;
use App\Services\ToolExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Run a tool/connector with parameters and return its result. A write-effect tool performs a real external operation — confirm with the user first.')]
class ExecuteToolTool extends SapiensTool
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

        // Report the resolved effect (pinned column else inferred from
        // method/operation/read_only), matching use_tool — not the raw column,
        // which is null for an inferred-effect tool.
        $effect = app(ConnectorActionResolver::class)->resolve($tool)->effect->value;

        $result = app(ToolExecutionService::class)->execute($tool, $validated['parameters'] ?? []);

        return Response::json([
            'success' => $result->success,
            'effect' => $effect,
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
            'tool_id' => $schema->string()->description('The tool id to execute.')->required(),
            'parameters' => $schema->object()->description('Parameters to pass to the tool.'),
        ];
    }
}
