<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\User;
use App\Support\Context\OrganizationContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Set the organization's Contextbook — the business knowledge injected into every AI interaction in this organization. Only the fields you pass are changed; pass null to clear one (list fields are replaced wholesale). Keep it short and factual: the block is billed on EVERY model call in the organization, so it is capped and the call is rejected if it exceeds the cap — long-form material belongs in a Knowledge Base instead. Organization owners / sysadmins only.")]
class SetOrganizationContextTool extends SapiensTool
{
    // No ability gate; owner/sysadmin-gated below, like the web Contextbook page.

    /** Canonical Contextbook fields this tool accepts. */
    private const FIELDS = [
        'descriptor', 'industry', 'size', 'website',
        'audience', 'geographies', 'timezone', 'currency', 'units',
        'language', 'formality', 'tone_notes',
        'glossary', 'offerings', 'never', 'escalation', 'disclaimer', 'links',
    ];

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id === null) {
            return Response::error('This connection is not bound to an organization.');
        }
        if (! $user->hasRole('owner') && ! $user->hasRole('sysadmin')) {
            return Response::error('Only an organization owner or sysadmin can edit the context.');
        }

        $validated = $request->validate([
            'descriptor' => ['nullable', 'string', 'max:240'],
            'industry' => ['nullable', 'string', 'max:80'],
            'size' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:300', 'url'],
            'audience' => ['nullable', 'string', 'max:400'],
            'geographies' => ['nullable', 'array', 'max:10'],
            'geographies.*' => ['nullable', 'string', 'max:60'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'units' => ['nullable', Rule::in(OrganizationContext::UNITS)],
            'language' => ['nullable', 'string', 'max:20'],
            'formality' => ['nullable', Rule::in(OrganizationContext::FORMALITY)],
            'tone_notes' => ['nullable', 'string', 'max:240'],
            'glossary' => ['nullable', 'array', 'max:20'],
            'glossary.*.term' => ['nullable', 'string', 'max:40'],
            'glossary.*.meaning' => ['nullable', 'string', 'max:160'],
            'offerings' => ['nullable', 'array', 'max:10'],
            'offerings.*.name' => ['nullable', 'string', 'max:60'],
            'offerings.*.description' => ['nullable', 'string', 'max:160'],
            'never' => ['nullable', 'array', 'max:10'],
            'never.*' => ['nullable', 'string', 'max:160'],
            'escalation' => ['nullable', 'string', 'max:240'],
            'disclaimer' => ['nullable', 'string', 'max:240'],
            'links' => ['nullable', 'array', 'max:8'],
            'links.*.label' => ['nullable', 'string', 'max:60'],
            'links.*.url' => ['nullable', 'string', 'max:300', 'url'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $organization = Organization::find($user->organization_id);
        if ($organization === null) {
            return Response::error('Organization not found.');
        }

        $row = OrganizationAiContext::firstOrNew(['organization_id' => $organization->id])
            ->setRelation('organization', $organization);

        // Merge only the keys present in the call over the stored profile, then
        // normalize — a partial set leaves untouched fields intact.
        $incoming = array_intersect_key($validated, array_flip(self::FIELDS));
        $context = OrganizationContext::fromArray(array_merge($row->profile ?? [], $incoming));

        $tokens = $context->estimatedTokens($organization->name);
        if ($tokens > OrganizationContext::MAX_TOKENS) {
            return Response::error(
                "The context would render to {$tokens} tokens, over the ".OrganizationContext::MAX_TOKENS.' cap. '
                .'Trim it, or move the long-form material into a Knowledge Base — this block travels with every single AI call.',
            );
        }

        $row->fill([
            'profile' => $context->toArray(),
            'enabled' => $validated['enabled'] ?? $row->enabled,
            'updated_by_id' => $user->id,
        ])->recompile()->save();

        return Response::json([
            'updated' => true,
            'estimated_tokens' => $row->compiled_tokens ?? 0,
            'max_tokens' => OrganizationContext::MAX_TOKENS,
            'context' => $context->toArray(),
            'prompt_block' => $row->injectableBlock(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'descriptor' => $schema->string()->description('One line on what the organization actually does. Max 240 chars.'),
            'industry' => $schema->string()->description('Industry or sector.'),
            'size' => $schema->string()->description('Rough size, in the organization\'s own words (e.g. "~120 people").'),
            'website' => $schema->string()->description('Primary website URL.'),
            'audience' => $schema->string()->description('Whom it serves — segments, ideal customer. Max 400 chars.'),
            'geographies' => $schema->array()->description('Countries or regions it operates in. Replaces the stored list. Max 10.'),
            'timezone' => $schema->string()->description('IANA timezone (e.g. America/Mexico_City). Models are grounded in this local time instead of UTC.'),
            'currency' => $schema->string()->description('ISO 4217 code (e.g. MXN). Amounts are assumed to be in it unless stated otherwise.'),
            'units' => $schema->string()->enum(OrganizationContext::UNITS)->description('Measurement system.'),
            'language' => $schema->string()->description('BCP-47 tag agents reply in (e.g. es-MX), or "auto" to always match the user.'),
            'formality' => $schema->string()->enum(OrganizationContext::FORMALITY)->description('How formal agents should sound.'),
            'tone_notes' => $schema->string()->description('Short free-text tone guidance.'),
            'glossary' => $schema->array()->description('Organization-specific vocabulary: array of {term, meaning}. The highest-value field — internal terms whose meaning differs from the common one. Replaces the stored list. Max 20.'),
            'offerings' => $schema->array()->description('Main products/services: array of {name, description}. Replaces the stored list. Max 10.'),
            'never' => $schema->array()->description('Things an agent must never do, one short sentence each (e.g. "Quote prices"). Replaces the stored list. Max 10.'),
            'escalation' => $schema->string()->description('Where an agent should send what it cannot handle.'),
            'disclaimer' => $schema->string()->description('A disclaimer agents must state when relevant.'),
            'links' => $schema->array()->description('Canonical URLs: array of {label, url}, so agents cite real links instead of inventing them. Replaces the stored list. Max 8.'),
            'enabled' => $schema->boolean()->description('Whether the Contextbook is injected into prompts at all. Defaults to on.'),
        ];
    }
}
