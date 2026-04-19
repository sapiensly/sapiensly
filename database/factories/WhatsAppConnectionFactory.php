<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\WhatsAppConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppConnection>
 */
class WhatsAppConnectionFactory extends Factory
{
    public function definition(): array
    {
        $phoneNumberId = (string) fake()->unique()->numerify('1##########');

        return [
            'channel_id' => Channel::factory()->whatsapp(),
            'display_phone_number' => '+'.fake()->numerify('1##########'),
            'phone_number_id' => $phoneNumberId,
            'business_account_id' => (string) fake()->numerify('1##########'),
            'provider' => 'meta_cloud',
            'auth_config' => [
                'phone_number_id' => $phoneNumberId,
                'whatsapp_business_account_id' => (string) fake()->numerify('1##########'),
                'access_token' => 'EAA'.Str::random(120),
                'app_id' => (string) fake()->numerify('1##########'),
                'app_secret' => Str::random(32),
                'webhook_verify_token' => Str::random(48),
                'graph_api_version' => 'v20.0',
            ],
            'webhook_verify_token' => Str::random(48),
            'messaging_tier' => '1k',
            'allow_insecure_tls' => false,
        ];
    }

    public function forChannel(Channel $channel): static
    {
        return $this->state(fn () => ['channel_id' => $channel->id]);
    }
}
