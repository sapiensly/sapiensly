<?php

namespace App\Http\Controllers\Api\Widget\Concerns;

use App\Models\Chatbot;
use App\Models\WidgetConversation;
use App\Models\WidgetSession;
use Illuminate\Http\Request;

/**
 * Resolve a conversation the CALLER is entitled to, on every widget endpoint.
 *
 * Scoping by chatbot alone is not authorization: the API token ships in the page
 * source of every site that embeds the bot, so "holds a valid token" describes
 * every visitor on the internet at once. The session token is the only thing
 * that says WHICH visitor is asking, and the bundle already sends it.
 *
 * This lives in one place because the first pass at the fix patched the chat
 * endpoints and quietly left feedback and attachments behind — a stranger could
 * still rate someone else's conversation, or read a file from it. A rule applied
 * per-controller is a rule that will be missed by the next controller.
 */
trait ResolvesVisitorConversation
{
    protected function visitorConversation(Request $request, Chatbot $chatbot, string $conversationId): ?WidgetConversation
    {
        $sessionToken = (string) ($request->header('X-Session-Token') ?? $request->input('session_token') ?? '');

        if ($sessionToken === '') {
            return null;
        }

        $session = WidgetSession::where('chatbot_id', $chatbot->id)
            ->where('session_token', $sessionToken)
            ->first();

        if ($session === null) {
            return null;
        }

        return WidgetConversation::where('chatbot_id', $chatbot->id)
            ->where('widget_session_id', $session->id)
            ->where('id', $conversationId)
            ->first();
    }
}
