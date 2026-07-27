<?php

use App\Enums\ChatbotStatus;
use App\Models\Chatbot;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
});

function chatbotNamed(Organization $org, User $user, string $name, array $config = []): Chatbot
{
    return Chatbot::create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => $name,
        'status' => ChatbotStatus::Active,
        'config' => $config,
    ]);
}

/**
 * The widget panel header names whoever is answering. It used to read "Support"
 * on every bot on the platform — a built-in default that is also written into
 * `config` at creation, so it stuck there forever and no bot ever introduced
 * itself by name.
 */
it('titles the panel with the chatbot own name', function () {
    $chatbot = chatbotNamed($this->org, $this->user, 'Concierge Nébula');

    expect($chatbot->getAppearanceConfig()['widget_title'])->toBe('Concierge Nébula');
});

it('replaces the stored generic default too, not just an absent value', function () {
    // What Chatbot::getDefaultConfig() writes into a freshly created bot.
    $chatbot = chatbotNamed($this->org, $this->user, 'Soporte Yuhu', [
        'appearance' => ['widget_title' => 'Support', 'primary_color' => '#3B82F6'],
    ]);

    expect($chatbot->getAppearanceConfig()['widget_title'])->toBe('Soporte Yuhu');
});

it('never overrides a title someone deliberately wrote', function () {
    $chatbot = chatbotNamed($this->org, $this->user, 'Concierge Nébula', [
        'appearance' => ['widget_title' => 'Atención a socios'],
    ]);

    expect($chatbot->getAppearanceConfig()['widget_title'])->toBe('Atención a socios');
});

it('keeps a very long name from overflowing the header', function () {
    $chatbot = chatbotNamed($this->org, $this->user, str_repeat('Nébula ', 20));

    expect(mb_strlen($chatbot->getAppearanceConfig()['widget_title']))->toBeLessThanOrEqual(40);
});
