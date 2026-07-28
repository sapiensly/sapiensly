<?php

use App\Models\App;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * An organization whose Brandbook carries dark-surface logo/icon variants must
 * still be able to create an app.
 *
 * OrganizationBrand seeds `settings.brand` on every new manifest, including
 * `logo_dark` / `icon_dark`. The manifest schema declares `settings.brand` with
 * `additionalProperties: false`, so when those two were added to the Brandbook
 * without being added to the schema, creation blew up in production with
 * "Additional object properties are not allowed: logo_dark, icon_dark" — a 500
 * on the Create App button for any org that had set them.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = mcpOrg();
    $this->org->update(['brand' => [
        'logo_url' => 'https://cdn.example.com/logo.svg',
        'icon_url' => 'https://cdn.example.com/icon.svg',
        'logo_dark_url' => 'https://cdn.example.com/logo-dark.svg',
        'icon_dark_url' => 'https://cdn.example.com/icon-dark.svg',
        'accent_color' => '#0059ff',
    ]]);

    $this->user = mcpMember($this->org);
});

it('creates an app when the org brand has dark variants', function () {
    $this->actingAs($this->user)
        ->post('/apps', [])
        ->assertRedirect();

    expect(App::query()->where('organization_id', $this->org->id)->count())->toBe(1);
});

it('carries both brand variants into the initial manifest', function () {
    $this->actingAs($this->user)->post('/apps', []);

    $manifest = App::query()->where('organization_id', $this->org->id)->sole()
        ->currentVersion->manifest;

    expect($manifest['settings']['brand'])
        ->toMatchArray([
            'logo' => 'https://cdn.example.com/logo.svg',
            'logo_dark' => 'https://cdn.example.com/logo-dark.svg',
            'icon' => 'https://cdn.example.com/icon.svg',
            'icon_dark' => 'https://cdn.example.com/icon-dark.svg',
        ]);
});
