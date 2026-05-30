<?php

namespace Database\Factories;

use App\Models\App;
use App\Models\AppVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppVersion>
 */
class AppVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'organization_id' => null,
            'version_number' => 1,
            'manifest' => [
                'schema_version' => '1.0.0',
                'id' => 'app_'.strtolower(Str::ulid()->toBase32()),
                'slug' => fake()->slug(2),
                'name' => fake()->words(3, true),
                'version' => 1,
                'objects' => [],
                'pages' => [],
                'permissions' => [
                    'roles' => [
                        ['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
                        ['id' => 'rol_user', 'slug' => 'user', 'name' => 'User', 'is_default' => true],
                    ],
                ],
            ],
            'created_by_user_id' => null,
            'change_summary' => null,
        ];
    }
}
