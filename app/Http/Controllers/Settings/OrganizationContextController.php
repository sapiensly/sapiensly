<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\User;
use App\Services\Context\ContextProposalService;
use App\Support\Context\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages the organization Contextbook — the business knowledge every model
 * interaction in the organization is grounded in. Gated to organization
 * administrators like the Brandbook: what is saved here changes the behaviour of
 * every agent in the organization at once.
 */
class OrganizationContextController extends Controller
{
    public function show(Request $request): Response
    {
        $organization = $this->authorizeOrganization($request);
        $row = $this->row($organization);
        $context = $row->context();

        return Inertia::render('settings/OrganizationContext', [
            'context' => $context->toArray(),
            'enabled' => $row->enabled,
            'preview' => $context->promptBlock($organization->name),
            'tokens' => $context->estimatedTokens($organization->name),
            'maxTokens' => OrganizationContext::MAX_TOKENS,
            'formalityOptions' => OrganizationContext::FORMALITY,
            'unitOptions' => OrganizationContext::UNITS,
            'updatedAt' => $row->exists ? $row->updated_at?->toIso8601String() : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->authorizeOrganization($request);
        $validated = $this->validated($request);

        $row = $this->row($organization);
        $context = OrganizationContext::fromArray($validated);

        // The block is billed on every call of every agent in this organization,
        // so the ceiling is enforced here, on write — never at injection time,
        // where the only options would be to truncate mid-sentence or overspend.
        $tokens = $context->estimatedTokens($organization->name);
        if ($tokens > OrganizationContext::MAX_TOKENS) {
            throw ValidationException::withMessages([
                'descriptor' => __(
                    'The context is too long (:tokens of :max tokens). Trim it, or move the long-form material to a Knowledge Base — this block travels with every single AI call.',
                    ['tokens' => $tokens, 'max' => OrganizationContext::MAX_TOKENS],
                ),
            ]);
        }

        $row->fill([
            'profile' => $context->toArray(),
            'enabled' => $request->boolean('enabled', true),
            'updated_by_id' => $request->user()?->id,
        ])->recompile()->save();

        return back()->with('success', __('Contextbook updated.'));
    }

    /**
     * Render the block for an unsaved form state, so the page can show exactly
     * what the models will read (and what it costs) before anything is stored.
     */
    public function preview(Request $request): JsonResponse
    {
        $organization = $this->authorizeOrganization($request);
        $context = OrganizationContext::fromArray($this->validated($request));

        return response()->json([
            'preview' => $context->promptBlock($organization->name),
            'tokens' => $context->estimatedTokens($organization->name),
            'max_tokens' => OrganizationContext::MAX_TOKENS,
        ]);
    }

    /**
     * Draft a Contextbook from the organization's website and a one-line brief,
     * so the form is never a blank page. Nothing is stored — the draft lands in
     * the form for a human to edit and save like any manual entry.
     */
    public function propose(Request $request, ContextProposalService $proposals): JsonResponse
    {
        $organization = $this->authorizeOrganization($request);

        $validated = $request->validate([
            'website' => ['nullable', 'string', 'max:300', 'url'],
            'brief' => ['nullable', 'string', 'max:2000'],
        ]);

        // The stored profile goes in so the draft comes back knowing which of its
        // fields would overwrite something a human already wrote. Nothing here
        // applies anything — the UI reviews the conflicts and the user decides.
        $draft = $proposals->propose(
            $validated['website'] ?? null,
            trim((string) ($validated['brief'] ?? '')),
            $request->user(),
            $this->row($organization)->profile ?? [],
        );

        $context = OrganizationContext::fromArray($draft['profile']);

        return response()->json([
            ...$draft,
            'preview' => $context->promptBlock($organization->name),
            'tokens' => $context->estimatedTokens($organization->name),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
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
        ]);
    }

    /** The organization's Contextbook row, unsaved when it has none yet. */
    private function row(Organization $organization): OrganizationAiContext
    {
        return OrganizationAiContext::firstOrNew(['organization_id' => $organization->id])
            ->setRelation('organization', $organization);
    }

    private function authorizeOrganization(Request $request): Organization
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->organization_id !== null && ($user->hasRole('owner') || $user->hasRole('sysadmin')),
            403,
            'Only an organization administrator can manage the context.',
        );

        return $user->organization;
    }
}
