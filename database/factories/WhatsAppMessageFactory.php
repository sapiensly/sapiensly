<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppContentType;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppMessage>
 */
class WhatsAppMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'whatsapp_conversation_id' => WhatsAppConversation::factory(),
            'role' => MessageRole::User,
            'direction' => MessageDirection::Inbound,
            'content' => fake()->sentence(),
            'content_type' => WhatsAppContentType::Text,
            'wamid' => 'wamid.'.Str::random(40),
            'status' => MessageStatus::Delivered,
        ];
    }

    public function outbound(): static
    {
        return $this->state(fn () => [
            'role' => MessageRole::Assistant,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Sent,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => MessageStatus::Failed,
            'error_code' => 131056,
            'error_message' => 'Rate limit hit',
        ]);
    }

    public function forConversation(WhatsAppConversation $conv): static
    {
        return $this->state(fn () => ['whatsapp_conversation_id' => $conv->id]);
    }
}
