<?php

namespace App\Policies;

use App\Enums\Visibility;
use App\Models\User;
use App\Models\WhatsAppConversation;

/**
 * Authorization for WhatsApp conversations. Access mirrors the parent Channel:
 * anyone who can view the channel can view its conversations; reply and
 * takeover require the `whatsapp-connections.reply` permission when the user
 * is part of an organization.
 */
class WhatsAppConversationPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('whatsapp-connections.view');
    }

    public function view(User $user, WhatsAppConversation $conversation): bool
    {
        $channel = $conversation->channel;

        if ($channel === null) {
            return false;
        }

        if (! $channel->isVisibleTo($user) && $channel->visibility !== Visibility::Global) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('whatsapp-connections.view');
    }

    public function reply(User $user, WhatsAppConversation $conversation): bool
    {
        if (! $this->view($user, $conversation)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('whatsapp-connections.reply');
    }

    public function takeover(User $user, WhatsAppConversation $conversation): bool
    {
        return $this->reply($user, $conversation);
    }
}
