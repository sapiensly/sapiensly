<?php

namespace App\Support\Ai;

use App\Models\Chatbot;
use App\Models\WidgetConversation;
use Closure;

/**
 * Marks the stretch of work that answers someone OUTSIDE the tenant — a widget
 * visitor, a WhatsApp contact — so every agent built while it runs withholds the
 * platform tool catalogue.
 *
 * This is a context rather than a flag on a service because the turn is not
 * served by one object. The widget's own LLMService, the orchestrator's separate
 * LLMService, and whatever a future collaborator injects are all different
 * instances; a switch flipped on one of them silently misses the rest. That is
 * not hypothetical — the first version of this guard was an instance flag, and a
 * multi-agent bot went straight past it into the orchestrator's own instance,
 * still carrying every tool.
 *
 * Scoped by closure and restored in a `finally`, so it cannot leak into the next
 * job on a queue worker the way a plain singleton flag would.
 */
class PublicTurnContext
{
    private bool $public = false;

    private ?Chatbot $chatbot = null;

    private ?WidgetConversation $conversation = null;

    /** True while the current turn is being driven by someone outside the tenant. */
    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * The conversation this public turn belongs to, when it has one.
     *
     * Carried here for the same reason the flag is: the bot that decides to take
     * someone's message may be running inside the orchestrator's own LLMService,
     * several objects away from the service that knows which conversation this
     * is. A tool the model can call needs to find its way back here.
     */
    public function conversation(): ?WidgetConversation
    {
        return $this->conversation;
    }

    public function chatbot(): ?Chatbot
    {
        return $this->chatbot;
    }

    /**
     * Run the work of a public turn. Nested calls are safe: the previous state
     * is restored, not clobbered.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function runPublic(Closure $work, ?Chatbot $chatbot = null, ?WidgetConversation $conversation = null): mixed
    {
        $previous = [$this->public, $this->chatbot, $this->conversation];

        $this->public = true;
        $this->chatbot = $chatbot;
        $this->conversation = $conversation;

        try {
            return $work();
        } finally {
            [$this->public, $this->chatbot, $this->conversation] = $previous;
        }
    }
}
