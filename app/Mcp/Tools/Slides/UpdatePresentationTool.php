<?php

namespace App\Mcp\Tools\Slides;

use App\Enums\DocumentType;
use App\Mcp\Tools\SapiensTool;
use App\Models\Document;
use App\Models\User;
use App\Services\Slides\DeckEditor;
use App\Services\Slides\DeckValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Edit a presentation with slide-level operations — the way to act on feedback like "make slide 4 more executive" or "add a chart after the metrics" WITHOUT regenerating the whole deck. Call get_presentation first for current 0-based indexes, then pass operations applied in order: replace {index, slide}, insert {index, slide} (inserts BEFORE index; index = slide_count appends), remove {index}, move {index, to}. You can also rename the deck or switch its theme. The result is re-validated as a whole — errors name the exact slide and field so you can correct and retry.')]
class UpdatePresentationTool extends SapiensTool
{
    protected const ABILITY = 'data:write';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'document_id' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:120'],
            'theme' => ['nullable', 'string', 'in:'.implode(',', DeckValidator::THEMES)],
            'operations' => ['nullable', 'array', 'max:40'],
            'operations.*' => ['array'],
            'operations.*.op' => ['required', 'string', 'in:replace,insert,remove,move'],
            'operations.*.index' => ['required', 'integer', 'min:0'],
            'operations.*.to' => ['nullable', 'integer', 'min:0'],
            'operations.*.slide' => ['nullable', 'array'],
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

        $editor = app(DeckEditor::class);
        [$next, $error] = $editor->apply(
            $manifest,
            array_values((array) ($validated['operations'] ?? [])),
            $validated['name'] ?? null,
            $validated['theme'] ?? null,
        );

        if ($next === null) {
            return Response::error($error ?? 'The edit could not be applied.');
        }

        $editor->persist($deck, $next, $user);

        return Response::json([
            'updated' => true,
            'document_id' => $deck->id,
            'name' => $deck->name,
            'theme' => (string) $next['theme'],
            'slide_count' => count((array) $next['slides']),
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
            'name' => $schema->string()->description('Optional new deck title.'),
            'theme' => $schema->string()->enum(DeckValidator::THEMES)->description('Optional new theme.'),
            'operations' => $schema->array()->description('Slide operations applied in order, each {op, index, slide?, to?}: replace/insert take a full `slide` object ({layout, ...fields}); insert places BEFORE index (index = slide_count appends); move takes `to`. Indexes are 0-based against the deck state AFTER the previous operation.'),
        ];
    }
}
