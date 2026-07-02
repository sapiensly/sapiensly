<?php

use App\Enums\DocumentType;
use App\Jobs\RefreshDeckJob;
use App\Models\DeckVersion;
use App\Models\Document;
use App\Models\User;
use App\Services\Slides\DeckDataResolver;
use App\Services\Slides\DeckEditor;
use App\Services\Slides\DeckNarrator;
use App\Services\Slides\DeckVersioner;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

function makeDeck(User $user, array $extraMetadata = []): Document
{
    $manifest = [
        'title' => 'Living deck',
        'theme' => 'executive',
        'slides' => [
            ['layout' => 'title', 'title' => 'Living deck'],
            ['layout' => 'bullets', 'title' => 'Puntos', 'bullets' => ['uno', 'dos']],
        ],
    ];

    return Document::create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'name' => 'Living deck',
        'keywords' => [],
        'type' => DocumentType::Deck,
        'body' => json_encode($manifest),
        'visibility' => 'private',
        'metadata' => array_merge(['theme' => 'executive', 'slide_count' => 2], $extraMetadata),
    ]);
}

it('records versions on edit, coalesces a typing session, and restores', function () {
    $deck = makeDeck($this->user);

    // Two rapid edits by the same user → ONE coalesced edit version.
    foreach (['Puntos v2', 'Puntos v3'] as $title) {
        $this->actingAs($this->user)
            ->patchJson(route('slides.update', ['document' => $deck->id]), [
                'operations' => [[
                    'op' => 'replace', 'index' => 1,
                    'slide' => ['layout' => 'bullets', 'title' => $title, 'bullets' => ['uno', 'dos']],
                ]],
            ])
            ->assertOk();
    }

    $versions = DeckVersion::where('document_id', $deck->id)->orderBy('version_number')->get();
    expect($versions)->toHaveCount(1)
        ->and($versions[0]->cause)->toBe('edit')
        ->and($versions[0]->source_manifest['slides'][1]['title'])->toBe('Puntos v3')
        ->and($versions[0]->created_by_user_id)->toBe($this->user->id);

    // History endpoint lists it.
    $this->actingAs($this->user)
        ->getJson(route('slides.versions', ['document' => $deck->id]))
        ->assertOk()
        ->assertJsonPath('versions.0.cause', 'edit')
        ->assertJsonPath('versions.0.number', 1);

    // Break the coalescing window, edit again → a second version.
    $versions[0]->forceFill(['created_at' => now()->subHour()])->save();
    $this->actingAs($this->user)
        ->patchJson(route('slides.update', ['document' => $deck->id]), [
            'name' => 'Living deck v2',
        ])
        ->assertOk();
    expect(DeckVersion::where('document_id', $deck->id)->count())->toBe(2);

    // Restore version 1 → deck body reverts, history grows (append-only).
    $first = DeckVersion::where('document_id', $deck->id)->where('version_number', 1)->sole();
    $this->actingAs($this->user)
        ->postJson(route('slides.versions.restore', ['document' => $deck->id, 'version' => $first->id]))
        ->assertOk()
        ->assertJsonPath('name', 'Living deck');

    expect(DeckVersion::where('document_id', $deck->id)->count())->toBe(3)
        ->and(DeckVersion::where('document_id', $deck->id)->orderByDesc('version_number')->first()->cause)->toBe('restore')
        ->and(json_decode((string) $deck->refresh()->body, true)['title'])->toBe('Living deck');
});

it('stores refresh settings and views a historical version with as-of', function () {
    $deck = makeDeck($this->user);

    $this->actingAs($this->user)
        ->patchJson(route('slides.update', ['document' => $deck->id]), [
            'refresh' => ['frequency' => 'daily', 'hour' => 7, 'narrative' => true],
        ])
        ->assertOk()
        ->assertJsonPath('refresh.frequency', 'daily');

    expect($deck->refresh()->metadata['refresh']['frequency'])->toBe('daily');

    // Create a version, then open it read-only via ?v=.
    $this->actingAs($this->user)
        ->patchJson(route('slides.update', ['document' => $deck->id]), ['name' => 'v2'])
        ->assertOk();

    $this->actingAs($this->user)
        ->get(route('slides.present', ['document' => $deck->id]).'?v=1')
        ->assertOk();

    // A share link frozen to version 1 renders without a session.
    $url = $this->actingAs($this->user)
        ->postJson(route('slides.share', ['document' => $deck->id]), ['version' => 1])
        ->assertOk()
        ->json('url');
    expect($url)->toContain('v=1');

    $this->post(route('logout'));
    $this->get($url)->assertOk();
});

it('refresh job skips versioning when data did not change, and queues from the endpoint', function () {
    Queue::fake([RefreshDeckJob::class]);
    $deck = makeDeck($this->user);

    $this->actingAs($this->user)
        ->postJson(route('slides.refresh', ['document' => $deck->id]))
        ->assertStatus(202);

    Queue::assertPushed(RefreshDeckJob::class, fn ($job) => $job->documentId === $deck->id
        && $job->cause === 'manual_refresh');

    // Run the job inline: deck has no live bindings and no prior version →
    // first refresh records the baseline version; a second identical refresh
    // records nothing new. The narrator must never be invoked (no changes).
    $this->mock(DeckNarrator::class)->shouldNotReceive('narrate');

    $run = fn () => (new RefreshDeckJob($deck->id, $this->user->organization_id, $this->user->id, 'manual_refresh'))
        ->handle(
            app(DeckDataResolver::class),
            app(DeckVersioner::class),
            app(DeckEditor::class),
            app(DeckNarrator::class),
        );

    $run();
    expect(DeckVersion::where('document_id', $deck->id)->count())->toBe(1)
        ->and($deck->refresh()->metadata['refresh']['last_refreshed_at'] ?? null)->not->toBeNull();

    $run();
    expect(DeckVersion::where('document_id', $deck->id)->count())->toBe(1);
});
