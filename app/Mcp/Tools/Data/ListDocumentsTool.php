<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List documents in your account, optionally limited to a single knowledge base.')]
class ListDocumentsTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'knowledge_base_id' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! empty($validated['knowledge_base_id'])) {
            $kb = KnowledgeBase::query()->forAccountContext($user)->find($validated['knowledge_base_id']);
            if ($kb === null) {
                return Response::error('That knowledge base is not available to you.');
            }
            $documents = $kb->attachedDocuments()->get();
        } else {
            $documents = Document::query()->forAccountContext($user)->get();
        }

        return Response::json([
            'documents' => $documents->map(fn (Document $doc) => [
                'id' => $doc->id,
                'name' => $doc->name,
                'type' => $doc->type?->value,
                'file_size' => $doc->file_size,
                'embedding_status' => $doc->pivot->embedding_status ?? null,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()->description('Optional knowledge base id to list documents from.'),
        ];
    }
}
