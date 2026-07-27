<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WidgetConversation;

/**
 * Authorization for widget conversations, mirroring WhatsAppConversationPolicy.
 *
 * Written now, before there is anything to reply with, because the alternative
 * is inventing authorization at the same moment as the feature that needs it —
 * and taking over a stranger's conversation on someone's website is not the
 * place to be improvising who may do it. Reading a transcript follows the
 * chatbot; taking a conversation over is a change to it, so it follows update.
 */
class WidgetConversationPolicy
{
    public function view(User $user, WidgetConversation $conversation): bool
    {
        $chatbot = $conversation->chatbot;

        if ($chatbot === null || ! $chatbot->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('chatbots.view');
    }

    public function reply(User $user, WidgetConversation $conversation): bool
    {
        if (! $this->view($user, $conversation)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('chatbots.update');
    }

    public function takeover(User $user, WidgetConversation $conversation): bool
    {
        return $this->reply($user, $conversation);
    }
}
