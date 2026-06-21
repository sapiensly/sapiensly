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

#[Description('Update a knowledge base (partial — only supplied fields change): name, description, keywords, or chunking config. Changing chunk config affects documents embedded afterwards, not those already processed.')]
class UpdateKnowledgeBaseTool extends SapiensTool
{
    use PresentsKnowledgeBase;

    protected const ABILITY = 'data:write';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $kbId = $request->validate(['knowledge_base_id' => ['required', 'string']])['knowledge_base_id'];

        try {
            $kb = KnowledgeBase::query()->forAccountContext($user)->findOrFail($kbId);
        } catch (ModelNotFoundException) {
            return Response::error("No knowledge base '{$kbId}' is visible to you.");
        }

        if (! $user->can('update', $kb)) {
            return Response::error('You do not have permission to update this knowledge base.');
        }

        $validated = $request->validate([
            'knowledge_base_id' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'keywords' => ['sometimes', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
            'config' => ['sometimes', 'array'],
            'config.chunk_size' => ['nullable', 'integer', 'min:100', 'max:4000'],
            'config.chunk_overlap' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);

        $attributes = [];
        foreach (['name', 'description', 'keywords', 'config'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        if ($attributes !== []) {
            $kb->update($attributes);
        }

        return Response::json($this->knowledgeBasePayload($kb->refresh()));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()->description('The id of the knowledge base to update.')->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('New description.'),
            'keywords' => $schema->array()->description('Replace the keyword list.'),
            'config' => $schema->object()->description('Replace chunking: { chunk_size, chunk_overlap }.'),
        ];
    }
}
