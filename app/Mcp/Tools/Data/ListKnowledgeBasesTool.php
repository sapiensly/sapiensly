<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the knowledge bases you can search, with their id, name, status and document/chunk counts.')]
class ListKnowledgeBasesTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $bases = KnowledgeBase::query()->forAccountContext($user)->get();

        return Response::json([
            'knowledge_bases' => $bases->map(fn (KnowledgeBase $kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'description' => $kb->description,
                'status' => $kb->status?->value,
                'visibility' => $kb->visibility?->value,
                'document_count' => $kb->document_count,
                'chunk_count' => $kb->chunk_count,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
