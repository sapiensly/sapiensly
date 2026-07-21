<?php

use App\Ai\Tools\Builder\CritiqueLandingDesignTool;
use App\Events\Builder\BuilderDraftShotRequested;
use App\Models\App;
use App\Models\User;
use App\Services\Landing\DraftPreviewShot;
use App\Services\Landing\LandingDesignCritic;
use App\Services\Manifest\AppManifestService;
use App\Support\Tenancy\TenantCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Tools\Request as ToolRequest;

function draftShotApp(): App
{
    $user = User::factory()->create();

    return App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => 'draft_shot_app',
    ]);
}

/** @return array<string, mixed> a minimal landing draft manifest */
function draftShotManifest(): array
{
    return [
        'settings' => ['surface' => 'landing', 'custom_css' => '.hero { color: red; }'],
        'objects' => [],
        'pages' => [[
            'id' => 'pag_draftshot1', 'slug' => 'home', 'name' => 'Home', 'path' => '/', 'blocks' => [
                ['id' => 'htm_draftshot', 'type' => 'html', 'content' => '<section class="hero"><h1>Hola</h1></section>'],
            ],
        ]],
    ];
}

/** Short waits so the no-browser paths cost milliseconds, not seconds. */
function fastDraftShots(): DraftPreviewShot
{
    $service = app(DraftPreviewShot::class);
    $service->ackTimeoutMs = 150;
    $service->shotTimeoutMs = 300;
    $service->pollIntervalMs = 20;

    return $service;
}

it('bails fast with null when no browser claims the nonce', function () {
    Event::fake([BuilderDraftShotRequested::class]);
    $app = draftShotApp();

    $shot = fastDraftShots()->capture($app, 'cnv_test', draftShotManifest());

    expect($shot)->toBeNull();
    Event::assertDispatched(BuilderDraftShotRequested::class, fn ($e) => $e->conversationId === 'cnv_test');
});

it('returns null without broadcasting for a non-landing manifest', function () {
    Event::fake([BuilderDraftShotRequested::class]);
    $app = draftShotApp();

    $manifest = draftShotManifest();
    unset($manifest['settings']['surface']);

    expect(fastDraftShots()->capture($app, 'cnv_test', $manifest))->toBeNull();
    Event::assertNotDispatched(BuilderDraftShotRequested::class);
});

it('completes the rendezvous: claim acks, the posted shot comes back as a StoredImage', function () {
    Storage::fake('local');
    $app = draftShotApp();
    $service = fastDraftShots();

    // Simulate the open builder UI: the (sync) event dispatch claims the
    // payload and posts the JPEG before capture() starts polling.
    Event::listen(BuilderDraftShotRequested::class, function (BuilderDraftShotRequested $e) use ($app, $service) {
        $payload = $service->claim($app, $e->nonce);
        expect($payload)->not->toBeNull()
            ->and($payload['page']['blocks'][0]['type'])->toBe('html')
            // Author CSS arrives pre-scoped to the app surface.
            ->and($payload['custom_css'])->toContain('.sp-app-surface');
        $service->storeShot($app, $e->nonce, 'jpeg-bytes');
    });

    $shot = $service->capture($app, 'cnv_test', draftShotManifest());

    expect($shot)->not->toBeNull()
        ->and($shot->disk)->toBe('local')
        ->and(Storage::disk('local')->get($shot->path))->toBe('jpeg-bytes');

    // cleanup() removes the materialised temp file.
    $service->cleanup($shot);
    Storage::disk('local')->assertMissing($shot->path);
});

it('rejects a shot for a nonce that was never claimed', function () {
    $app = draftShotApp();

    expect(fastDraftShots()->storeShot($app, 'nonce-nobody-requested', 'jpeg-bytes'))->toBeFalse();
});

