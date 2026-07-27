<?php

use App\Models\Chatbot;
use App\Models\User;
use App\Models\WidgetConversation;

/**
 * The stats on a bot's own page have to be numbers.
 *
 * Postgres returns AVG over a numeric column as a STRING, which survives JSON
 * encoding as a string and reaches Vue as one. The page calls `.toFixed(1)` on
 * it, so the owner's chatbot page threw `toFixed is not a function` — but only
 * once a visitor had rated a conversation. With no ratings the average is null,
 * the page renders a dash, and everything looks fine, which is why this shipped:
 * the bug needed real data to exist and the tests had none.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->chatbot = Chatbot::factory()->forUser($this->user)->create();
});

it('sends the average rating as a number once a conversation is rated', function () {
    WidgetConversation::factory()->create(['chatbot_id' => $this->chatbot->id, 'rating' => 4]);
    WidgetConversation::factory()->create(['chatbot_id' => $this->chatbot->id, 'rating' => 5]);

    $this->actingAs($this->user)
        ->get(route('chatbots.show', $this->chatbot))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chatbots/Show')
            ->where('stats.avg_rating', 4.5));
});

it('leaves the average null when nobody has rated, so the page shows a dash', function () {
    WidgetConversation::factory()->create(['chatbot_id' => $this->chatbot->id, 'rating' => null]);

    $this->actingAs($this->user)
        ->get(route('chatbots.show', $this->chatbot))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.avg_rating', null));
});
