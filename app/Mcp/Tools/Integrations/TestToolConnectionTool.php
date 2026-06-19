<?php

namespace App\Mcp\Tools\Integrations;

use App\Mcp\Tools\SapiensTool;
use App\Models\Tool;
use App\Models\User;
use App\Services\ToolExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Test that a tool/connector can reach its endpoint, without performing its real operation.')]
class TestToolConnectionTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'tool_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $tool = Tool::query()->forAccountContext($user)->findOrFail($validated['tool_id']);
        } catch (ModelNotFoundException) {
            return Response::error("No tool '{$validated['tool_id']}' is visible to you.");
        }

        $result = app(ToolExecutionService::class)->testConnection($tool);

        return Response::json([
            'success' => $result->success,
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
            'tool_id' => $schema->string()->description('The tool id to test.')->required(),
        ];
    }
}
