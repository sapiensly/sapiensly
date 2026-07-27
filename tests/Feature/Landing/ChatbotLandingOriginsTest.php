<?php

use App\Enums\ChatbotStatus;
use App\Models\App;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\CustomDomain;
use App\Models\Organization;
use App\Models\User;
use App\Services\Landing\ChatbotLandingOrigins;
use App\Services\Landing\LandingPublisher;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    $this->chatbot = Chatbot::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'name' => 'Concierge',
        'status' => ChatbotStatus::Active,
        'config' => [],
    ]);
    $this->token = ChatbotApiToken::create([
        'chatbot_id' => $this->chatbot->id,
        'name' => 'Default Token',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat', 'feedback'],
    ]);
    $this->origins = app(ChatbotLandingOrigins::class);
});

/** A published landing bound to the chatbot, with its public slug minted. */
function boundLanding(Organization $org, User $user, Chatbot $chatbot): App
{
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'kind' => 'landing',
        'chatbot_id' => $chatbot->id,
    ]);
    app(LandingPublisher::class)->publish($app);

    return $app->fresh();
}

it('derives the platform origin from a published landing that serves the bot', function () {
    boundLanding($this->org, $this->user, $this->chatbot);

    expect($this->origins->for($this->chatbot->id))
        ->toContain(strtolower(rtrim(config('app.url'), '/')));
});

it('adds an active custom domain as its own origin', function () {
    $app = boundLanding($this->org, $this->user, $this->chatbot);
    CustomDomain::create([
        'organization_id' => $this->org->id,
        'user_id' => $this->user->id,
        'app_id' => $app->id,
        'hostname' => 'promo.acme.mx',
        'status' => 'active',
    ]);
    $this->origins->forget($this->chatbot->id);

    expect($this->origins->for($this->chatbot->id))->toContain('https://promo.acme.mx');
});

it('grants nothing for an unpublished landing', function () {
    App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'kind' => 'landing',
        'chatbot_id' => $this->chatbot->id,
    ]);

    expect($this->origins->for($this->chatbot->id))->toBe([]);
});

it('withdraws the origin the moment the landing is unpublished', function () {
    $app = boundLanding($this->org, $this->user, $this->chatbot);
    expect($this->origins->for($this->chatbot->id))->not->toBe([]);

    app(LandingPublisher::class)->unpublish($app);

    expect($this->origins->for($this->chatbot->id))->toBe([]);
});

/**
 * The trap this whole design avoids: ValidateWidgetOrigin reads an EMPTY list as
 * "allow every origin". Had publishing appended the landing's origin to the
 * chatbot's own list, the bot would have flipped to allow-only-that and locked
 * out every external site already embedding it.
 */
it('never writes the derived origin into the chatbot own allowlist', function () {
    boundLanding($this->org, $this->user, $this->chatbot);

    expect($this->chatbot->fresh()->allowed_origins)->toBeNull();

    // And an external embed with no configured list still gets through.
    $this->withHeaders([
        'Origin' => 'https://a-customer-site.example',
        'Authorization' => 'Bearer '.$this->token->token,
    ])->postJson('/api/widget/v1/sessions', [])->assertSuccessful();
});

it('lets the published landing through a chatbot that does restrict origins', function () {
    $this->chatbot->update(['allowed_origins' => ['https://only-this-one.example']]);
    boundLanding($this->org, $this->user, $this->chatbot);

    $this->withHeaders([
        'Origin' => strtolower(rtrim(config('app.url'), '/')),
        'Authorization' => 'Bearer '.$this->token->token,
    ])->postJson('/api/widget/v1/sessions', [])->assertSuccessful();

    // …while an origin that is neither configured nor derived stays out.
    $this->withHeaders([
        'Origin' => 'https://somewhere-else.example',
        'Authorization' => 'Bearer '.$this->token->token,
    ])->postJson('/api/widget/v1/sessions', [])->assertStatus(403);
});
