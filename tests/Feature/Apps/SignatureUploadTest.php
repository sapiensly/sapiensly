<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The other half of the signature: what happens to the bytes.
 *
 * The browser test proves the pad turns ink into a PNG and posts it; this one
 * proves the endpoint it posts to turns that PNG into an ordinary file value.
 * They are split because a served browser test has no object storage — and
 * pretending otherwise would mean a test that passes for the wrong reason.
 *
 * What matters here is that a signature is NOT special: it goes through the
 * same upload, comes back in the same shape, and lands in the record the same
 * way an attached contract does. That is the whole argument for making it an
 * option on `file` rather than a type of its own.
 */
/**
 * Stand up object storage for an upload test.
 *
 * `Storage::fake('s3')` alone is not enough: TenantStorage decides WHICH disk
 * to write to before any disk is touched, and with a blank key/secret/bucket it
 * refuses outright (TenantStorageNotConfiguredException → 503). So the upload
 * tests passed only on a machine whose .env happened to have S3 filled in, and
 * failed on a fresh checkout and in CI.
 *
 * Configured here rather than in phpunit.xml because a global value would make
 * every OTHER test believe S3 is real — the export download path reaches for
 * the bucket the moment it thinks one exists. Scoped and explicit, like a test
 * that needs a provider key setting it in config().
 */
function fakeObjectStorage(): void
{
    config([
        'filesystems.disks.s3.key' => 'testing',
        'filesystems.disks.s3.secret' => 'testing',
        'filesystems.disks.s3.bucket' => 'sapiensly-testing',
    ]);

    Storage::fake('s3');
}

function signatureUploadApp(User $owner, array $fieldOverrides = []): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'sig_'.Str::lower(Str::random(6)),
        'name' => 'Entregas',
    ]);

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Entregas',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_entregas0001',
            'name' => 'Entregas',
            'slug' => 'entregas',
            'fields' => [
                array_merge([
                    'id' => 'fld_firma000001',
                    'name' => 'Firma de recibido',
                    'slug' => 'firma',
                    'type' => 'file',
                    'capture' => 'signature',
                ], $fieldOverrides),
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    // Through the service, not straight into the table: the schema check is
    // the thing under test in one of these, and writing the row by hand would
    // have skipped it.
    app(AppManifestService::class)->createVersion($app, $manifest, $owner);

    return $app->fresh();
}

it('stores a signed PNG exactly as it stores any attachment', function () {
    fakeObjectStorage();

    $owner = User::factory()->create();
    $app = signatureUploadApp($owner);

    $response = $this->actingAs($owner)
        ->post("/r/{$app->slug}/uploads", [
            'file' => UploadedFile::fake()->image('firma.png', 400, 160),
        ])
        ->assertCreated();

    // The shape the field puts in the record — the same one a contract gets.
    expect($response->json())->toHaveKeys([
        'file_id', 'original_name', 'mime', 'size_bytes', 'url',
    ])->and($response->json('original_name'))->toBe('firma.png');
});

it('is a manifest a validator accepts', function () {
    // A capture option the schema rejected would fail at save time, long after
    // the model had written it and told somebody it was done.
    $owner = User::factory()->create();
    $app = signatureUploadApp($owner);

    expect($app->current_version_id)->not->toBeNull();
});

it('refuses a capture mode nobody implements', function () {
    // The enum is the contract: an author (or a model) inventing
    // capture:"fingerprint" must be told at save time, not discover it as a
    // field that renders nothing.
    $owner = User::factory()->create();

    expect(fn () => signatureUploadApp($owner, ['capture' => 'fingerprint']))
        ->toThrow(Exception::class);
});

it('is closed to somebody who cannot reach the app', function () {
    fakeObjectStorage();

    $owner = User::factory()->create();
    $app = signatureUploadApp($owner);

    $this->actingAs(User::factory()->create())
        ->post("/r/{$app->slug}/uploads", [
            'file' => UploadedFile::fake()->image('firma.png'),
        ])
        ->assertNotFound();
});
