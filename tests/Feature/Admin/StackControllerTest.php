<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'sysadmin', 'guard_name' => 'web']);
});

test('stack page renders with all five groups in canonical order', function () {
    $user = User::factory()->create();
    $user->assignRole('sysadmin');

    $this->actingAs($user)
        ->get('/admin/stack')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Stack')
            ->has('groups', 5)
            ->where('groups.0.id', 'runtime')
            ->where('groups.1.id', 'frontend')
            ->where('groups.2.id', 'data')
            ->where('groups.3.id', 'ai')
            ->where('groups.4.id', 'infra'));
});

test('runtime group surfaces PHP and Laravel versions', function () {
    $user = User::factory()->create();
    $user->assignRole('sysadmin');

    $this->actingAs($user)
        ->get('/admin/stack')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('groups.0.id', 'runtime')
            ->where('groups.0.items.0.name', 'PHP')
            ->where('groups.0.items.0.version', PHP_VERSION)
            ->where('groups.0.items.1.name', 'Laravel'));
});

test('every stack item carries a status field', function () {
    $user = User::factory()->create();
    $user->assignRole('sysadmin');

    $response = $this->actingAs($user)->get('/admin/stack')->assertOk();
    $groups = $response->viewData('page')['props']['groups'];

    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            expect($item)->toHaveKeys(['name', 'version', 'description', 'status']);
            expect($item['status'])->toBeIn(['ok', 'outdated', 'missing']);
        }
    }
});

test('non-sysadmin is blocked from /admin/stack', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get('/admin/stack')->assertForbidden();
});
