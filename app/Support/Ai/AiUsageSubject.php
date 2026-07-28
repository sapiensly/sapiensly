<?php

namespace App\Support\Ai;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * What the model calls of the current turn should be billed to.
 *
 * The usage ledger already has the columns — `module` and `conversation_id` —
 * and the chatbot channels never filled them: every widget and WhatsApp turn
 * landed under the generic `agent` module with no conversation attached. So an
 * owner could see what their organization spent in total and nothing else. Not
 * what a given bot costs, not the cost of a conversation, not cost per
 * resolution, and not that one bot was being drained by someone hammering it.
 * You cannot improve what you cannot attribute.
 *
 * A context rather than an argument, for the same reason as
 * {@see PublicTurnContext}: one turn is served by several LLMService instances
 * (the channel's own, then the orchestrator's for a multi-agent bot), and a
 * value threaded through one of them misses the rest. Scoped by closure and
 * restored in a `finally`, so it cannot bleed into the next job on a worker.
 */
class AiUsageSubject
{
    private ?string $module = null;

    private ?string $conversationId = null;

    private ?Model $artifact = null;

    /** The module this turn bills to, or null to leave the caller's own label. */
    public function module(): ?string
    {
        return $this->module;
    }

    /** The conversation this turn belongs to, if any. */
    public function conversationId(): ?string
    {
        return $this->conversationId;
    }

    /**
     * The artifact this turn's spend belongs to — the bot, not the agent that
     * happened to answer for it. Without this the dashboard could only name a
     * chatbot by joining its conversation, and only for the two channels that
     * write one.
     */
    public function artifact(): ?Model
    {
        return $this->artifact;
    }

    /**
     * Run work billed to a channel, a conversation and the artifact serving it.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function attributedTo(string $module, ?string $conversationId, ?Model $artifact, Closure $work): mixed
    {
        $previousModule = $this->module;
        $previousConversation = $this->conversationId;
        $previousArtifact = $this->artifact;

        $this->module = $module;
        $this->conversationId = $conversationId;
        $this->artifact = $artifact;

        try {
            return $work();
        } finally {
            $this->module = $previousModule;
            $this->conversationId = $previousConversation;
            $this->artifact = $previousArtifact;
        }
    }
}
