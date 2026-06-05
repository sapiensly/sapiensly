<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Production-safe: only deterministic seeders run here. No model factories —
     * they depend on fakerphp/faker, a dev-only package absent under
     * `composer install --no-dev`, so seeding one in production fatals. Demo and
     * sample data lives in DemoAppSeeder, invoked explicitly, never from a prod
     * `db:seed`.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SysAdminSeeder::class);
    }
}
