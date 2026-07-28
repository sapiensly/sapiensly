<?php

use App\Enums\DocumentType;
use App\Models\App;
use App\Models\Chat;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use App\Support\Ai\SpendArtifact;

/**
 * The registry that turns a spend subject into something a person can name.
 * A feature test by nature: resolving a name is a database read.
 */
it('maps a known model to its slug and id', function () {
    $app = App::factory()->create();

    expect(SpendArtifact::of($app))->toBe(['subject_type' => 'app', 'subject_id' => $app->id])
        ->and(SpendArtifact::typeFor($app))->toBe('app');
});

it('records nothing for a model it does not know', function () {
    // An unrecognised subject must record as unattributed rather than as a slug
    // the read side cannot resolve.
    expect(SpendArtifact::of(new Organization))->toBeNull()
        ->and(SpendArtifact::of(null))->toBeNull();
});

it('resolves a batch of subjects to names and kinds', function () {
    $user = User::factory()->create();
    $app = App::factory()->create(['name' => 'Order Desk']);
    $chat = Chat::create(['user_id' => $user->id, 'title' => 'Pricing questions']);

    $resolved = SpendArtifact::resolve([
        ['app', $app->id],
        ['chat', $chat->id],
        ['app', 'app_gone'],       // an id with no row behind it
        ['nonsense', 'whatever'],  // an unknown slug
    ]);

    expect($resolved["app:{$app->id}"])->toBe(['name' => 'Order Desk', 'kind' => 'App'])
        ->and($resolved["chat:{$chat->id}"])->toBe(['name' => 'Pricing questions', 'kind' => 'Chat'])
        ->and($resolved)->not->toHaveKey('app:app_gone')
        ->and($resolved)->not->toHaveKey('nonsense:whatever');
});

it('says which kind of document a subject is', function () {
    $user = User::factory()->create();
    $deck = Document::create([
        'user_id' => $user->id,
        'name' => 'Q3 Review',
        'type' => DocumentType::Deck,
        'body' => '{}',
    ]);

    // A slide deck and an uploaded PDF are both Documents; the kind has to tell
    // them apart or "Document" is all the dashboard can ever say.
    expect(SpendArtifact::resolve([['document', $deck->id]])["document:{$deck->id}"])
        ->toBe(['name' => 'Q3 Review', 'kind' => 'Presentation']);
});

it('still names a soft-deleted artifact', function () {
    $user = User::factory()->create();
    $deck = Document::create([
        'user_id' => $user->id,
        'name' => 'Deleted deck',
        'type' => DocumentType::Deck,
        'body' => '{}',
    ]);
    $deck->delete();

    // The spend it caused is still in the ledger, so it still needs a name.
    expect(SpendArtifact::resolve([['document', $deck->id]])["document:{$deck->id}"]['name'])
        ->toBe('Deleted deck');
});
