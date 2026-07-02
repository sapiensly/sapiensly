<?php

use App\Enums\DocumentType;
use App\Jobs\RunSlideBuilderJob;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Slides\CreatePresentationTool;
use App\Mcp\Tools\Slides\GetPresentationTool;
use App\Mcp\Tools\Slides\UpdatePresentationTool;
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

it('edits a deck with slide operations and revalidates the result', function () {
    $create = SapiensServer::actingAs($this->user)
        ->tool(CreatePresentationTool::class, [
            'name' => 'Editable',
            'slides' => validSlides(),
        ])
        ->assertOk();

    $deck = Document::query()->where('type', DocumentType::Deck)->sole();

    SapiensServer::actingAs($this->user)
        ->tool(GetPresentationTool::class, ['document_id' => $deck->id])
        ->assertOk()
        ->assertSee('"slide_count":8');

    // Replace slide 2, remove the quote (index 6), move closing forward.
    SapiensServer::actingAs($this->user)
        ->tool(UpdatePresentationTool::class, [
            'document_id' => $deck->id,
            'name' => 'Editable v2',
            'theme' => 'dark',
            'operations' => [
                ['op' => 'replace', 'index' => 2, 'slide' => [
                    'layout' => 'bullets', 'title' => 'Canales priorizados',
                    'bullets' => ['X primero', 'Comunidades después'],
                ]],
                ['op' => 'remove', 'index' => 6],
                ['op' => 'move', 'index' => 6, 'to' => 5],
            ],
        ])
        ->assertOk()
        ->assertSee('"slide_count":7');

    $manifest = json_decode((string) $deck->refresh()->body, true);
    expect($deck->name)->toBe('Editable v2')
        ->and($manifest['theme'])->toBe('dark')
        ->and($manifest['slides'][2]['title'])->toBe('Canales priorizados')
        ->and(collect($manifest['slides'])->pluck('layout'))->not->toContain('quote');

    // An invalid edit is rejected atomically — nothing changes.
    SapiensServer::actingAs($this->user)
        ->tool(UpdatePresentationTool::class, [
            'document_id' => $deck->id,
            'operations' => [
                ['op' => 'replace', 'index' => 0, 'slide' => ['layout' => 'bullets', 'title' => 'x', 'bullets' => ['solo uno']]],
            ],
        ])
        ->assertHasErrors();
    expect(json_decode((string) $deck->refresh()->body, true)['slides'][0]['layout'])->toBe('title');
});

it('accepts live data bindings in charts and metrics', function () {
    $errors = (new DeckValidator)->validate([
        'title' => 'Live',
        'slides' => [
            ['layout' => 'chart', 'title' => 'Tareas por estado', 'chart_type' => 'donut',
                'data_source' => ['app_slug' => 'tracker', 'object' => 'tareas', 'group_by' => 'estado', 'aggregation' => 'count']],
            ['layout' => 'metrics', 'items' => [
                ['label' => 'Clientes', 'value_source' => ['app_slug' => 'tracker', 'object' => 'clientes', 'aggregation' => 'count']],
                ['value' => '12', 'label' => 'Estático'],
            ]],
        ],
    ]);
    expect($errors)->toBe([]);

    $errors = (new DeckValidator)->validate([
        'title' => 'Broken live',
        'slides' => [
            ['layout' => 'chart', 'title' => 'x', 'chart_type' => 'bar',
                'data_source' => ['app_slug' => 'tracker', 'object' => 'tareas', 'group_by' => 'estado', 'aggregation' => 'sum']],
        ],
    ]);
    expect(implode("\n", $errors))->toContain('data_source.field: required');
});

it('mints a signed share link that renders read-only without a session', function () {
    $deck = Document::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'name' => 'Compartible',
        'keywords' => [],
        'type' => DocumentType::Deck,
        'body' => json_encode(['title' => 'Compartible', 'slides' => validSlides()]),
        'visibility' => 'private',
        'metadata' => ['theme' => 'executive', 'slide_count' => 8],
    ]);

    $url = $this->actingAs($this->user)
        ->postJson(route('slides.share', ['document' => $deck->id]))
        ->assertOk()
        ->json('url');

    expect($url)->toContain('/share/p/'.$deck->id);

    // The link works with NO authenticated session.
    $this->post(route('logout'));
    $this->get($url)->assertOk();

    // Tampering with the signature is rejected.
    $this->get(str_replace('signature=', 'signature=dead', $url))->assertForbidden();

    // The print route requires a signature too.
    $this->get(route('slides.print', ['document' => $deck->id]))->assertForbidden();
});

