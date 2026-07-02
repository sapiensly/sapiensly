<?php

namespace App\Mcp\Tools\Slides;

use App\Enums\DocumentType;
use App\Mcp\Tools\SapiensTool;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Read a presentation (slide deck): its theme and the full ordered slides array with 0-based indexes. Call this BEFORE update_presentation so your slide operations target the right indexes.')]
class GetPresentationTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'document_id' => ['required', 'string'],
        ]);

        $deck = Document::query()
            ->forAccountContext($user)
            ->where('type', DocumentType::Deck)
            ->find($validated['document_id']);

        if ($deck === null) {
            return Response::error("No presentation '{$validated['document_id']}' is visible to you.");
        }

        $manifest = json_decode((string) $deck->body, true);
        if (! is_array($manifest)) {
            return Response::error('The presentation manifest is corrupted.');
        }

        $slides = array_values((array) ($manifest['slides'] ?? []));

        return Response::json([
            'document_id' => $deck->id,
            'name' => $deck->name,
            'theme' => (string) ($manifest['theme'] ?? 'executive'),
            'slide_count' => count($slides),
            'slides' => collect($slides)->map(fn (array $s, int $i): array => ['index' => $i, ...$s])->all(),
            'url' => route('slides.present', ['document' => $deck->id]),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string()->description('The presentation document id (doc_…).')->required(),
        ];
    }
}
