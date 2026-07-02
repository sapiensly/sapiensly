<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Presentations (slide decks). A deck is a Document of type `deck` whose body
 * holds the validated deck manifest (see DeckValidator); the frontend renders
 * it deterministically. `/slides` lists the account's decks; `/p/{document}`
 * is the full-screen viewer.
 */
class SlidesController extends Controller
{
    public function index(Request $request): Response
    {
        $decks = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->with('user:id,name')
            ->reorder()
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Document $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'theme' => (string) ($d->metadata['theme'] ?? 'executive'),
                'slide_count' => (int) ($d->metadata['slide_count'] ?? 0),
                'updated_at' => $d->updated_at?->toIso8601String(),
                'created_by' => $d->user?->name,
            ]);

        return Inertia::render('slides/Index', ['decks' => $decks]);
    }

    public function present(Request $request, string $document): Response
    {
        $deck = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $manifest = json_decode((string) $deck->body, true);
        abort_unless(is_array($manifest), 404);

        $brand = $request->user()?->organization?->brandbook();

        return Inertia::render('slides/Present', [
            'deck' => [
                'id' => $deck->id,
                'name' => $deck->name,
                'manifest' => $manifest,
            ],
            'brand' => [
                'accent' => $brand?->effectiveAccent(),
                'logo_url' => $brand?->logoUrl,
            ],
        ]);
    }

    public function destroy(Request $request, string $document): RedirectResponse
    {
        $deck = Document::forAccountContext($request->user())
            ->where('type', DocumentType::Deck)
            ->findOrFail($document);

        $deck->delete();

        return redirect()->route('slides.index');
    }
}
