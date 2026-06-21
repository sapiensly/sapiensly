<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\Data\Concerns\PresentsKnowledgeBase;
use App\Mcp\Tools\SapiensTool;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get a knowledge base: its config (chunking), status, counts and the documents attached to it with their embedding status.')]
class GetKnowledgeBaseTool extends SapiensTool
{
    use PresentsKnowledgeBase;

    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'knowledge_base_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $kb = KnowledgeBase::query()->forAccountContext($user)->findOrFail($validated['knowledge_base_id']);
        } catch (ModelNotFoundException) {
            return Response::error("No knowledge base '{$validated['knowledge_base_id']}' is visible to you.");
        }

        return Response::json($this->knowledgeBasePayload($kb, withDocuments: true));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()->description('The id of the knowledge base to inspect.')->required(),
        ];
    }
}
