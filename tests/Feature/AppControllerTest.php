<?php

use App\Models\App;
use App\Models\AppVersion;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
});

it('redirects guests away from the apps index', function () {
    $this->get('/apps')->assertRedirect('/login');
});

it('renders the apps index for authenticated users', function () {
    $this->actingAs($this->user)
        ->get('/apps')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('apps/Index'));
});

it('renders the create form', function () {
    $this->actingAs($this->user)
        ->get('/apps/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('apps/Create'));
});

it('creates an app with an initial version and redirects to show', function () {
    $response = $this->actingAs($this->user)
        ->post('/apps', [
            'name' => 'Mini CRM',
            'slug' => 'mini_crm',
            'description' => 'Test',
            'visibility' => 'private',
        ]);

    $response->assertRedirect();

    $app = App::query()->where('slug', 'mini_crm')->firstOrFail();

    expect($app->name)->toBe('Mini CRM')
        ->and($app->user_id)->toBe($this->user->id)
        ->and($app->current_version_id)->not->toBeNull();

    $version = AppVersion::query()->where('app_id', $app->id)->first();
    expect($version)->not->toBeNull()
        ->and($version->version_number)->toBe(1)
        ->and($version->manifest['slug'])->toBe('mini_crm')
        ->and($version->manifest['permissions']['roles'])->toHaveCount(2);
});

it('rejects bad slugs', function () {
    $this->actingAs($this->user)
        ->post('/apps', [
            'name' => 'Bad',
            'slug' => 'Has-Dashes',
        ])
        ->assertSessionHasErrors('slug');
});

it('scopes organization_id from the current user when visibility is organization', function () {
    $org = Organization::create(['name' => 'Acme']);
    $this->user->update(['organization_id' => $org->id]);

    $this->actingAs($this->user)->post('/apps', [
        'name' => 'A', 'slug' => 'org_app', 'visibility' => 'organization',
    ]);

    $app = App::query()->where('slug', 'org_app')->firstOrFail();
    expect($app->organization_id)->toBe($org->id);
});

it('lets an organization member view their own private app after creating it', function () {
    $org = Organization::create(['name' => 'Acme']);
    $this->user->update(['organization_id' => $org->id]);

    $this->actingAs($this->user)->post('/apps', [
        'name' => 'Priv', 'slug' => 'priv_app', 'visibility' => 'private',
    ])->assertRedirect();

    $app = App::query()->where('slug', 'priv_app')->firstOrFail();

    // A private app must still carry the owner's org (tenant scope); visibility
    // alone gates sharing. Nulling it hid the app from its own org-context owner.
    expect($app->organization_id)->toBe($org->id)
        ->and($app->visibility->value)->toBe('private');

    // The creator can view it — previously a 403 on the post-create redirect.
    $this->actingAs($this->user)->get("/apps/{$app->id}")->assertOk();
});

it('shows an app with manifest and versions', function () {
    $this->actingAs($this->user)->post('/apps', ['name' => 'X', 'slug' => 'x_app']);
    $app = App::query()->where('slug', 'x_app')->firstOrFail();

    $this->actingAs($this->user)
        ->get("/apps/{$app->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('apps/Show')
            ->where('app.slug', 'x_app')
            ->where('manifest.slug', 'x_app')
            ->has('versions', 1)
            ->where('overview.stats.pages', 0)
            ->where('overview.stats.objects', 0)
            ->where('overview.stats.records', 0)
            ->where('overview.stats.workflows', 0)
            ->has('overview.pages', 0)
            ->has('overview.objects', 0)
            ->has('overview.workflows', 0)
        );
});

it('hides apps from users who cannot see them', function () {
    $this->actingAs($this->user)->post('/apps', ['name' => 'X', 'slug' => 'x_app']);
    $app = App::query()->where('slug', 'x_app')->firstOrFail();

    $other = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($other)
        ->get("/apps/{$app->id}")
        ->assertForbidden();
});

it('deletes an app', function () {
    $this->actingAs($this->user)->post('/apps', ['name' => 'X', 'slug' => 'x_app']);
    $app = App::query()->where('slug', 'x_app')->firstOrFail();

    $this->actingAs($this->user)->delete("/apps/{$app->id}")->assertRedirect('/apps');

    expect(App::query()->where('id', $app->id)->exists())->toBeFalse();
});
