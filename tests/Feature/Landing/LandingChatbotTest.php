<?php

use App\Enums\ChatbotStatus;
use App\Models\App;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\Landing\LandingPublisher;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\InvalidManifestException;
use Illuminate\Support\Str;

/**
 * A landing may carry ONE of its own organization's chatbots as a floating
 * bubble. Everything here guards the two ways that can go wrong: naming a bot
 * that isn't yours, and a bot that stops being usable after you published.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    $this->manifests = app(AppManifestService::class);
});

function landingChatbot(Organization $org, User $user, ChatbotStatus $status = ChatbotStatus::Active): Chatbot
{
    $chatbot = Chatbot::create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Concierge',
        'status' => $status,
        'config' => [],
    ]);
    ChatbotApiToken::create([
        'chatbot_id' => $chatbot->id,
        'name' => 'Default Token',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat', 'feedback'],
    ]);

    return $chatbot;
}

function landingManifest(App $app, ?string $chatbotId, string $surface = 'landing'): array
{
    $settings = ['surface' => $surface];
    if ($chatbotId !== null) {
        $settings['chatbot'] = ['id' => $chatbotId, 'position' => 'left', 'greeting' => '¿Te ayudo?'];
    }

    return [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'mi_landing',
        'name' => $app->name,
        'version' => 1,
        'objects' => [],
        'pages' => [[
            'id' => 'pg_landing',
            'slug' => 'home',
            'path' => '/',
            'name' => 'Home',
            'blocks' => [['id' => 'blk_hero', 'type' => 'html', 'content' => '<section>Hola</section>']],
        ]],
        'permissions' => [
            'roles' => [['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin']],
        ],
        'settings' => $settings,
    ];
}

it('binds a chatbot the organization owns and indexes it on the app', function () {
    $chatbot = landingChatbot($this->org, $this->user);
    $app = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);

    $this->manifests->createVersion($app, landingManifest($app, $chatbot->id), $this->user);

    // The manifest stays the source of truth; the column is the read index that
    // makes "which landings serve this bot?" cheap.
    expect($app->fresh()->chatbot_id)->toBe($chatbot->id);
});

it('refuses a chatbot from another organization', function () {
    $otherOrg = Organization::create(['name' => 'Rival', 'slug' => 'rival-'.Str::lower(Str::random(6))]);
    $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
    $foreign = landingChatbot($otherOrg, $otherUser);

    $app = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);

    expect(fn () => $this->manifests->createVersion($app, landingManifest($app, $foreign->id), $this->user))
        ->toThrow(InvalidManifestException::class, 'belongs to this app');

    expect($app->fresh()->chatbot_id)->toBeNull();
});

it('refuses a bubble on anything that is not a landing', function () {
    $chatbot = landingChatbot($this->org, $this->user);
    $app = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);

    expect(fn () => $this->manifests->createVersion(
        $app,
        landingManifest($app, $chatbot->id, surface: 'app'),
        $this->user,
    ))->toThrow(InvalidManifestException::class, 'only available on a landing surface');
});

it('serves the bubble on the published page, with the page position and greeting', function () {
    $chatbot = landingChatbot($this->org, $this->user);
    $app = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);
    $this->manifests->createVersion($app, landingManifest($app, $chatbot->id), $this->user);
    $published = app(LandingPublisher::class)->publish($app->fresh());

    $this->get('/l/'.$published['public_slug'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('chatbot.chatbot_id', $chatbot->id)
            ->where('chatbot.position', 'left')
            ->where('chatbot.greeting', '¿Te ayudo?')
            ->has('chatbot.token'));
});

/**
 * A bot paused or deleted after publishing must not take the page with it: the
 * landing keeps working, minus the bubble.
 */
it('drops the bubble when the chatbot is paused, and keeps the page up', function () {
    $chatbot = landingChatbot($this->org, $this->user);
    $app = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);
    $this->manifests->createVersion($app, landingManifest($app, $chatbot->id), $this->user);
    $published = app(LandingPublisher::class)->publish($app->fresh());

    $chatbot->update(['status' => ChatbotStatus::Inactive]);

    $this->get('/l/'.$published['public_slug'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('chatbot', null));
});

it('drops the bubble when the chatbot was deleted outright', function () {
    $chatbot = landingChatbot($this->org, $this->user);
    $app = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);
    $this->manifests->createVersion($app, landingManifest($app, $chatbot->id), $this->user);
    $published = app(LandingPublisher::class)->publish($app->fresh());

    $chatbot->delete();

    $this->get('/l/'.$published['public_slug'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('chatbot', null));
});

it('ships no chatbot prop for a landing that binds none', function () {
    $app = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);
    $this->manifests->createVersion($app, landingManifest($app, null), $this->user);
    $published = app(LandingPublisher::class)->publish($app->fresh());

    $this->get('/l/'.$published['public_slug'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('chatbot', null));

    expect($app->fresh()->chatbot_id)->toBeNull();
});

it('clears the binding when the landing drops it', function () {
    $chatbot = landingChatbot($this->org, $this->user);
    $app = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->org->id]);
    $this->manifests->createVersion($app, landingManifest($app, $chatbot->id), $this->user);
    expect($app->fresh()->chatbot_id)->toBe($chatbot->id);

    $this->manifests->createVersion($app->fresh(), landingManifest($app, null), $this->user);

    expect($app->fresh()->chatbot_id)->toBeNull();
});
