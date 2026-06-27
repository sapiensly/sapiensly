<?php

namespace Database\Factories;

use App\Models\App;
use App\Models\AppUserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppUserRole>
 */
class AppUserRoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'user_id' => null,
            'app_id' => App::factory(),
            'assigned_user_id' => User::factory(),
            'role_slug' => 'user',
            'granted_by_user_id' => null,
        ];
    }
}
