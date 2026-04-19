<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'identifier' => (string) Str::uuid(),
            'profile_name' => fake()->name(),
            'email' => null,
            'phone_e164' => null,
            'locale' => null,
            'metadata' => null,
            'last_inbound_at' => null,
            'last_outbound_at' => null,
            'opted_out_at' => null,
            'user_agent' => null,
            'ip_address' => null,
        ];
    }

    public function whatsapp(?string $waId = null): static
    {
        return $this->state(fn () => [
            'identifier' => $waId ?? (string) fake()->numerify('1##########'),
            'phone_e164' => '+'.($waId ?? fake()->numerify('1##########')),
        ]);
    }

    public function forChannel(Channel $channel): static
    {
        return $this->state(fn () => ['channel_id' => $channel->id]);
    }

    public function optedOut(): static
    {
        return $this->state(fn () => ['opted_out_at' => now()]);
    }

    public function recentlyActive(): static
    {
        return $this->state(fn () => ['last_inbound_at' => now()->subMinutes(5)]);
    }
}