it('serves the claim endpoint to the owner and 404s an unknown nonce', function () {
    $app = draftShotApp();
    $owner = User::query()->findOrFail($app->user_id);
    $service = fastDraftShots();

    // A capture whose nonce nobody claims still leaves the payload cached —
    // grab the nonce from the dispatched event to drive the endpoints.
    $nonce = null;
    Event::listen(BuilderDraftShotRequested::class, function (BuilderDraftShotRequested $e) use (&$nonce) {
        $nonce = $e->nonce;
    });
    $service->capture($app, 'cnv_test', draftShotManifest());
    expect($nonce)->not->toBeNull();

    $this->actingAs($owner)
        ->getJson("/apps/{$app->id}/builder/draft-shot/{$nonce}")
        ->assertOk()
        ->assertJsonPath('page.blocks.0.type', 'html');

    // The claim acked, so the posted shot is now accepted.
    $this->actingAs($owner)
        ->post("/apps/{$app->id}/builder/draft-shot/{$nonce}", [
            'screenshot' => UploadedFile::fake()->image('draft.jpg', 800, 600),
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $this->actingAs($owner)
        ->getJson("/apps/{$app->id}/builder/draft-shot/nonce-that-does-not-exist")
        ->assertNotFound();
});

it('the builder critique judges draft pixels first and reports judged_pixels=draft', function () {
    Storage::fake('local');
    Storage::disk('local')->put('tmp/draft-shots/faked.jpg', 'jpeg-bytes');

    $app = draftShotApp();
    $owner = User::query()->findOrFail($app->user_id);
    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);
    $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['surface' => 'landing']);
    $manifest['pages'] = draftShotManifest()['pages'];
    $manifests->createVersion($app, $manifest, $owner, 'seed');

    // A fake Stage 2 that "captures" instantly — the tool must prefer it over
    // LatestPreviewShot and label the pixels as draft.
    $fake = new class(app(TenantCache::class)) extends DraftPreviewShot
    {
        public ?string $conversation = null;

        public function capture(App $app, string $conversationId, array $manifest): ?StoredImage
        {
            $this->conversation = $conversationId;

            return new StoredImage('tmp/draft-shots/faked.jpg', 'local');
        }
    };
    app()->instance(DraftPreviewShot::class, $fake);

    $tool = new CritiqueLandingDesignTool(
        $app->refresh(), $manifests, app(LandingDesignCritic::class),
        user: null, proposeTool: null, conversationId: 'cnv_stage2',
    );
    $result = json_decode($tool->handle(new ToolRequest(['intent' => 'test landing', 'round' => 1])), true);

    expect($result['judged_pixels'])->toBe('draft')
        ->and($fake->conversation)->toBe('cnv_stage2');
    // The materialised draft shot is cleaned up after the critique.
    Storage::disk('local')->assertMissing('tmp/draft-shots/faked.jpg');
});

it('the builder critique degrades to text-only when no shot source answers', function () {
    $app = draftShotApp();
    $owner = User::query()->findOrFail($app->user_id);
    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);
    $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['surface' => 'landing']);
    $manifest['pages'] = draftShotManifest()['pages'];
    $manifests->createVersion($app, $manifest, $owner, 'seed');

    $service = fastDraftShots();
    app()->instance(DraftPreviewShot::class, $service);

    $tool = new CritiqueLandingDesignTool(
        $app->refresh(), $manifests, app(LandingDesignCritic::class),
        user: null, proposeTool: null, conversationId: 'cnv_stage2',
    );
    $result = json_decode($tool->handle(new ToolRequest(['intent' => 'test landing', 'round' => 1])), true);

    expect($result['judged_pixels'])->toBeFalse();
});

it('rejects the endpoints for a stranger', function () {
    $app = draftShotApp();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->getJson("/apps/{$app->id}/builder/draft-shot/whatever")
        ->assertStatus(403);

    $this->actingAs($stranger)
        ->post("/apps/{$app->id}/builder/draft-shot/whatever", [
            'screenshot' => UploadedFile::fake()->image('x.jpg'),
        ])
        ->assertStatus(403);
});
