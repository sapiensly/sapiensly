<?php

namespace App\Support\Chatbots;

use App\Enums\HandoffMode;

/**
 * What this organization can honestly do for a visitor who wants a person, and
 * the sentence that tells the bot so.
 *
 * The grounding instructions used to forbid the offer outright — "never promise
 * that a person is being fetched, because nothing is fetching one" — which was
 * true and is why it was written. This object is what makes it conditional: the
 * prohibition stays word for word when nothing can be promised, and relaxes
 * exactly as far as the system can deliver, never further.
 */
final class HandoffOffer
{
    private function __construct(
        public readonly HandoffMode $mode,
        public readonly ?string $channel = null,
    ) {}

    /** Someone is watching the inbox and can join this conversation. */
    public static function live(): self
    {
        return new self(HandoffMode::Live);
    }

    /** The organization named where people actually answer. */
    public static function redirect(string $channel): self
    {
        return new self(HandoffMode::Redirect, $channel);
    }

    /** Nobody to point at: the offer is to take their details. */
    public static function capture(): self
    {
        return new self(HandoffMode::Capture);
    }

    /**
     * The paragraph appended to a public bot's instructions.
     *
     * Both branches keep the hard part — no transfer, nobody is coming into this
     * chat — because neither is true today. What changes is what the bot may
     * offer instead, and both offers are promises the system keeps: a channel
     * the organization published, or a follow-up backed by a stored contact and
     * a workflow.
     */
    public function promptClause(): string
    {
        $shared = <<<'EOT'
Nobody is standing by in this chat. Never say you are transferring them,
connecting them, or fetching someone, and never say a person will reply here in
a moment — no one is coming into this conversation.
EOT;

        return match ($this->mode) {
            // The one branch that may promise a person, because someone is
            // demonstrably watching the inbox as this turn is answered.
            HandoffMode::Live => <<<'EOT'
Someone from the team IS watching right now and can join this conversation.

When they ask for a person — or when you have nothing useful left to offer —
call the `leave_message_for_the_team` tool. It puts this conversation in front
of the team, and the person who takes it replies HERE, in this chat.

So you may say a person has been asked to join. Say it plainly and without a
time: "aviso al equipo, alguien se une aquí en un momento" is honest; "en 2
minutos" is not, because you do not know. Take their email too if they offer it,
in case they close the page. After you have called the tool, stop answering for
the team and let the person speak.
EOT,
            HandoffMode::Redirect => $shared."\n\n".<<<EOT
What you MAY do when they want a person: point them at how this organization is
reached, exactly as written here — {$this->channel} — and offer to take their
name and email so someone follows up. Taking a message means CALLING the
`leave_message_for_the_team` tool. Call it before you tell them the message is
recorded, because the tool is what records it.
EOT,
            HandoffMode::Capture => $shared."\n\n".<<<'EOT'
What you MAY do when they want a person: offer to take their name and email so
someone follows up. Say it as what it is — a message left, not a call
transferred.

Taking a message means CALLING the `leave_message_for_the_team` tool. Call it
before you tell them the message is recorded, because the tool is what records
it: saying so without calling it leaves the person waiting for a reply nobody
knows to send.
EOT,
        };
    }
}
