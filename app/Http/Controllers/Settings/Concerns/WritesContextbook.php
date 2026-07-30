<?php

namespace App\Http\Controllers\Settings\Concerns;

use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\User;
use App\Support\Context\OrganizationContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Contextbook write path: what a form may submit, what it means to merge it
 * over what is stored, and the per-call token ceiling that write has to respect.
 *
 * Shared with the Brandbook trait's screen: both books are edited on the identity
 * page and saved in one request, so the rules and the budget check have to be
 * callable from there without duplicating them.
 */
trait WritesContextbook
{
    /**
     * @return array<string, mixed>
     */
    protected function contextRules(): array
    {
        return [
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
        ];
    }

    /** Whether the submitted form carried anything about the Contextbook at all. */
    protected function submitsContextbook(array $validated): bool
    {
        return array_intersect_key($validated, $this->contextRules()) !== [];
    }

    /** The organization's Contextbook row, unsaved when it has none yet. */
    protected function contextRow(Organization $organization): OrganizationAiContext
    {
        return OrganizationAiContext::firstOrNew(['organization_id' => $organization->id])
            ->setRelation('organization', $organization);
    }

    /**
     * The Contextbook a submitted form describes: the submitted keys written over
     * what is stored. A form that carries half the book — the identity header on
     * its own, say — edits that half instead of erasing the rest, and a field the
     * form blanked arrives as an explicit null and clears.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function submittedContext(Organization $organization, array $validated): OrganizationContext
    {
        $stored = $this->contextRow($organization)->profile ?? [];
        $incoming = array_intersect_key($validated, $this->contextRules());

        return OrganizationContext::fromArray(array_merge($stored, $incoming));
    }

    /**
     * The block is billed on every call of every agent in this organization, so
     * the ceiling is enforced on write — never at injection time, where the only
     * options would be to truncate mid-sentence or overspend.
     *
     * Separate from the save so a screen that writes both books can refuse the
     * whole request before storing either half.
     */
    protected function assertContextFits(Organization $organization, OrganizationContext $context): void
    {
        $tokens = $context->estimatedTokens($organization->name);

        if ($tokens > OrganizationContext::MAX_TOKENS) {
            throw ValidationException::withMessages([
                'descriptor' => __(
                    'The context is too long (:tokens of :max tokens). Trim it, or move the long-form material to a Knowledge Base — this block travels with every single AI call.',
                    ['tokens' => $tokens, 'max' => OrganizationContext::MAX_TOKENS],
                ),
            ]);
        }
    }

    /**
     * Store the book and recompile the block the prompt path reads.
     *
     * @param  bool|null  $enabled  Null leaves the stored switch alone.
     */
    protected function saveContextbook(
        Organization $organization,
        OrganizationContext $context,
        ?bool $enabled,
        ?User $user,
    ): void {
        $this->assertContextFits($organization, $context);

        $row = $this->contextRow($organization);

        $row->fill([
            'profile' => $context->toArray(),
            'enabled' => $enabled ?? ($row->exists ? $row->enabled : true),
            'updated_by_id' => $user?->id,
        ])->recompile()->save();
    }
}
