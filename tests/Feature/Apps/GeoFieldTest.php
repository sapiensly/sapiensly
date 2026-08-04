<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordValidationException;
use App\Services\Records\RecordWriteService;
use Illuminate\Support\Str;

/**
 * A point on the earth.
 *
 * The first genuinely NEW value shape of the sensor wave — a photo, a signature
 * and a barcode were all a file or a string wearing a different hat, and could
 * be options on an existing type. {lat, lng} is not, so this one pays the full
 * price of a type.
 *
 * What it stores is where somebody CHOSE to record, never an automatic stamp:
 * tracking staff is a decision an app owner has to make deliberately and tell
 * their people about, not one this platform makes for them by default.
 */
function geoApp(User $owner, array $overrides = []): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'geo_'.Str::lower(Str::random(6)),
        'name' => 'Visitas',
    ]);

    $manifest = array_replace_recursive([
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Visitas',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_visitas0001',
            'name' => 'Visitas',
            'slug' => 'visitas',
            'fields' => [
                ['id' => 'fld_sitio000001', 'name' => 'Sitio', 'slug' => 'sitio', 'type' => 'string'],
                ['id' => 'fld_donde000001', 'name' => 'Dónde', 'slug' => 'donde', 'type' => 'geo'],
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ], $overrides);

    app(AppManifestService::class)->createVersion($app, $manifest, $owner);

    return $app->fresh();
}

function writeVisit(App $app, mixed $point): Record
{
    return app(RecordWriteService::class)->create(
        $app,
        app(AppManifestService::class)->getActiveManifest($app),
        'obj_visitas0001',
        ['sitio' => 'Planta norte', 'donde' => $point],
    );
}

it('stores a point, keeping how sure the device was', function () {
    // "Within 8 metres" and "within 3 kilometres" are very different claims
    // about the same coordinates, and only the device knows which it made.
    $owner = User::factory()->create();
    $app = geoApp($owner);

    $record = writeVisit($app, ['lat' => 19.4326, 'lng' => -99.1332, 'accuracy' => 8]);

    // Loose on the accuracy's type: it round-trips through JSONB and comes
    // back as an int, which is a perfectly good number of metres.
    expect($record->data['donde']['lat'])->toBe(19.4326)
        ->and($record->data['donde']['lng'])->toBe(-99.1332)
        ->and($record->data['donde']['accuracy'])->toEqual(8);
});

it('takes a point with no accuracy at all', function () {
    // Typed by hand, or off a survey: there is no device to have measured it.
    $owner = User::factory()->create();
    $app = geoApp($owner);

    $record = writeVisit($app, ['lat' => 19.4326, 'lng' => -99.1332]);

    expect($record->data['donde'])->toBe(['lat' => 19.4326, 'lng' => -99.1332]);
});

it('refuses coordinates that are not on the earth', function () {
    // A longitude of 200 is not a place. Caught here rather than on a map that
    // silently draws nothing.
    $owner = User::factory()->create();
    $app = geoApp($owner);

    expect(fn () => writeVisit($app, ['lat' => 19.4, 'lng' => 200]))
        ->toThrow(RecordValidationException::class);

    expect(fn () => writeVisit($app, ['lat' => 91, 'lng' => 0]))
        ->toThrow(RecordValidationException::class);
});

it('refuses half a coordinate', function () {
    $owner = User::factory()->create();
    $app = geoApp($owner);

    expect(fn () => writeVisit($app, ['lat' => 19.4]))
        ->toThrow(RecordValidationException::class);
});

it('names the field when it refuses one', function () {
    // The message is the only thing that reaches somebody debugging a workflow.
    $owner = User::factory()->create();
    $app = geoApp($owner);

    try {
        writeVisit($app, ['lat' => 'norte', 'lng' => 'oeste']);
        $this->fail('Expected the write to be refused.');
    } catch (RecordValidationException $e) {
        expect($e->getMessage())->toContain('donde')
            ->and($e->errors)->toHaveKey('donde');
    }
});

it('lets a map be addressed by one geo field instead of two number ones', function () {
    // The pair is how every app written before this type says it, and those
    // apps have to keep working — so the block takes either, and the schema
    // insists on exactly one of the two ways.
    $owner = User::factory()->create();

    $app = geoApp($owner, ['pages' => [[
        'id' => 'pag_mapa000001',
        'slug' => 'mapa',
        'path' => '/mapa',
        'name' => 'Mapa',
        'blocks' => [[
            'id' => 'blk_mapa000001',
            'type' => 'map',
            'data_source' => ['object_id' => 'obj_visitas0001'],
            'geo_field_id' => 'fld_donde000001',
            'popup_field_id' => 'fld_sitio000001',
        ]],
    ]]]);

    expect($app->current_version_id)->not->toBeNull();
});

it('still accepts a map addressed the old way', function () {
    $owner = User::factory()->create();

    $app = geoApp($owner, [
        'objects' => [[
            'id' => 'obj_visitas0001',
            'fields' => [
                ['id' => 'fld_lat00000001', 'name' => 'Lat', 'slug' => 'lat', 'type' => 'number'],
                ['id' => 'fld_lng00000001', 'name' => 'Lng', 'slug' => 'lng', 'type' => 'number'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_mapa000001',
            'slug' => 'mapa',
            'path' => '/mapa',
            'name' => 'Mapa',
            'blocks' => [[
                'id' => 'blk_mapa000001',
                'type' => 'map',
                'data_source' => ['object_id' => 'obj_visitas0001'],
                'lat_field_id' => 'fld_lat00000001',
                'lng_field_id' => 'fld_lng00000001',
            ]],
        ]],
    ]);

    expect($app->current_version_id)->not->toBeNull();
});

it('refuses a map that says neither way', function () {
    $owner = User::factory()->create();

    expect(fn () => geoApp($owner, ['pages' => [[
        'id' => 'pag_mapa000001',
        'slug' => 'mapa',
        'path' => '/mapa',
        'name' => 'Mapa',
        'blocks' => [[
            'id' => 'blk_mapa000001',
            'type' => 'map',
            'data_source' => ['object_id' => 'obj_visitas0001'],
        ]],
    ]]]))->toThrow(Exception::class);
});
