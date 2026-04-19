<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\WhatsAppConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppConversation>
 */
class WhatsAppConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory()->whatsapp()->active(),
            'contact_id' => Contact::factory(),
            'title' => null,
            'metadata' => null,
            'flow_state' => null,
            'message_count' => 0,
            'status' => ConversationStatus::Open,
            'assigned_user_id' => null,
            'is_resolved' => false,
            'is_abandoned' => false,
            'last_inbound_at' => now(),
        ];
    }

    public function escalated(): static
    {
        return $this->state(fn () => ['status' => ConversationStatus::Escalated]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => ConversationStatus::Resolved,
            'is_resolved' => true,
        ]);
    }

    public function forChannel(Channel $channel, ?Contact $contact = null): static
    {
        return $this->state(fn () => [
            'channel_id' => $channel->id,
            'contact_id' => $contact?->id ?? Contact::factory()->forChannel($channel),
        ]);
    }
}
