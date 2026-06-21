<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete a document. Pass a knowledge_base_id to only detach it from that KB (its chunks there are removed, the document is kept); omit it to delete the document entirely (removes it from every KB and deletes its file). Confirm with the user first.')]
class DeleteDocumentTool extends SapiensTool
{
    protected const ABILITY = 'data:write';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'document_id' => ['required', 'string'],
            'knowledge_base_id' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $document = Document::query()->forAccountContext($user)->find($validated['document_id']);
        if ($document === null) {
            return Response::error("No document '{$validated['document_id']}' is visible to you.");
        }

        $service = app(DocumentService::class);

        if (! empty($validated['knowledge_base_id'])) {
            $kb = KnowledgeBase::query()->forAccountContext($user)->find($validated['knowledge_base_id']);
            if ($kb === null) {
                return Response::error("No knowledge base '{$validated['knowledge_base_id']}' is visible to you.");
            }

            if (! $user->can('update', $document)) {
                return Response::error('You do not have permission to modify this document.');
            }

            $service->detachFromKnowledgeBase($document, $kb);

            return Response::json([
                'detached' => true,
                'document_id' => $document->id,
                'knowledge_base_id' => $kb->id,
            ]);
        }

        if (! $user->can('delete', $document)) {
            return Response::error('You do not have permission to delete this document.');
        }

        $service->delete($document);

        return Response::json(['deleted' => true, 'document_id' => $validated['document_id']]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string()->description('The id of the document.')->required(),
            'knowledge_base_id' => $schema->string()->description('Optional. If set, only detach from this KB instead of deleting the document.'),
        ];
    }
}
