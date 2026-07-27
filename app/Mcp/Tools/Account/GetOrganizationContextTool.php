<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use App\Models\OrganizationAiContext;
use App\Models\User;
use App\Support\Context\OrganizationContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("The organization's Contextbook: the business knowledge every AI interaction in this organization is grounded in — what it does, whom it serves, the language and tone it uses, its own vocabulary, what agents must never do, and its canonical links. The Brandbook's counterpart (that one says how the organization looks, this one says what it is). Read it before writing any copy, classifying anything, or answering as the organization, so you use its real products and its real words instead of invented ones. Returns null-valued fields when unset.")]
class GetOrganizationContextTool extends SapiensTool
{
    // No ability gate: like the Brandbook, this is org-level context any member may read.

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id === null) {
            return Response::error('This connection is not bound to an organization.');
        }

        $row = OrganizationAiContext::query()
            ->where('organization_id', $user->organization_id)
            ->first();

        $context = $row?->context() ?? OrganizationContext::fromArray(null);

        return Response::json([
            ...$context->toArray(),
            'enabled' => $row?->enabled ?? true,
            'estimated_tokens' => $row?->compiled_tokens ?? 0,
            'max_tokens' => OrganizationContext::MAX_TOKENS,
            'prompt_block' => $row?->injectableBlock(),
            'usage' => $context->isEmpty()
                ? 'Contextbook unset — nothing is injected into prompts. Set it with set_organization_context so every agent, chatbot and builder turn in this organization is grounded in the same facts.'
                : 'This block is prepended to the system prompt of every agent, chat, app assistant, workflow AI step and builder turn in the organization. Treat it as authoritative for the organization\'s own products, vocabulary and boundaries; it does not override an individual agent\'s instructions.',
        ]);
    }
}
