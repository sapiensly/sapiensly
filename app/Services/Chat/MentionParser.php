<?php

namespace App\Services\Chat;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolves the agents a user @mentioned in a chat message.
 *
 * The composer is authoritative: it sends the explicit ids of the agents it
 * inserted as chips (`mentioned_agent_ids`), because agent names contain spaces
 * and there is no slug to parse reliably. As a convenience we also scan the raw
 * text for `@token` mentions and match them against agent names (slugified), so a
 * hand-typed `@finance` still resolves.
 *
 * All candidates are filtered to standalone agents the user can actually reach
 * (forAccountContext), deduplicated, kept in first-seen order, and capped.
 */
class MentionParser
{
    /** Maximum agents invoked from a single message; extras are dropped. */
    public const MAX_AGENTS = 5;

    /**
     * @param  array<int, string>  $mentionedAgentIds  ids of chip-inserted agents, in order
     * @return array{agents: Collection<int, Agent>, capped: bool}
     */
    public function resolve(User $user, array $mentionedAgentIds, ?string $text = null): array
    {
        $reachable = Agent::query()
            ->forAccountContext($user)
            ->standalone()
            ->get()
            ->keyBy('id');

        $bySlug = $reachable->keyBy(fn (Agent $a) => Str::slug((string) $a->name));

        /** @var array<int, Agent> $ordered */
        $ordered = [];
        $seen = [];

        $add = function (?Agent $agent) use (&$ordered, &$seen): void {
            if ($agent === null || isset($seen[$agent->id])) {
                return;
            }
            $seen[$agent->id] = true;
            $ordered[] = $agent;
        };

        // 1. Explicit chip ids (authoritative, ordered).
        foreach ($mentionedAgentIds as $id) {
            $add($reachable->get($id));
        }

        // 2. Fallback: @token matches against agent name slugs in the raw text.
        if (is_string($text) && $text !== '' && preg_match_all('/@([\w-]+)/u', $text, $matches)) {
            foreach ($matches[1] as $token) {
                $add($bySlug->get(Str::slug($token)));
            }
        }

        $capped = count($ordered) > self::MAX_AGENTS;

        return [
            'agents' => collect(array_slice($ordered, 0, self::MAX_AGENTS)),
            'capped' => $capped,
        ];
    }
}
