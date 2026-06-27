<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

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
});

it('lets an org owner save the brandbook (normalized)', function () {
    $this->actingAs($this->owner)
        ->put('/settings/organization/brand', [
            'primary_color' => '#1A2B3C',
            'font' => 'serif',
            'theme' => 'dark',
            'logo_url' => 'https://cdn.example.com/logo.png',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $brand = $this->org->refresh()->brandbook();
    expect($brand->primaryColor)->toBe('#1A2B3C')
        ->and($brand->font)->toBe('serif')
        ->and($brand->theme)->toBe('dark')
        ->and($brand->logoUrl)->toBe('https://cdn.example.com/logo.png');
});

it('rejects an invalid colour', function () {
    $this->actingAs($this->owner)
        ->put('/settings/organization/brand', ['primary_color' => 'blue'])
        ->assertSessionHasErrors('primary_color');
});

it('forbids a non-admin member from editing the brand', function () {
    $this->actingAs($this->member)
        ->put('/settings/organization/brand', ['primary_color' => '#000000'])
        ->assertForbidden();

    expect($this->org->refresh()->brand)->toBeNull();
});

it('uploads a logo asset to the public disk and returns its url', function () {
    Storage::fake('public');

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/brand/asset', [
            'kind' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png', 200, 80),
        ])
        ->assertOk()
        ->assertJsonPath('kind', 'logo')
        ->assertJsonStructure(['url']);

    expect(Storage::disk('public')->allFiles("org-brand/{$this->org->id}"))->not->toBeEmpty();
});

it('rejects a non-image asset upload', function () {
    Storage::fake('public');

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/brand/asset', [
            'kind' => 'logo',
            'file' => UploadedFile::fake()->create('virus.exe', 10),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('file');
});
