<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Spatie\Permission\Models\Role;

test('the default database seeder is production-safe — no factories or faker', function () {
    // Factories call fake(), and fakerphp/faker is a dev-only dependency absent
    // under `composer install --no-dev`, so a prod `db:seed` that hit a factory
    // would fatal with "Call to undefined function fake()". Guard against a
    // factory creeping back into the default seeder.
    $source = (string) file_get_contents(database_path('seeders/DatabaseSeeder.php'));

    expect($source)
        ->not->toContain('factory(')
        ->not->toContain('fake(');
});

test('the default database seeder seeds roles and the sysadmin, with no scaffolding test user', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Role::where('name', 'sysadmin')->exists())->toBeTrue()
        ->and(User::where('email', 'ed@sapiensly.ai')->exists())->toBeTrue()
        ->and(User::where('email', 'test@example.com')->exists())->toBeFalse();
});
