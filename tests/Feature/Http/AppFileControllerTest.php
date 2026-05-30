<?php

use App\Models\App;
use App\Models\AppFile;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Pretend AWS env is wired and intercept all writes locally. */
function fakeAppFileS3(): void
{
    Config::set('filesystems.disks.s3.key', 'fake');
    Config::set('filesystems.disks.s3.secret', 'fake');
    Config::set('filesystems.disks.s3.bucket', 'fake');
    Storage::fake('s3');
}

function fileManifest(string $appId, string $slug = 'demo'): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => $slug,
        'name' => 'Demo',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    fakeAppFileS3();

    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'visibility' => 'private',
        'slug' => 'demo_'.strtolower(Str::random(6)),
    ]);
    app(AppManifestService::class)->createVersion($this->testApp, fileManifest($this->testApp->id, $this->testApp->slug), $this->user);
});

it('rejects an unauthenticated upload', function () {
    // postJson sends Accept: application/json, so the middleware throws an
    // AuthenticationException that becomes 401 rather than a redirect.
    $this->postJson("/r/{$this->testApp->slug}/uploads", [
        'file' => UploadedFile::fake()->create('a.txt', 1),
    ])->assertUnauthorized();
});

it('uploads a file and returns its metadata', function () {
    $this->actingAs($this->user);

    $file = UploadedFile::fake()->create('contract.pdf', 200, 'application/pdf');

    $response = $this->postJson("/r/{$this->testApp->slug}/uploads", ['file' => $file])
        ->assertCreated()
        ->json();

    expect($response['file_id'])->toStartWith('fil_')
        ->and($response['original_name'])->toBe('contract.pdf')
        ->and($response['mime'])->toBe('application/pdf')
        ->and($response['size_bytes'])->toBe(200 * 1024)
        ->and($response['url'])->toContain('/files/'.$response['file_id']);

    // DB row is real and points at the right App.
    $row = AppFile::query()->find($response['file_id']);
    expect($row)->not->toBeNull()
        ->and($row->app_id)->toBe($this->testApp->id)
        ->and($row->uploaded_by_user_id)->toBe($this->user->id);

    expect($row->disk)->toBe('s3');
    Storage::disk('s3')->assertExists($row->storage_path);
});

it('returns 503 on upload when no S3 disk is configured', function () {
    Config::set('filesystems.disks.s3.key', null);
    Config::set('filesystems.disks.s3.secret', null);
    Config::set('filesystems.disks.s3.bucket', null);

    $this->actingAs($this->user)
        ->postJson("/r/{$this->testApp->slug}/uploads", [
            'file' => UploadedFile::fake()->create('a.txt', 1),
        ])
        ->assertStatus(503)
        ->assertJsonPath('error', 'storage_not_configured');
});

it('serves a previously-uploaded file with auth', function () {
    $this->actingAs($this->user);

    $file = UploadedFile::fake()->create('hello.txt', 1);
    $upload = $this->postJson("/r/{$this->testApp->slug}/uploads", ['file' => $file])->json();

    $this->get("/r/{$this->testApp->slug}/files/{$upload['file_id']}")
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8');
});

it('blocks a different user from downloading a private app file', function () {
    $this->actingAs($this->user);
    $upload = $this->postJson("/r/{$this->testApp->slug}/uploads", [
        'file' => UploadedFile::fake()->create('secret.txt', 1),
    ])->json();

    $stranger = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($stranger)
        ->get("/r/{$this->testApp->slug}/files/{$upload['file_id']}")
        ->assertNotFound();
});

it('404s when the file_id is unknown', function () {
    $this->actingAs($this->user)
        ->get("/r/{$this->testApp->slug}/files/fil_does_not_exist_at_all_x")
        ->assertNotFound();
});

it('rejects uploads larger than the 100MB hard cap', function () {
    $this->actingAs($this->user);
    // 101 MB synthetic file — Laravel's validate max:kilobytes catches this.
    $huge = UploadedFile::fake()->create('big.bin', 101 * 1024);

    $this->postJson("/r/{$this->testApp->slug}/uploads", ['file' => $huge])
        ->assertStatus(422);
});