it('opens the builder, applies direct ops via PATCH and queues AI turns', function () {
    Queue::fake([RunSlideBuilderJob::class]);

    $deck = Document::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'name' => 'Builder deck',
        'keywords' => [],
        'type' => DocumentType::Deck,
        'body' => json_encode(['title' => 'Builder deck', 'theme' => 'executive', 'slides' => validSlides()]),
        'visibility' => 'private',
        'metadata' => ['theme' => 'executive', 'slide_count' => 8],
    ]);

    $this->actingAs($this->user)
        ->get(route('slides.builder', ['document' => $deck->id]))
        ->assertOk();

    // Direct edit: duplicate the bullets slide via insert, rename + retheme.
    $response = $this->actingAs($this->user)
        ->patchJson(route('slides.update', ['document' => $deck->id]), [
            'name' => 'Builder deck v2',
            'theme' => 'dark',
            'operations' => [
                ['op' => 'insert', 'index' => 3, 'slide' => [
                    'layout' => 'bullets', 'title' => 'Nuevo', 'bullets' => ['a', 'b'],
                ]],
            ],
        ])
        ->assertOk()
        ->json();

    expect($response['name'])->toBe('Builder deck v2')
        ->and($response['manifest']['theme'])->toBe('dark')
        ->and($response['manifest']['slides'])->toHaveCount(9)
        ->and($response['resolved']['slides'])->toHaveCount(9);

    // An invalid op is a 422 and persists nothing.
    $this->actingAs($this->user)
        ->patchJson(route('slides.update', ['document' => $deck->id]), [
            'operations' => [['op' => 'remove', 'index' => 99]],
        ])
        ->assertStatus(422);
    expect(json_decode((string) $deck->refresh()->body, true)['slides'])->toHaveCount(9);

    // Chat message queues the builder AI turn and returns the placeholder id.
    $messageId = $this->actingAs($this->user)
        ->postJson(route('slides.builder.message', ['document' => $deck->id]), [
            'content' => 'Haz el slide 2 más ejecutivo',
        ])
        ->assertStatus(202)
        ->json('message_id');

    expect($messageId)->toStartWith('sbm_');
    Queue::assertPushed(RunSlideBuilderJob::class, function ($job) use ($deck) {
        return $job->documentId === $deck->id
            && $job->userText === 'Haz el slide 2 más ejecutivo';
    });

    // A stranger cannot touch the builder surface.
    $stranger = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($stranger)
        ->patchJson(route('slides.update', ['document' => $deck->id]), [
            'operations' => [['op' => 'remove', 'index' => 0]],
        ])
        ->assertNotFound();
});

it('validates the timeline and table layouts and the new themes', function () {
    $validator = new DeckValidator;

    expect($validator->validate([
        'title' => 'F3',
        'theme' => 'minimal',
        'slides' => [
            ['layout' => 'timeline', 'title' => 'Roadmap 90 días', 'items' => [
                ['label' => 'F1', 'title' => 'Ignición', 'status' => 'done'],
                ['label' => 'F2', 'title' => 'Motor', 'status' => 'active', 'description' => 'Contenido constante'],
                ['label' => 'F3', 'title' => 'Palanca', 'status' => 'upcoming'],
            ]],
            ['layout' => 'table', 'title' => 'Canales', 'columns' => ['Canal', 'Esfuerzo', 'Meta'], 'rows' => [
                ['Build in public', '60%', '40 clientes'],
                ['Comunidades', '25%', '30 clientes'],
            ]],
        ],
    ]))->toBe([]);

    expect((new DeckValidator)->validate(['title' => 'x', 'theme' => 'bold', 'slides' => validSlides()]))->toBe([]);

    $errors = $validator->validate([
        'title' => 'Broken',
        'slides' => [
            ['layout' => 'timeline', 'title' => 'x', 'items' => [
                ['label' => 'a', 'title' => 'b', 'status' => 'someday'],
            ]],
            ['layout' => 'table', 'title' => 'x', 'columns' => ['A', 'B'], 'rows' => [['solo una celda']]],
        ],
    ]);

    expect(implode("\n", $errors))
        ->toContain('slides.0.items: 2 to 6 items')
        ->toContain('slides.1.rows.0: exactly one cell per column (2)');
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
