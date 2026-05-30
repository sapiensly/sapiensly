<?php

namespace Database\Factories;

use App\Models\App;
use App\Models\Record;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Record>
 */
class RecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'app_id' => App::factory(),
            'object_definition_id' => 'obj_'.strtolower((string) Str::ulid()),
            'data' => [],
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }
}
