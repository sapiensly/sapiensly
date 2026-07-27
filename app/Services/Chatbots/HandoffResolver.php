<?php

namespace App\Services\Chatbots;

use App\Models\OrganizationAiContext;
use App\Support\Chatbots\HandoffOffer;

/**
 * Answers one question, for the prompt and for the escalation alike: what can
 * this organization honestly do right now for a visitor who wants a person?
 *
 * One place decides, so the bot's promise and the system's behaviour cannot
 * disagree. The old failure was exactly that disagreement — a `human_handoff`
 * node that wrote a metadata key nobody read while the bot said help was on the
 * way.
 *
 * This is where live takeover plugs in when it exists: presence becomes a third
 * answer above the two below, and nothing else in the system has to learn about
 * it. Until then the resolver never returns an answer the product cannot honour.
 *
 * Deliberately not a container singleton, for the same reason as
 * OrganizationContextResolver: on a long-lived worker the memo must not outlive
 * the service and keep serving an escalation channel the organization has since
 * changed.
 */
class HandoffResolver
{
    /** @var array<string, HandoffOffer> */
    private array $memo = [];

    public function __construct(
        private readonly OperatorPresence $presence = new OperatorPresence,
    ) {}

    public function forOwner(?string $organizationId, ?int $userId = null): HandoffOffer
    {
        // Deliberately OUTSIDE the memo: whether someone is watching changes
        // minute to minute, and even a service that lives for one turn would be
        // long enough to promise a person who has since left. The Contextbook
        // lookup below is the part worth memoizing.
        if ($this->presence->anyoneWatching($organizationId, $userId)) {
            return HandoffOffer::live();
        }

        if ($organizationId === null || $organizationId === '') {
            return HandoffOffer::capture();
        }

        return $this->memo[$organizationId] ??= $this->load($organizationId);
    }

    private function load(string $organizationId): HandoffOffer
    {
        $row = OrganizationAiContext::query()
            ->where('organization_id', $organizationId)
            ->first();

        // The Contextbook's `escalation` field is already the organization's own
        // answer to "who do people talk to when the bot cannot help" — written
        // for humans, in their words. Reusing it means a team that filled in the
        // Contextbook has already configured this without knowing it existed.
        $channel = trim((string) ($row?->context()->escalation ?? ''));

        return $channel === ''
            ? HandoffOffer::capture()
            : HandoffOffer::redirect($channel);
    }
}
