<?php

namespace App\Services\Chatbots;

use App\Enums\HandoffMode;
use App\Jobs\DispatchConversationEscalatedWorkflows;
use App\Models\Chatbot;
use App\Models\WidgetConversation;
use App\Support\Chatbots\HandoffOffer;

/**
 * Turns a `human_handoff` node from a note nobody reads into something that
 * happened.
 *
 * What it used to do: write `metadata.human_handoff` on the conversation. What
 * read that key: nothing, anywhere in the codebase. The visitor was told help
 * was coming, the bot carried on answering, and no one on the team ever learned
 * the conversation existed.
 *
 * What it does now is bounded by what the product can honour today: record the
 * escalation where the operator can see it, make sure the visitor is a Contact
 * someone can reply to, and fire the workflows the organization has wired up.
 * No live takeover — see HandoffResolver for why that is not promised yet.
 */
class ConversationEscalator
{
    public function __construct(
        private readonly HandoffResolver $handoffs,
    ) {}

    /**
     * Record that this conversation needs a person, and tell whoever is listening.
     */
    public function escalate(
        Chatbot $chatbot,
        WidgetConversation $conversation,
        ?string $reason = null,
        bool $notify = true,
        ?string $visitorName = null,
        ?string $visitorEmail = null,
    ): HandoffOffer {
        $offer = $this->handoffs->forOwner($chatbot->organization_id, $chatbot->user_id);

        $metadata = $conversation->metadata ?? [];
        $metadata['handoff'] = [
            'at' => now()->toISOString(),
            'reason' => $reason,
            'mode' => $offer->mode->value,
            'channel' => $offer->channel,
            'notified' => $notify,
            // Left open on purpose: it closes when someone actually replies, and
            // in the meantime it is what the operator list filters on.
            'resolved_at' => null,
        ];

        // NOT `Escalated`. That status suppresses the bot, and suppressing it
        // while no human can answer would leave the visitor talking to a wall —
        // strictly worse than the bug being fixed. The status moves when a person
        // takes the conversation, which is a thing that cannot happen yet.
        $conversation->update(['metadata' => $metadata]);

        $this->recordVisitorDetails($conversation, $visitorName, $visitorEmail);
        $conversation->refresh();

        if ($notify) {
            $this->notify($chatbot, $conversation, $offer, $reason);
        }

        return $offer;
    }

    /**
     * Write a name and address wherever this conversation can hold them.
     *
     * Both places, because either can be absent: a chatbot created without a
     * Channel has no Contact at all — a live bot on this install had exactly
     * that — and requiring one meant an escalation quietly kept nothing. The
     * session always exists, so the promise survives a half-provisioned bot.
     */
    public function recordVisitorDetails(
        WidgetConversation $conversation,
        ?string $name = null,
        ?string $email = null,
    ): void {
        $session = $conversation->session;
        $contact = $conversation->contact;

        // Fall back to what the widget was told at session start, so an
        // escalation never reaches the team more anonymous than it had to.
        $name = filled($name) ? $name : $session?->visitor_name;
        $email = filled($email) ? $email : $session?->visitor_email;

        if ($session !== null) {
            $updates = [];
            if (blank($session->visitor_email) && filled($email)) {
                $updates['visitor_email'] = $email;
            }
            if (blank($session->visitor_name) && filled($name)) {
                $updates['visitor_name'] = $name;
            }
            if ($updates !== []) {
                $session->update($updates);
            }
        }

        if ($contact !== null) {
            $updates = [];
            if (blank($contact->email) && filled($email)) {
                $updates['email'] = $email;
            }
            if (blank($contact->profile_name) && filled($name)) {
                $updates['profile_name'] = $name;
            }
            if ($updates !== []) {
                $contact->update($updates);
            }
        }
    }

    private function notify(
        Chatbot $chatbot,
        WidgetConversation $conversation,
        HandoffOffer $offer,
        ?string $reason,
    ): void {
        if ($chatbot->channel_id === null) {
            return;
        }

        $contact = $conversation->contact;
        $session = $conversation->session;

        DispatchConversationEscalatedWorkflows::dispatch(
            (string) $chatbot->channel_id,
            $chatbot->organization_id,
            $chatbot->user_id,
            [
                'channel' => [
                    'id' => $chatbot->channel_id,
                    'type' => 'widget',
                    'name' => $chatbot->name,
                ],
                'chatbot' => [
                    'id' => $chatbot->id,
                    'name' => $chatbot->name,
                ],
                'conversation_id' => $conversation->id,
                'reason' => $reason,
                'mode' => $offer->mode->value,
                // Read through to the session: a bot with no Channel has no
                // Contact, and the address is the whole point of the payload.
                'contact' => [
                    'id' => $contact?->id,
                    'name' => $contact?->profile_name ?? $session?->visitor_name,
                    'email' => $contact?->email ?? $session?->visitor_email,
                    'identifier' => $contact?->identifier,
                ],
            ],
        );
    }

    /**
     * Keep the email a visitor types after being asked for one.
     *
     * The offer the bot is allowed to make is "leave your details and someone
     * follows up". A promise that ends with the address scrolling past in a chat
     * transcript is not kept, so the address has to land on the Contact — the
     * record a person would actually reply to.
     *
     * Narrow on purpose, in both directions: only while the conversation is
     * waiting on a person, and only until an address is known. Outside that
     * window a visitor's message is a message, not something to mine.
     */
    public function captureContactDetails(WidgetConversation $conversation, string $text): void
    {
        if (! self::isAwaitingPerson($conversation)) {
            return;
        }

        if (filled($conversation->contact?->email) || filled($conversation->session?->visitor_email)) {
            return;
        }

        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]*\w/', $text, $matches) !== 1) {
            return;
        }

        $email = $matches[0];
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $this->recordVisitorDetails($conversation, email: $email);
    }

    /**
     * Whether this conversation is waiting on a person — the question the
     * operator list asks, and the one that decides whether an email the visitor
     * types next is worth keeping.
     */
    public static function isAwaitingPerson(WidgetConversation $conversation): bool
    {
        $handoff = $conversation->metadata['handoff'] ?? null;

        return is_array($handoff) && ($handoff['resolved_at'] ?? null) === null;
    }

    /**
     * The mode the last escalation resolved to, if any.
     */
    public static function modeOf(WidgetConversation $conversation): ?HandoffMode
    {
        $mode = $conversation->metadata['handoff']['mode'] ?? null;

        return is_string($mode) ? HandoffMode::tryFrom($mode) : null;
    }
}
