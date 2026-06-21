<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete a knowledge base. Agents that search it will lose access to its content — confirm with the user first.')]
class DeleteKnowledgeBaseTool extends SapiensTool
{
    protected const ABILITY = 'data:write';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'knowledge_base_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $kb = KnowledgeBase::query()->forAccountContext($user)->find($validated['knowledge_base_id']);
        if ($kb === null) {
            return Response::error("No knowledge base '{$validated['knowledge_base_id']}' is visible to you.");
        }

        if (! $user->can('delete', $kb)) {
            return Response::error('You do not have permission to delete this knowledge base.');
        }

        $kb->delete();

        return Response::json(['deleted' => true, 'knowledge_base_id' => $validated['knowledge_base_id']]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()->description('The id of the knowledge base to delete.')->required(),
        ];
    }
}
