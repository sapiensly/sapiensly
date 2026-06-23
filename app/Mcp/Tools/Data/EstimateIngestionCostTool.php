<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Services\IngestionCostEstimator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Estimate the full USD cost of ingesting a document into a knowledge base BEFORE processing it: PDF OCR (per page, only if scanned) plus embedding generation (per token). Returns the chosen extraction method, OCR engine, page count and a cost breakdown.')]
class EstimateIngestionCostTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'document_id' => ['required', 'string'],
            'knowledge_base_id' => ['required', 'string'],
            'ocr_engine' => ['sometimes', 'string', 'in:mistral-ocr,cloudflare-ai'],
        ]);

        // Both tables are RLS-scoped to the caller's tenant.
        $document = Document::query()->find($validated['document_id']);
        if ($document === null) {
            return Response::error('Document not found.');
        }

        $knowledgeBase = KnowledgeBase::query()->find($validated['knowledge_base_id']);
        if ($knowledgeBase === null) {
            return Response::error('Knowledge base not found.');
        }

        $estimate = app(IngestionCostEstimator::class)->estimateForDocument(
            $document,
            $knowledgeBase,
            $validated['ocr_engine'] ?? null,
        );

        return Response::json($estimate);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string()->description('The document to estimate ingestion cost for.')->required(),
            'knowledge_base_id' => $schema->string()->description('The target knowledge base (determines the embedding model).')->required(),
            'ocr_engine' => $schema->string()->description('Optional OCR engine override: mistral-ocr or cloudflare-ai. Defaults to the automatic heuristic.'),
        ];
    }
}
