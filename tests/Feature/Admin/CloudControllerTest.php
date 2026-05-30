<?php

use App\Enums\Visibility;
use App\Models\CloudProvider;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'sysadmin', 'guard_name' => 'web']);
});

function sysadminForCloud(): User
{
    $u = User::factory()->create();
    $u->assignRole('sysadmin');

    return $u;
}

test('cloud page renders empty storage when no global provider is set', function () {
    $admin = sysadminForCloud();

    $this->actingAs($admin)
        ->get('/admin/cloud')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Cloud')
            ->where('storage', null)
            ->has('database')
            ->has('pgvector'));
});

test('cloud page surfaces storage driver, bucket, region when configured', function () {
    $admin = sysadminForCloud();

    CloudProvider::create([
        'kind' => 'storage',
        'driver' => 's3',
        'display_name' => 'Global S3',
        'visibility' => Visibility::Global,
        'is_default' => true,
        'status' => 'active',
        'credentials' => [
            'bucket' => 'sapiensly-prod',
            'region' => 'us-east-1',
            'key' => 'AKIATEST',
            'secret' => 'secret',
        ],
    ]);

    $this->actingAs($admin)
        ->get('/admin/cloud')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('storage.driver', 's3')
            ->where('storage.bucket', 'sapiensly-prod')
            ->where('storage.region', 'us-east-1')
            ->where('storage.usedBytes', null));
});

test('pgvector section reports enabled when the extension is installed', function () {
    // Postgres test DB has pgvector installed (created by migrations), so the
    // endpoint reports enabled=true with the embedding index registered.
    $admin = sysadminForCloud();

    $this->actingAs($admin)
        ->get('/admin/cloud')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pgvector.enabled', true)
            ->has('pgvector.indexCount')
            ->has('pgvector.vectorCount'));
});

test('non-sysadmin is blocked from /admin/cloud', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->get('/admin/cloud')->assertForbidden();
});
