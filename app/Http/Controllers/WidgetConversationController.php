<?php

namespace App\Http\Controllers;

use App\Models\Chatbot;
use App\Models\WidgetConversation;
use App\Services\Chatbots\ConversationTakeover;
use App\Services\Chatbots\OperatorPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The operator's side of a live handoff: take a conversation from the bot, speak
 * in it, hand it back.
 *
 * Deliberately the same three verbs as the WhatsApp inbox
 * ({@see WhatsAppConversationController}), because it is the same job on a
 * different channel and a person who has learned one should not have to learn
 * the other.
 */
class WidgetConversationController extends Controller
{
    public function __construct(
        private readonly ConversationTakeover $takeover,
        private readonly OperatorPresence $presence,
    ) {}

    public function takeover(Request $request, Chatbot $chatbot, WidgetConversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($chatbot, $conversation, 'takeover');

        $this->takeover->take($conversation, $request->user());

        return back();
    }

    public function release(Request $request, Chatbot $chatbot, WidgetConversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($chatbot, $conversation, 'takeover');

        $this->takeover->release($conversation);

        return back();
    }

    public function reply(Request $request, Chatbot $chatbot, WidgetConversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($chatbot, $conversation, 'reply');

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ]);

        // Replying implies taking it: an operator who types into a conversation
        // the bot still owns would otherwise be interleaved with model answers,
        // both talking to the same person at once.
        if (! $this->takeover->isTaken($conversation)) {
            $this->takeover->take($conversation, $request->user());
        }

        $this->takeover->reply($conversation, $request->user(), $validated['content']);

        return back();
    }

    /**
     * The inbox says it is still open, which is what lets the bot offer a person
     * at all. Called on a timer by whoever is looking at conversations.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $this->presence->touch($request->user());

        return response()->json(['ok' => true]);
    }

    /**
     * Stop vouching immediately — the page is closing.
     */
    public function leave(Request $request): JsonResponse
    {
        $this->presence->forget($request->user());

        return response()->json(['ok' => true]);
    }

    private function authorizeConversation(Chatbot $chatbot, WidgetConversation $conversation, string $ability): void
    {
        // The nested route does not prove the pairing; a conversation id from
        // another bot would otherwise be actionable by anyone who can reach one.
        abort_unless($conversation->chatbot_id === $chatbot->id, 404);

        $this->authorize($ability, $conversation);
    }
}
