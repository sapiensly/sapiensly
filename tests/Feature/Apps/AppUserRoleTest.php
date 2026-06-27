<?php

use App\Models\App;
use App\Models\AppUserRole;
use App\Models\User;
use Illuminate\Database\QueryException;

it('creates an app-role grant with a prefixed id and wires its relations', function () {
    $app = App::factory()->create();
    $member = User::factory()->create();

    $grant = AppUserRole::factory()->create([
        'app_id' => $app->id,
        'assigned_user_id' => $member->id,
        'role_slug' => 'admin',
    ]);

    expect($grant->id)->toStartWith('aur_')
        ->and($grant->role_slug)->toBe('admin')
        ->and($grant->app->id)->toBe($app->id)
        ->and($grant->assignedUser->id)->toBe($member->id)
        ->and($app->userRoles()->count())->toBe(1)
        ->and($member->appRoles()->count())->toBe(1);
});

it('enforces one role per user per app', function () {
    $app = App::factory()->create();
    $member = User::factory()->create();

    AppUserRole::factory()->create(['app_id' => $app->id, 'assigned_user_id' => $member->id]);

    expect(fn () => AppUserRole::factory()->create([
        'app_id' => $app->id,
        'assigned_user_id' => $member->id,
    ]))->toThrow(QueryException::class);
});
