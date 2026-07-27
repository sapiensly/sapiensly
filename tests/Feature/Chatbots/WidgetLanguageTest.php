<?php

use App\Enums\ChatbotStatus;
use App\Models\Chatbot;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The widget speaks the organization's language, not the platform's default.
 *
 * Every string a visitor reads shipped as an English literal baked into the
 * chatbot's config at creation — so "Did this answer your question?" appeared
 * under a Spanish landing. That prompt is the only input to the resolution
 * metric, and asking it in the wrong language is the cheapest possible way to
 * depress the one number the product is judged on.
 *
 * The language comes from the Contextbook, where the organization already said
 * it. Not from the visitor's browser: a support bot speaks for its company.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
});

function botFor(Organization $org, User $user, array $config = []): Chatbot
{
    return Chatbot::create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Concierge',
        'status' => ChatbotStatus::Active,
        'config' => $config,
    ]);
}

function declaresLanguage(Organization $org, string $language): void
{
    OrganizationAiContext::firstOrNew(['organization_id' => $org->id])
        ->setRelation('organization', $org)
        ->fill(['profile' => ['language' => $language]])
        ->recompile()
        ->save();
}

it('asks the resolution question in the language the organization declared', function () {
    declaresLanguage($this->org, 'es-MX');

    $appearance = botFor($this->org, $this->user)->getAppearanceConfig();

    expect($appearance['resolution_prompt'])->toBe('¿Esto resolvió tu duda?')
        ->and($appearance['resolution_yes'])->toBe('Sí, gracias')
        ->and($appearance['resolution_no'])->toBe('No del todo')
        ->and($appearance['placeholder_text'])->toBe('Escribe tu mensaje...')
        ->and($appearance['welcome_message'])->toBe('¡Hola! ¿En qué puedo ayudarte?');
});

it('leaves an organization that speaks English alone', function () {
    declaresLanguage($this->org, 'en');

    expect(botFor($this->org, $this->user)->getAppearanceConfig()['resolution_prompt'])
        ->toBe('Did this answer your question?');
});

it('never overwrites wording someone chose on purpose', function () {
    declaresLanguage($this->org, 'es-MX');

    $chatbot = botFor($this->org, $this->user, [
        'appearance' => ['resolution_prompt' => '¿Te sirvió, che?'],
    ]);

    expect($chatbot->getAppearanceConfig()['resolution_prompt'])->toBe('¿Te sirvió, che?');
});

/**
 * The English default is also written into `config` at creation, so "still at
 * the default" has to mean the stored literal too — not just an absent key.
 */
it('replaces the English default already stored in the config', function () {
    declaresLanguage($this->org, 'es-MX');

    $chatbot = botFor($this->org, $this->user, [
        'appearance' => ['resolution_prompt' => 'Did this answer your question?'],
    ]);

    expect($chatbot->getAppearanceConfig()['resolution_prompt'])->toBe('¿Esto resolvió tu duda?');
});

it('still lets the bot introduce itself by name in either language', function () {
    declaresLanguage($this->org, 'es-MX');

    // The title localizes to "Soporte" first, then yields to the bot's own name
    // — a translated placeholder is no more anybody's bot name than the English.
    expect(botFor($this->org, $this->user)->getAppearanceConfig()['widget_title'])
        ->toBe('Concierge');
});
