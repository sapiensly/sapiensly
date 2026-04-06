<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class SysAdminSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $user = User::firstOrCreate(
            ['email' => 'ed@sapiensly.ai'],
            [
                'name' => 'Ed',
                'password' => 'passmenow',
                'email_verified_at' => now(),
            ]
        );

        setPermissionsTeamId(null);
        $user->assignRole('sysadmin');
    }
}
