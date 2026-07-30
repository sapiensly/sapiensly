<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\AuthorizesOrganizationAdmin;
use App\Http\Controllers\Settings\Concerns\WritesBrandbook;
use App\Http\Controllers\Settings\Concerns\WritesContextbook;
use App\Services\Site\SiteImportService;
use App\Support\Branding\ColorPalette;
use App\Support\Context\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's identity: the Brandbook and the Contextbook on one screen.
 *
 * They were a page each, and the split showed: both answer "who is this
 * organization?", both are read off the same website, and the general facts they
 * share (what the business does, its industry, its size, its URL) had to live on
 * one of them — so the admin typed the address on one page, imported, and then
 * went to the other page to save the other half of the same reading.
 *
 * Here the shared facts sit above two tabs and ONE save writes both books, which
 * is also why the token ceiling is checked before anything is stored: a
 * Contextbook over budget must not leave a half-saved Brandbook behind.
 *
 * Admin-gated like every organization-wide setting: what is saved here changes
 * the behaviour of every app, agent and chatbot at once.
 */
class OrganizationIdentityController extends Controller
{
    use AuthorizesOrganizationAdmin;
    use WritesBrandbook;
    use WritesContextbook;

    public function show(Request $request, SiteImportService $import): Response
    {
        $organization = $this->authorizeOrganization($request, 'the organization identity');

        $brand = $organization->brandbook();
        $row = $this->contextRow($organization);
        $context = $row->context();

        return Inertia::render('settings/OrganizationIdentity', [
            'brand' => $brand->toArray(),
            // The palette currently in effect (derived from the active accent) so
            // the page can show it without waiting for AI proposals.
            'palette' => ColorPalette::fromAccent($brand->effectiveAccent()),
            'context' => $context->toArray(),
            'enabled' => $row->enabled,
            // The exact block the models will read, and what it costs per call.
            'preview' => $context->promptBlock($organization->name),
            'tokens' => $context->estimatedTokens($organization->name),
            'maxTokens' => OrganizationContext::MAX_TOKENS,
            'formalityOptions' => OrganizationContext::FORMALITY,
            'unitOptions' => OrganizationContext::UNITS,
            'updatedAt' => $row->exists ? $row->updated_at?->toIso8601String() : null,
            // What the site import last read, so the page can offer that reading
            // back instead of asking for the address again.
            'lastImport' => $import->lastImport(),
        ]);
    }

    /**
     * Save both books. The payload is flat — the two vocabularies do not overlap —
     * so an error lands on the field the form already knows how to show it on.
     */
    public function update(Request $request): RedirectResponse
    {
        $organization = $this->authorizeOrganization($request, 'the organization identity');

        $validated = $request->validate([
            ...$this->brandRules(),
            ...$this->contextRules(),
            'enabled' => ['nullable', 'boolean'],
        ]);

        $writesContext = $this->submitsContextbook($validated) || $request->has('enabled');
        $context = $this->submittedContext($organization, $validated);

        // Refuse the whole save before touching either book, so an over-budget
        // Contextbook cannot leave a half-applied identity behind.
        if ($writesContext) {
            $this->assertContextFits($organization, $context);
        }

        if ($this->submitsBrandbook($validated)) {
            $this->saveBrandbook($organization, $validated);
        }

        if ($writesContext) {
            $this->saveContextbook(
                $organization,
                $context,
                $request->has('enabled') ? $request->boolean('enabled') : null,
                $request->user(),
            );
        }

        return back()->with('success', __('Organization identity updated.'));
    }
}
