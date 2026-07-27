<?php

use App\Enums\ChatbotStatus;
use App\Enums\MessageRole;
use App\Models\Chatbot;
use App\Models\Organization;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Models\WidgetSession;
use App\Services\ChatbotAnalyticsService;
use Illuminate\Support\Str;

/**
 * "Abandoned" has to mean the visitor was left hanging.
 *
 * The rule is right — a conversation whose LAST message is the visitor's, gone
 * quiet — but it was read off the FIRST message, because `latest()` was chained
 * onto a relation that is already ordered ascending. The first message is always
 * the visitor's, since they are the ones who open the conversation. So every
 * quiet conversation was marked abandoned, including the ones the bot answered
 * completely, and the abandonment figure on the owner's dashboard was counting
 * its successes as failures.
 */
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
    $this->session = WidgetSession::create([
        'chatbot_id' => $this->chatbot->id,
        'session_token' => Str::random(64),
    ]);
});

/** A conversation that fell silent an hour ago, with the given exchange. */
function quietConversation(Chatbot $chatbot, WidgetSession $session, array $roles): WidgetConversation
{
    $conversation = WidgetConversation::create([
        'chatbot_id' => $chatbot->id,
        'widget_session_id' => $session->id,
    ]);

    $at = now()->subHours(2);
    foreach ($roles as $role) {
        $message = WidgetMessage::create([
            'widget_conversation_id' => $conversation->id,
            'role' => $role,
            'content' => 'x',
        ]);
        $message->forceFill(['created_at' => $at])->save();
        $at = $at->copy()->addMinute();
    }

    $conversation->forceFill(['updated_at' => now()->subHour()])->save();

    return $conversation;
}

it('does not call an answered conversation abandoned', function () {
    // The everyday shape of a success: the visitor asked, the bot replied, and
    // the visitor got what they needed and closed the tab.
    $answered = quietConversation($this->chatbot, $this->session, [
        MessageRole::User, MessageRole::Assistant,
    ]);

    app(ChatbotAnalyticsService::class)->markAbandonedConversations();

    expect($answered->fresh()->is_abandoned)->toBeFalse();
});

it('still catches a visitor who was left hanging', function () {
    // The real failure: they asked again and nothing came back.
    $hanging = quietConversation($this->chatbot, $this->session, [
        MessageRole::User, MessageRole::Assistant, MessageRole::User,
    ]);

    $marked = app(ChatbotAnalyticsService::class)->markAbandonedConversations();

    expect($marked)->toBe(1)
        ->and($hanging->fresh()->is_abandoned)->toBeTrue()
        ->and($hanging->fresh()->abandoned_at)->not->toBeNull();
});

it('leaves a conversation that is still warm alone', function () {
    $recent = quietConversation($this->chatbot, $this->session, [MessageRole::User]);
    $recent->forceFill(['updated_at' => now()])->save();

    app(ChatbotAnalyticsService::class)->markAbandonedConversations();

    expect($recent->fresh()->is_abandoned)->toBeFalse();
});
