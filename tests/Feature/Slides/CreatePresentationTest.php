<?php

use App\Enums\DocumentType;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Slides\CreatePresentationTool;
use App\Models\Document;
use App\Models\User;
use App\Services\Slides\DeckValidator;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

/**
 * A small valid deck exercising several layouts.
 *
 * @return array<int, array<string, mixed>>
 */
function validSlides(): array
{
    return [
        ['layout' => 'title', 'title' => 'Estrategia 0 → 100', 'subtitle' => 'Plan de 90 días', 'meta' => 'Sapiensly'],
        ['layout' => 'section', 'title' => 'El fundamento', 'kicker' => 'Semana 0'],
        ['layout' => 'bullets', 'title' => 'Canales', 'bullets' => ['Build in public en X', 'Comunidades técnicas', 'Onboarding manual']],
        ['layout' => 'big_number', 'value' => '100', 'label' => 'clientes de paga', 'delta' => '+100', 'context' => 'Meta a 90 días'],
        ['layout' => 'metrics', 'items' => [
            ['value' => '20', 'label' => 'Ola 1', 'delta' => 'white-glove'],
            ['value' => '40', 'label' => 'Ola 2'],
            ['value' => '40', 'label' => 'Ola 3'],
        ]],
        ['layout' => 'chart', 'title' => 'Clientes por fase', 'chart_type' => 'bar',
            'labels' => ['Ignición', 'Motor', 'Palanca'],
            'series' => [['name' => 'Clientes', 'data' => [15, 35, 50]]],
            'takeaway' => 'La palanca de referrals concentra la mitad del objetivo.'],
        ['layout' => 'quote', 'quote' => 'Dejá de arreglar tus automatizaciones. Dejá que un agente decida.', 'attribution' => 'CMO'],
        ['layout' => 'closing', 'title' => 'Siguientes pasos', 'bullets' => ['Publicar el primer hilo', 'Draftear el essay'], 'cta' => 'Arrancamos'],
    ];
}

it('validates a correct deck and rejects broken ones with precise errors', function () {
    $validator = new DeckValidator;

    expect($validator->validate([
        'title' => 'Deck',
        'theme' => 'dark',
        'slides' => validSlides(),
    ]))->toBe([]);

    $errors = $validator->validate([
        'title' => 'Deck',
        'theme' => 'neon',
        'slides' => [
            ['layout' => 'nope'],
            ['layout' => 'bullets', 'title' => 'x', 'bullets' => ['solo uno']],
            ['layout' => 'chart', 'title' => 'x', 'chart_type' => 'donut',
                'labels' => ['a', 'b'],
                'series' => [
                    ['name' => 's1', 'data' => [1, 2]],
                    ['name' => 's2', 'data' => [3, 4]],
                ]],
        ],
    ]);

    expect(implode("\n", $errors))
        ->toContain('theme:')
        ->toContain('slides.0.layout:')
        ->toContain('slides.1.bullets: 2 to 5 items')
        ->toContain('slides.2.series: 1 to 1 series');
});

it('enforces copy budgets so slides cannot overflow', function () {
    $errors = (new DeckValidator)->validate([
        'title' => 'Deck',
        'slides' => [
            ['layout' => 'bullets', 'title' => str_repeat('a', 80), 'bullets' => ['ok', str_repeat('b', 140)]],
        ],
    ]);

    expect(implode("\n", $errors))
        ->toContain('slides.0.title: at most 70 characters')
        ->toContain('slides.0.bullets.1: at most 110 characters');
});

it('create_presentation persists a deck document and returns the viewer url', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreatePresentationTool::class, [
            'name' => 'Estrategia de Marketing',
            'theme' => 'executive',
            'slides' => validSlides(),
        ])
        ->assertOk()
        ->assertSee('/p/');

    $deck = Document::query()->where('type', DocumentType::Deck)->sole();
    expect($deck->name)->toBe('Estrategia de Marketing')
        ->and($deck->metadata['slide_count'])->toBe(8)
        ->and($deck->metadata['theme'])->toBe('executive');

    $manifest = json_decode((string) $deck->body, true);
    expect($manifest['slides'])->toHaveCount(8)
        ->and($manifest['title'])->toBe('Estrategia de Marketing');
});

it('create_presentation rejects an invalid deck without persisting anything', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreatePresentationTool::class, [
            'name' => 'Broken',
            'slides' => [['layout' => 'bullets', 'title' => 'x', 'bullets' => ['one']]],
        ])
        ->assertHasErrors();

    expect(Document::query()->where('type', DocumentType::Deck)->exists())->toBeFalse();
});

it('lists decks in /slides and renders the viewer, scoped to the account', function () {
    $deck = Document::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'name' => 'Mi deck',
        'keywords' => [],
        'type' => DocumentType::Deck,
        'body' => json_encode(['title' => 'Mi deck', 'theme' => 'dark', 'slides' => validSlides()]),
        'visibility' => 'private',
        'metadata' => ['theme' => 'dark', 'slide_count' => 8],
    ]);

    $this->actingAs($this->user)
        ->get(route('slides.index'))
        ->assertOk()
        ->assertSee('Mi deck');

    $this->actingAs($this->user)
        ->get(route('slides.present', ['document' => $deck->id]))
        ->assertOk();

    // Another user in another account cannot open it.
    $stranger = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($stranger)
        ->get(route('slides.present', ['document' => $deck->id]))
        ->assertNotFound();
});

it('keeps decks out of the documents library listing', function () {
    Document::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'name' => 'Deck oculto en documentos',
        'keywords' => [],
        'type' => DocumentType::Deck,
        'body' => json_encode(['title' => 'x', 'slides' => validSlides()]),
        'visibility' => 'private',
        'metadata' => [],
    ]);

    $this->actingAs($this->user)
        ->get(route('documents.index'))
        ->assertOk()
        ->assertDontSee('Deck oculto en documentos');
});
