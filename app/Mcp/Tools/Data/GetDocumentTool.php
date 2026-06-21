<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\Data\Concerns\PresentsDocument;
use App\Mcp\Tools\SapiensTool;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get a document: its type, body (for inline documents), and the knowledge bases it is attached to with their embedding status.')]
class GetDocumentTool extends SapiensTool
{
    use PresentsDocument;

    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'document_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $document = Document::query()->forAccountContext($user)->findOrFail($validated['document_id']);
        } catch (ModelNotFoundException) {
            return Response::error("No document '{$validated['document_id']}' is visible to you.");
        }

        return Response::json($this->documentPayload($document));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string()->description('The id of the document to inspect.')->required(),
        ];
    }
}
