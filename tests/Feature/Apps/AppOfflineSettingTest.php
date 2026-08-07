<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\App;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Setting `settings.offline` from the builder's Access panel.
 *
 * It lives beside the access mode because it is the same kind of statement: the
 * mode says who may open the app, this says which of its data may still be on
 * their phone tomorrow. Until this existed an owner could only ask the chat.
 */
function aos_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);

    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
        'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
    ]);

    $this->member = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->member->id,
        'role' => MembershipRole::Member, 'status' => MembershipStatus::Active,
    ]);

    $this->testApp = App::create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'slug' => 'campo',
        'name' => 'Servicio Campo',
        'visibility' => 'organization',
    ]);

    $ordenes = aos_id('obj');
    $nominas = aos_id('obj');
    $role = aos_id('rol');

    app(AppManifestService::class)->createVersion($this->testApp, [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'campo',
        'name' => 'Servicio Campo',
        'version' => 1,
        'objects' => [
            ['id' => $ordenes, 'slug' => 'ordenes', 'name' => 'Órdenes', 'fields' => [
                ['id' => aos_id('fld'), 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
            ]],
            ['id' => $nominas, 'slug' => 'nominas', 'name' => 'Nóminas', 'fields' => [
                ['id' => aos_id('fld'), 'slug' => 'monto', 'name' => 'Monto', 'type' => 'currency'],
            ]],
        ],
        'pages' => [[
            'id' => aos_id('pag'), 'slug' => 'ordenes', 'name' => 'Órdenes', 'path' => '/ordenes',
            'blocks' => [],
        ]],
        'permissions' => [
            'roles' => [['id' => $role, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]],
        ],
    ], $this->owner);

    // createVersion points current_version_id at the new row on a freshly
    // locked model; the one in hand still remembers none.
    $this->testApp->refresh();
});

/** @return array<string, mixed> */
function aos_offline(): array
{
    return app(AppManifestService::class)
        ->getActiveManifest(test()->testApp->refresh())['settings']['offline'] ?? [];
}

it('shows the panel the app’s own objects and the current policy', function () {
    // The panel offers a choice of the app's objects rather than a text box:
    // an exclusion naming something that does not exist protects nothing.
    $this->actingAs($this->owner)
        ->getJson("/apps/{$this->testApp->id}/access")
        ->assertOk()
        ->assertJsonPath('offline.enabled', true)
        ->assertJsonPath('offline.exclude_objects', [])
        ->assertJsonPath('objects.0.slug', 'ordenes')
        ->assertJsonPath('objects.1.name', 'Nóminas');
});

it('keeps one object off the device without turning the app off', function () {
    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access/offline", [
            'enabled' => true,
            'exclude_objects' => ['nominas'],
        ])
        ->assertOk()
        ->assertJsonPath('offline.exclude_objects', ['nominas']);

    expect(aos_offline())->toBe(['enabled' => true, 'exclude_objects' => ['nominas']]);
});

it('turns the whole thing off for an app that must not be cached at all', function () {
    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access/offline", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('offline.enabled', false);

    expect(aos_offline())->toBe(['enabled' => false]);
});

it('writes nothing when the app is left at the default', function () {
    // Absent MEANS the default. Storing a no-op would put a setting nobody
    // chose into every manifest and make "unset" stop reading as "never asked".
    $before = $this->testApp->refresh()->current_version_id;

    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access/offline", [
            'enabled' => true,
            'exclude_objects' => [],
        ])
        ->assertOk()
        ->assertJsonPath('offline.enabled', true);

    expect(aos_offline())->toBe([])
        ->and($this->testApp->refresh()->current_version_id)->toBe($before);
});

it('clears the setting when it goes back to the default', function () {
    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access/offline", [
            'enabled' => true, 'exclude_objects' => ['nominas'],
        ])->assertOk();

    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access/offline", [
            'enabled' => true, 'exclude_objects' => [],
        ])->assertOk();

    expect(aos_offline())->toBe([]);
});

it('takes only its own key back out when the app has other settings', function () {
    // The whole-object removal above is for the case where `offline` was the
    // only setting. It must not take the app's theme with it.
    app(AppManifestService::class)->applyPatch(
        $this->testApp,
        [['op' => 'add', 'path' => '/settings', 'value' => ['theme' => 'dark', 'offline' => ['enabled' => false]]]],
        $this->owner,
    );

    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access/offline", [
            'enabled' => true, 'exclude_objects' => [],
        ])->assertOk();

    $settings = app(AppManifestService::class)
        ->getActiveManifest($this->testApp->refresh())['settings'] ?? [];

    expect($settings)->toBe(['theme' => 'dark']);
});

it('reaches the header that actually stops the page being cached', function () {
    // The panel writes the manifest; OfflinePolicy reads it; the middleware
    // stamps no-store. Each half has its own test — this is the seam between
    // them, which is where a feature that "works" usually does not.
    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access/offline", ['enabled' => false])
        ->assertOk();

    expect($this->actingAs($this->owner)->get('/r/campo/ordenes')->assertOk()->headers->get('Cache-Control'))
        ->toContain('no-store');
});

it('refuses an exclusion naming an object the app does not have', function () {
    // Accepted, it would sit in the manifest looking as though it protected
    // something. The policy resolves exclusions through the app's own objects,
    // so a typo would silently protect nothing at all.
    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access/offline", [
            'enabled' => true,
            'exclude_objects' => ['no_existe'],
        ])
        ->assertStatus(422);

    expect(aos_offline())->toBe([]);
});

it('is not a decision an ordinary member gets to make', function () {
    // Same gate as handing out roles: only someone who fully controls the app.
    $this->actingAs($this->member)
        ->postJson("/apps/{$this->testApp->id}/access/offline", ['enabled' => false])
        ->assertStatus(403);

    expect(aos_offline())->toBe([]);
});

it('leaves the change in the app’s history like any other', function () {
    // A manifest patch, so it is a version: reversible, diffable, and visible
    // to whoever asks later why the app stopped working in the basement.
    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access/offline", ['enabled' => false])
        ->assertOk();

    $this->actingAs($this->owner)
        ->getJson("/apps/{$this->testApp->id}/versions")
        ->assertOk()
        ->assertSee('Offline policy changed');
});
