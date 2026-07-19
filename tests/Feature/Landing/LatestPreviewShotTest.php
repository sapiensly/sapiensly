<?php

use App\Models\App;
use App\Models\User;
use App\Services\Landing\LatestPreviewShot;
use App\Services\Storage\TenantStorage;
use App\Support\Storage\TenantPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Make the global S3 fallback resolvable, then fake it — the shot machinery
    // is disk-agnostic; tests only need a name Storage::fake can own.
    config([
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
        'filesystems.disks.s3.bucket' => 'test-bucket',
        'filesystems.disks.s3.region' => 'auto',
    ]);
    Storage::fake('s3');
});

function shotApp(): App
{
    $user = User::factory()->create();

    return App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => 'shot_app',
    ]);
}

function shotPath(App $app): string
{
    return TenantPath::scope($app->organization_id, $app->user_id, 'builder_screenshots/'.$app->id.'/latest_preview.jpg');
}

it('resolves a fresh preview shot as a StoredImage', function () {
    $app = shotApp();
    $disk = app(TenantStorage::class)->diskName($app);
    Storage::fake($disk);
    Storage::disk($disk)->put(shotPath($app), 'jpeg-bytes');

    expect(app(LatestPreviewShot::class)->for($app))->not->toBeNull();
});

it('returns null when no shot exists', function () {
    $app = shotApp();
    Storage::fake(app(TenantStorage::class)->diskName($app));

    expect(app(LatestPreviewShot::class)->for($app))->toBeNull();
});

it('returns null for a stale shot (older than the freshness window)', function () {
    $app = shotApp();
    $disk = app(TenantStorage::class)->diskName($app);
    Storage::fake($disk);
    Storage::disk($disk)->put(shotPath($app), 'jpeg-bytes');

    // Fast-forward past the 15-minute freshness window.
    $this->travel(20)->minutes();

    expect(app(LatestPreviewShot::class)->for($app))->toBeNull();

    $this->travelBack();
});

it('stores the shot via the builder endpoint at the deterministic path', function () {
    $app = shotApp();
    $disk = app(TenantStorage::class)->diskName($app);
    Storage::fake($disk);
    $owner = User::query()->findOrFail($app->user_id);

    $this->actingAs($owner)
        ->post("/apps/{$app->id}/builder/preview-shot", [
            'screenshot' => UploadedFile::fake()->image('latest_preview.jpg', 800, 600),
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    Storage::disk($disk)->assertExists(shotPath($app));
    // Fresh upload → resolvable for the critique.
    expect(app(LatestPreviewShot::class)->for($app))->not->toBeNull();
});

it('rejects the endpoint for a stranger', function () {
    $app = shotApp();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post("/apps/{$app->id}/builder/preview-shot", [
            'screenshot' => UploadedFile::fake()->image('x.jpg'),
        ])
        ->assertStatus(403);
});
