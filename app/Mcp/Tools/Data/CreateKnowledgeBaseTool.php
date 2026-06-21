<?php

namespace App\Mcp\Tools\Data;

use App\Enums\KnowledgeBaseStatus;
use App\Enums\Visibility;
use App\Mcp\Tools\Data\Concerns\PresentsKnowledgeBase;
use App\Mcp\Tools\SapiensTool;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a knowledge base (a RAG corpus). Then add_document feeds it text to embed. Chunking is configurable via config.chunk_size / config.chunk_overlap; embeddings use your account or platform default model.')]
class CreateKnowledgeBaseTool extends SapiensTool
{
    use PresentsKnowledgeBase;

    protected const ABILITY = 'data:write';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('create', KnowledgeBase::class)) {
            return Response::error('You do not have permission to create knowledge bases.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
            'config' => ['nullable', 'array'],
            'config.chunk_size' => ['nullable', 'integer', 'min:100', 'max:4000'],
            'config.chunk_overlap' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);

        $kb = KnowledgeBase::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'keywords' => $validated['keywords'] ?? [],
            'status' => KnowledgeBaseStatus::Pending,
            'config' => $validated['config'] ?? ['chunk_size' => 1000, 'chunk_overlap' => 200],
        ]);

        return Response::json($this->knowledgeBasePayload($kb));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The knowledge base name.')->required(),
            'description' => $schema->string()->description('What this corpus contains.'),
            'keywords' => $schema->array()->description('Optional tags for search/categorization.'),
            'config' => $schema->object()->description('Optional chunking: { chunk_size (100-4000, default 1000), chunk_overlap (0-500, default 200) }.'),
        ];
    }
}
