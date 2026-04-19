<?php

namespace Database\Factories;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\Visibility;
use App\Models\Channel;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => null,
            'visibility' => Visibility::Private,
            'channel_type' => ChannelType::Widget,
            'name' => fake()->unique()->words(2, true),
            'agent_id' => null,
            'agent_team_id' => null,
            'status' => ChannelStatus::Draft,
            'metadata' => null,
        ];
    }

    public function whatsapp(): static
    {
        return $this->state(fn () => ['channel_type' => ChannelType::WhatsApp]);
    }

    public function widget(): static
    {
        return $this->state(fn () => ['channel_type' => ChannelType::Widget]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => ChannelStatus::Active]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
        ]);
    }

    public function forOrganization(Organization $organization, ?User $user = null): static
    {
        return $this->state(fn () => [
            'user_id' => $user?->id ?? User::factory(),
            'organization_id' => $organization->id,
            'visibility' => Visibility::Organization,
        ]);
    }
}
