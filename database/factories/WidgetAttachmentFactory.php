<?php

namespace Database\Factories;

use App\Models\WidgetAttachment;
use App\Models\WidgetConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WidgetAttachment>
 */
class WidgetAttachmentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->word().'.png';

        return [
            'widget_conversation_id' => WidgetConversation::factory(),
            'widget_message_id' => null,
            'user_id' => null,
            'organization_id' => null,
            'disk' => 's3',
            'storage_path' => 'widget_uploads/'.fake()->uuid().'/'.fake()->uuid().'.png',
            'original_name' => $name,
            'mime' => 'image/png',
            'size_bytes' => fake()->numberBetween(1000, 500000),
            'kind' => 'image',
            'extracted_text' => null,
            'metadata' => null,
        ];
    }

    public function pdf(): static
    {
        return $this->state(fn () => [
            'mime' => 'application/pdf',
            'kind' => 'document',
            'original_name' => fake()->word().'.pdf',
            'storage_path' => 'widget_uploads/'.fake()->uuid().'/'.fake()->uuid().'.pdf',
            'extracted_text' => fake()->paragraph(),
        ]);
    }
}
