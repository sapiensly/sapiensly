<?php

namespace App\Mcp\Tools\Integrations;

use App\Mcp\Tools\SapiensTool;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete a tool. Agents that reference it will no longer be able to call it — confirm with the user first.')]
class DeleteToolTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'tool_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $tool = Tool::query()->forAccountContext($user)->find($validated['tool_id']);
        if ($tool === null) {
            return Response::error("No tool '{$validated['tool_id']}' is visible to you.");
        }

        if (! $user->can('delete', $tool)) {
            return Response::error('You do not have permission to delete this tool.');
        }

        $tool->delete();

        return Response::json(['deleted' => true, 'tool_id' => $validated['tool_id']]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()->description('The id of the tool to delete.')->required(),
        ];
    }
}
