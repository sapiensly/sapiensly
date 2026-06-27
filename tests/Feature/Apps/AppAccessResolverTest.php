<?php

use App\Models\App;
use App\Models\AppUserRole;
use App\Models\User;
use App\Services\Apps\AppAccessResolver;

/**
 * Builds a manifest with two roles (admin / default user), one object with a
 * user-role policy (read-only, a hidden + a readonly field, a row_filter) and
 * two pages (one admin-only via page_policies).
 *
 * @param  array<string, mixed>  $overrides  merged into `permissions`
 */
function accessManifest(array $permissionsOverride = []): array
{
    return [
        'permissions' => array_replace([
            'access_mode' => 'open',
            'roles' => [
                ['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
                ['id' => 'rol_user', 'slug' => 'user', 'name' => 'User', 'is_default' => true],
            ],
            'object_policies' => [
                [
                    'object_id' => 'obj_items',
                    'role_id' => 'rol_user',
                    'actions' => ['read'],
                    'row_filter' => ['op' => 'eq', 'field_id' => 'fld_owner', 'value_expression' => '{{current_user.id}}'],
                    'field_restrictions' => ['hidden' => ['fld_secret'], 'readonly' => ['fld_name']],
                ],
                [
                    'object_id' => 'obj_items',
                    'role_id' => 'rol_admin',
                    'actions' => ['create', 'read', 'update', 'delete'],
                ],
            ],
            'page_policies' => [
                ['page_id' => 'pag_admin', 'role_id' => 'rol_admin', 'can_view' => true],
            ],
        ], $permissionsOverride),
        'objects' => [[
            'id' => 'obj_items', 'slug' => 'items', 'fields' => [
                ['id' => 'fld_name', 'slug' => 'name', 'type' => 'string'],
                ['id' => 'fld_secret', 'slug' => 'secret', 'type' => 'string'],
                ['id' => 'fld_owner', 'slug' => 'owner', 'type' => 'string'],
            ],
        ]],
        'pages' => [
            ['id' => 'pag_home', 'slug' => 'home'],
            ['id' => 'pag_admin', 'slug' => 'admin'],
        ],
    ];
}

function resolver(): AppAccessResolver
{
    return new AppAccessResolver;
}

it('grants full bypass to the app owner', function () {
    $owner = User::factory()->create();
    $app = App::factory()->create(['user_id' => $owner->id]);

    $ctx = resolver()->resolve($app, accessManifest(), $owner);

    expect($ctx->bypass)->toBeTrue()
        ->and($ctx->canViewPage('pag_admin'))->toBeTrue()
        ->and($ctx->objectActions('obj_items'))->toBe(['create', 'read', 'update', 'delete'])
        ->and($ctx->hiddenFieldSlugs('obj_items'))->toBe([])
        ->and($ctx->rowFilter('obj_items'))->toBeNull();
});

it('falls back to the default role for an ungranted member of an open app', function () {
    $member = User::factory()->create();
    $app = App::factory()->create(['user_id' => User::factory()->create()->id]);

    $ctx = resolver()->resolve($app, accessManifest(), $member);

    expect($ctx->bypass)->toBeFalse()
        ->and($ctx->hasAccess)->toBeTrue()
        ->and($ctx->roleSlugs)->toBe(['user'])
        // user policy: read-only, row-filtered, with hidden/readonly fields.
        ->and($ctx->objectActions('obj_items'))->toBe(['read'])
        ->and($ctx->can('obj_items', 'create'))->toBeFalse()
        ->and($ctx->hiddenFieldSlugs('obj_items'))->toBe(['secret'])
        ->and($ctx->readonlyFieldSlugs('obj_items'))->toBe(['name'])
        ->and($ctx->rowFilter('obj_items'))->not->toBeNull()
        // page gating: admin-only page hidden, unpoliced page visible.
        ->and($ctx->canViewPage('pag_home'))->toBeTrue()
        ->and($ctx->canViewPage('pag_admin'))->toBeFalse();
});

it('uses an explicit grant over the default role', function () {
    $member = User::factory()->create();
    $app = App::factory()->create(['user_id' => User::factory()->create()->id]);
    AppUserRole::factory()->create(['app_id' => $app->id, 'assigned_user_id' => $member->id, 'role_slug' => 'admin']);

    $ctx = resolver()->resolve($app, accessManifest(), $member);

    expect($ctx->roleSlugs)->toBe(['admin'])
        ->and($ctx->objectActions('obj_items'))->toBe(['create', 'read', 'update', 'delete'])
        ->and($ctx->canViewPage('pag_admin'))->toBeTrue();
});

it('denies an ungranted user when the app is allowlist', function () {
    $member = User::factory()->create();
    $app = App::factory()->create(['user_id' => User::factory()->create()->id]);

    $ctx = resolver()->resolve($app, accessManifest(['access_mode' => 'allowlist']), $member);

    expect($ctx->bypass)->toBeFalse()->and($ctx->hasAccess)->toBeFalse();
});

it('is open-within-visibility when roles exist but no policies are authored', function () {
    $member = User::factory()->create();
    $app = App::factory()->create(['user_id' => User::factory()->create()->id]);

    $ctx = resolver()->resolve($app, accessManifest(['object_policies' => [], 'page_policies' => []]), $member);

    expect($ctx->objectActions('obj_items'))->toBe(['create', 'read', 'update', 'delete'])
        ->and($ctx->canViewPage('pag_admin'))->toBeTrue()
        ->and($ctx->hiddenFieldSlugs('obj_items'))->toBe([]);
});

it('treats a dangling grant slug as no grant (falls back to default)', function () {
    $member = User::factory()->create();
    $app = App::factory()->create(['user_id' => User::factory()->create()->id]);
    AppUserRole::factory()->create(['app_id' => $app->id, 'assigned_user_id' => $member->id, 'role_slug' => 'ghost']);

    $ctx = resolver()->resolve($app, accessManifest(), $member);

    expect($ctx->roleSlugs)->toBe(['user']); // ghost ignored → default
});

it('denies an anonymous user on an allowlist app and gives default role on an open app', function () {
    $app = App::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(resolver()->resolve($app, accessManifest(['access_mode' => 'allowlist']), null)->hasAccess)->toBeFalse();
    expect(resolver()->resolve($app, accessManifest(), null)->roleSlugs)->toBe(['user']);
});
