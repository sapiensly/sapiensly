<?php

namespace App\Services\WhatsApp;

use App\Enums\ConversationStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\WhatsAppConversation;

/**
 * Finds the open conversation for a given (channel, contact) pair — or starts
 * a new one when the last conversation is too old or already resolved. This
 * centralises the "conversation threading" policy so controllers and jobs
 * don't diverge.
 */
class WhatsAppConversationResolver
{
    public function resolveOpen(Channel $channel, Contact $contact): WhatsAppConversation
    {
        $latest = WhatsAppConversation::where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->latest('created_at')
            ->first();

        if ($latest && $this->isReusable($latest)) {
            return $latest;
        }

        return WhatsAppConversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::Open,
            'metadata' => null,
            'flow_state' => null,
            'message_count' => 0,
        ]);
    }

    private function isReusable(WhatsAppConversation $conversation): bool
    {
        $status = $conversation->status;

        // Escalated / open = append.
        if ($status === ConversationStatus::Open || $status === ConversationStatus::Escalated
            || $status === ConversationStatus::Pending) {
            return true;
        }

        // Resolved / abandoned: reuse only if within 24h of last inbound.
        $anchor = $conversation->last_inbound_at ?? $conversation->created_at;

        return $anchor !== null && $anchor->diffInHours(now()) < 24;
    }
}
