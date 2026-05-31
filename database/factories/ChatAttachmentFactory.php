<?php

namespace Database\Factories;

use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatAttachment>
 */
class ChatAttachmentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->word().'.png';

        return [
            'chat_id' => Chat::factory(),
            'chat_message_id' => null,
            'user_id' => User::factory(),
            'organization_id' => null,
            'disk' => 's3',
            'storage_path' => 'chat_uploads/'.fake()->uuid().'/'.fake()->uuid().'.png',
            'original_name' => $name,
            'mime' => 'image/png',
            'size_bytes' => fake()->numberBetween(1000, 500000),
        ];
    }
}
