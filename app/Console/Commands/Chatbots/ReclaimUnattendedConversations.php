<?php

namespace App\Console\Commands\Chatbots;

use App\Models\Chatbot;
use App\Services\Chatbots\ConversationTakeover;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Hands back conversations a person took and then went quiet on.
 *
 * The failure mode this exists for is produced by the handoff WORKING: someone
 * clicks takeover, the bot goes silent, and then they get pulled into a meeting.
 * The visitor is left staring at a chat where a person was announced and nothing
 * ever comes — worse than never having offered. So the offer is bounded by
 * something that runs whether or not anyone remembers.
 *
 * Scope is established per bot: `widget_conversations` is RLS-protected, and a
 * scheduled command has no ambient tenant scope, so a global query would match
 * nothing and report a cheerful zero. Chatbots live in the `platform` schema and
 * are readable without it, which is what makes the per-owner loop possible.
 */
#[Signature('chatbot:reclaim-unattended')]
#[Description('Return to the bot any conversation whose operator has gone quiet.')]
class ReclaimUnattendedConversations extends Command
{
    public function handle(ConversationTakeover $takeover, TenantContext $tenant): int
    {
        $reclaimed = 0;

        Chatbot::query()->cursor()->each(function (Chatbot $chatbot) use ($takeover, $tenant, &$reclaimed): void {
            $tenant->set($chatbot->organization_id, $chatbot->user_id);

            $reclaimed += $takeover->reclaimUnattended($chatbot);
        });

        $tenant->forget();

        if ($reclaimed > 0) {
            $this->info("Returned {$reclaimed} unattended conversation(s) to the bot.");
        }

        return self::SUCCESS;
    }
}
