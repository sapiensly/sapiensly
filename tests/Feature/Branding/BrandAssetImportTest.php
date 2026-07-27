<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Importing a remote logo runs only when a user accepts a proposal — proposing
 * writes nothing anywhere, including to storage.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    config()->set('security.ssrf.enabled', false); // the guard has its own tests

    config([
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
        'filesystems.disks.s3.bucket' => 'test-bucket',
    ]);
    Storage::fake('s3');

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
        'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
    ]);
});

it('copies a remote logo onto the tenant disk so the brand does not depend on someone else\'s server', function () {
    Http::fake(['*' => Http::response('binary-png-bytes', 200, ['Content-Type' => 'image/png'])]);

    $response = $this->actingAs($this->owner)
        ->postJson('/settings/organization/brand/asset/import', [
            'kind' => 'logo',
            'url' => 'https://acme.example/logo.png',
        ])
        ->assertOk()
        ->assertJsonPath('kind', 'logo');

    expect(Storage::disk('s3')->allFiles("org/{$this->org->id}/org-brand"))->not->toBeEmpty()
        ->and($response->json('url'))->toContain("brand-asset/{$this->org->id}/logo-");
});

it('imports a favicon, which is very often an .ico', function () {
    Http::fake(['*' => Http::response('ico-bytes', 200, ['Content-Type' => 'image/vnd.microsoft.icon'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/brand/asset/import', [
            'kind' => 'icon',
            'url' => 'https://acme.example/favicon.ico',
        ])
        ->assertOk();

    expect(Storage::disk('s3')->allFiles("org/{$this->org->id}/org-brand")[0])->toEndWith('.ico');
});

/**
 * The upload path accepts SVG because a human picked that file. This path takes
 * whatever a third-party host serves, and the result is streamed back from our
 * own origin by an unauthenticated route — so it does not.
 */
it('refuses to import an svg even though uploading one is allowed', function () {
    Http::fake(['*' => Http::response('<svg onload="alert(1)"></svg>', 200, ['Content-Type' => 'image/svg+xml'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/brand/asset/import', [
            'kind' => 'logo',
            'url' => 'https://acme.example/logo.svg',
        ])
        ->assertStatus(422);

    expect(Storage::disk('s3')->allFiles("org/{$this->org->id}/org-brand"))->toBeEmpty();
});

it('refuses anything that is not an image, and anything too large', function () {
    Http::fake(['*' => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf'])]);
    $this->actingAs($this->owner)
        ->postJson('/settings/organization/brand/asset/import', ['kind' => 'logo', 'url' => 'https://acme.example/a.pdf'])
        ->assertStatus(422);

    Http::fake(['*' => Http::response(str_repeat('x', 3 * 1024 * 1024), 200, ['Content-Type' => 'image/png'])]);
    $this->actingAs($this->owner)
        ->postJson('/settings/organization/brand/asset/import', ['kind' => 'logo', 'url' => 'https://acme.example/huge.png'])
        ->assertStatus(422);

    expect(Storage::disk('s3')->allFiles("org/{$this->org->id}/org-brand"))->toBeEmpty();
});

it('reports a download that fails instead of storing nothing silently', function () {
    Http::fake(['*' => Http::response('gone', 404)]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/brand/asset/import', ['kind' => 'icon', 'url' => 'https://acme.example/x.png'])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

it('is closed to a plain member', function () {
    $member = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $member->id,
        'role' => MembershipRole::Member, 'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($member)
        ->postJson('/settings/organization/brand/asset/import', ['kind' => 'logo', 'url' => 'https://acme.example/logo.png'])
        ->assertForbidden();
});
