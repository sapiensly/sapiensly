<?php

namespace App\Mcp\Tools\Integrations;

use App\Mcp\Tools\Integrations\Concerns\PresentsTool;
use App\Mcp\Tools\SapiensTool;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get the full configuration of a tool: its type, config (secrets masked), group members, and the resolved connector contract (typed inputs/outputs + read/write effect).')]
class GetToolTool extends SapiensTool
{
    use PresentsTool;

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

        return Response::json($this->toolPayload($tool));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()->description('The id of the tool to inspect.')->required(),
        ];
    }
}
