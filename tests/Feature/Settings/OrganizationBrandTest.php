<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Branding\PaletteProposalService;
use App\Support\Branding\ColorPalette;
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

it('renders the brandbook page for an org admin with the current brand', function () {
    $this->org->update(['brand' => ['accent_color' => '#123456', 'font' => 'serif']]);

    $this->actingAs($this->owner)
        ->get('/settings/organization/brand')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/OrganizationBrand')
            ->where('brand.accent_color', '#123456')
            ->where('brand.font', 'serif'));
});

it('forbids a non-admin from viewing the brandbook page', function () {
    $this->actingAs($this->member)
        ->get('/settings/organization/brand')
        ->assertForbidden();
});

it('lets an org owner save the brandbook (normalized)', function () {
    $this->actingAs($this->owner)
        ->put('/settings/organization/brand', [
            'accent_color' => '#1A2B3C',
            'font' => 'serif',
            'theme' => 'dark',
            'logo_url' => 'https://cdn.example.com/logo.png',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $brand = $this->org->refresh()->brandbook();
    expect($brand->accentColor)->toBe('#1A2B3C')
        ->and($brand->font)->toBe('serif')
        ->and($brand->theme)->toBe('dark')
        ->and($brand->logoUrl)->toBe('https://cdn.example.com/logo.png');
});

it('rejects an invalid colour', function () {
    $this->actingAs($this->owner)
        ->put('/settings/organization/brand', ['accent_color' => 'blue'])
        ->assertSessionHasErrors('accent_color');
});

it('forbids a non-admin member from editing the brand', function () {
    $this->actingAs($this->member)
        ->put('/settings/organization/brand', ['accent_color' => '#000000'])
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

it('returns palette proposals for an org admin', function () {
    $this->mock(PaletteProposalService::class)
        ->shouldReceive('propose')
        ->once()
        ->withArgs(fn (string $brief) => $brief === 'fintech mexicana')
        ->andReturn([
            'proposals' => [[
                'name' => 'Executive Indigo',
                'accent' => '#4f46e5',
                'rationale' => 'Trustworthy.',
                'palette' => ColorPalette::fromAccent('#4f46e5'),
            ]],
            'generated_by' => 'ai',
        ]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/brand/palette-proposals', ['brief' => 'fintech mexicana'])
        ->assertOk()
        ->assertJsonPath('generated_by', 'ai')
        ->assertJsonPath('proposals.0.accent', '#4f46e5')
        ->assertJsonStructure(['proposals' => [['name', 'accent', 'rationale', 'palette' => ['ramp', 'soft', 'contrast', 'chart']]]]);
});

it('forbids a non-admin from generating palette proposals', function () {
    $this->actingAs($this->member)
        ->postJson('/settings/organization/brand/palette-proposals', ['brief' => 'x'])
        ->assertForbidden();
});
