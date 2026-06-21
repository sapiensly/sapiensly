<?php

namespace App\Mcp\Tools\Data;

use App\Enums\DocumentType;
use App\Enums\Visibility;
use App\Mcp\Tools\Data\Concerns\PresentsDocument;
use App\Mcp\Tools\SapiensTool;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Add a document from raw text (no file upload). Pass a knowledge_base_id to attach it and kick off chunking + embedding (status starts pending, then ready). Only inline text types are supported here: txt, md, artifact (HTML). For binary files (PDF/DOCX) upload via the web app.')]
class AddDocumentTool extends SapiensTool
{
    use PresentsDocument;

    protected const ABILITY = 'data:write';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('create', Document::class)) {
            return Response::error('You do not have permission to create documents.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10485760'],
            'type' => ['nullable', 'string', Rule::in(['txt', 'md', 'artifact'])],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
            'knowledge_base_id' => ['nullable', 'string'],
        ]);

        // Resolve and authorize the target KB up front so we don't create an
        // orphan document when the KB isn't accessible.
        $kb = null;
        if (! empty($validated['knowledge_base_id'])) {
            $kb = KnowledgeBase::query()->forAccountContext($user)->find($validated['knowledge_base_id']);
            if ($kb === null) {
                return Response::error("No knowledge base '{$validated['knowledge_base_id']}' is visible to you.");
            }
        }

        $service = app(DocumentService::class);

        $document = $service->createInline(
            user: $user,
            type: DocumentType::from($validated['type'] ?? 'txt'),
            body: $validated['body'],
            name: $validated['name'],
            visibility: $user->organization_id ? Visibility::Organization : Visibility::Private,
            keywords: $validated['keywords'] ?? null,
        );

        if ($kb !== null) {
            $service->attachToKnowledgeBase($document, $kb);
        }

        return Response::json($this->documentPayload($document->refresh()));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The document name.')->required(),
            'body' => $schema->string()->description('The raw text content to ingest.')->required(),
            'type' => $schema->string()->enum(['txt', 'md', 'artifact'])->description('Inline content type (default txt).'),
            'keywords' => $schema->array()->description('Optional tags.'),
            'knowledge_base_id' => $schema->string()->description('Optional KB to attach to — triggers embedding so the text becomes searchable via search_knowledge.'),
        ];
    }
}
