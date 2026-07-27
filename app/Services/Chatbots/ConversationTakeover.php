<?php

namespace App\Services\Chatbots;

use App\Enums\ConversationStatus;
use App\Enums\MessageRole;
use App\Models\Chatbot;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use Illuminate\Support\Carbon;

/**
 * A person taking a conversation from the bot, replying in it, and giving it back.
 *
 * Phase 1 built the state and deliberately refused to use it: `Escalated`
 * suppresses the bot, and suppressing it while nobody could reply would have
 * left the visitor talking to a wall. This is the other half — now that a human
 * reply can actually reach the widget, the mute means someone is speaking
 * instead of the bot, not that nobody is.
 *
 * The dangerous state is the one in the middle: taken over, then abandoned. A
 * visitor waiting on a person who closed their laptop is the worst experience
 * this product could produce, and it is produced by the FEATURE working, not by
 * a bug — so {@see self::reclaimUnattended()} is part of the design rather than
 * a later hardening pass.
 */
class ConversationTakeover
{
    /**
     * How long a taken-over conversation may go without a word from its operator
     * before the bot takes it back.
     *
     * Long enough to read a transcript and think; short enough that a visitor
     * does not sit in silence wondering whether anyone is there.
     */
    public const UNATTENDED_MINUTES = 10;

    /**
     * Hand the conversation to a person. The bot goes quiet from the next turn.
     */
    public function take(WidgetConversation $conversation, User $operator): void
    {
        $conversation->update([
            'status' => ConversationStatus::Escalated,
            'assigned_user_id' => $operator->id,
        ]);

        $this->stamp($conversation, ['taken_at' => now()->toISOString(), 'taken_by' => $operator->id]);

        // The visitor is owed the transition. Going quiet and then answering in
        // a different voice, with no seam, is how a person gets mistaken for the
        // bot — and the bot for a person.
        $conversation->addMessage(
            MessageRole::Assistant,
            $this->visitorNotice($conversation, 'operator_joined'),
            ['human' => false, 'joined' => true],
        );
    }

    /**
     * Give it back to the bot, deliberately.
     */
    public function release(WidgetConversation $conversation): void
    {
        $conversation->update([
            'status' => ConversationStatus::Open,
            'assigned_user_id' => null,
        ]);

        // The escalation is over: it stops showing in the "asked for a person"
        // queue, because someone answered.
        $this->stamp($conversation, ['resolved_at' => now()->toISOString()]);
    }

    /**
     * Say something to the visitor, as a person.
     */
    public function reply(WidgetConversation $conversation, User $operator, string $content): WidgetMessage
    {
        $message = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'sender_user_id' => $operator->id,
            'content' => $content,
            'metadata' => ['human' => true, 'sender_name' => $operator->name],
        ]);

        $conversation->increment('message_count');

        return $message;
    }

    /**
     * Whether this conversation is being handled by a person right now.
     */
    public function isTaken(WidgetConversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Escalated
            && $conversation->assigned_user_id !== null;
    }

    /**
     * Give back every conversation of this bot whose operator has gone quiet.
     *
     * Silence is measured from the operator's last word, not from the takeover,
     * so someone actively replying is never interrupted. The visitor is told
     * plainly — a conversation that goes from a person back to a bot without
     * saying so reads as the person ignoring them.
     *
     * Scoped to ONE bot on purpose. `widget_conversations` is RLS-protected, so
     * a sweep that queries it globally from a scheduled command runs with no
     * tenant scope and matches nothing at all — silently, and invisibly to the
     * test suite, which aliases the runtime connections to the owner. The caller
     * establishes each owner's scope and calls this per bot.
     *
     * @return int how many were reclaimed
     */
    public function reclaimUnattended(Chatbot $chatbot): int
    {
        $cutoff = now()->subMinutes(self::UNATTENDED_MINUTES);
        $reclaimed = 0;

        $chatbot->conversations()
            ->where('status', ConversationStatus::Escalated)
            ->whereNotNull('assigned_user_id')
            ->cursor()
            ->each(function (WidgetConversation $conversation) use ($cutoff, &$reclaimed): void {
                $lastHuman = $conversation->messages()
                    ->whereNotNull('sender_user_id')
                    ->reorder()
                    ->latest('created_at')
                    ->latest('id')
                    ->first();

                // No word yet? Then the clock runs from the takeover itself —
                // otherwise a conversation grabbed and never answered would sit
                // escalated forever, which is the exact case this exists for.
                $lastWord = $lastHuman?->created_at
                    ?? $this->takenAt($conversation)
                    ?? $conversation->updated_at;

                if ($lastWord === null || $lastWord->greaterThan($cutoff)) {
                    return;
                }

                $this->release($conversation);
                $conversation->addMessage(
                    MessageRole::Assistant,
                    $this->visitorNotice($conversation, 'operator_left'),
                    ['human' => false, 'reclaimed' => true],
                );

                $reclaimed++;
            });

        return $reclaimed;
    }

    /**
     * A visitor-facing line, in the bot's own configured voice and language.
     *
     * Never a server-side __() — the app locale belongs to the OWNER, and this
     * is read by a stranger on the owner's website. The appearance config is
     * already the place where every such string lives.
     */
    private function visitorNotice(WidgetConversation $conversation, string $key): string
    {
        $appearance = $conversation->chatbot?->getAppearanceConfig() ?? [];
        $notice = $appearance[$key] ?? null;

        return is_string($notice) && trim($notice) !== '' ? $notice : '';
    }

    private function takenAt(WidgetConversation $conversation): ?Carbon
    {
        $at = $conversation->metadata['handoff']['taken_at'] ?? null;

        return is_string($at) ? Carbon::parse($at) : null;
    }

    /**
     * Merge into the handoff record the operator queue reads.
     *
     * @param  array<string, mixed>  $fields
     */
    private function stamp(WidgetConversation $conversation, array $fields): void
    {
        $metadata = $conversation->metadata ?? [];
        $metadata['handoff'] = array_merge($metadata['handoff'] ?? [], $fields);

        $conversation->update(['metadata' => $metadata]);
    }
}
