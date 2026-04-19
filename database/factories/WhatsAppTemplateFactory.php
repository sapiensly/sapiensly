<?php

namespace Database\Factories;

use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppTemplate>
 */
class WhatsAppTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'whatsapp_connection_id' => WhatsAppConnection::factory(),
            'name' => fake()->unique()->slug(2, false),
            'language' => 'en_US',
            'category' => 'utility',
            'components' => [
                [
                    'type' => 'body',
                    'text' => 'Hello {{1}}, your order {{2}} is ready.',
                ],
            ],
            'status' => 'approved',
            'last_synced_at' => now(),
        ];
    }
}
