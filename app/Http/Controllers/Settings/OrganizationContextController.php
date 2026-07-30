<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\AuthorizesOrganizationAdmin;
use App\Http\Controllers\Settings\Concerns\WritesContextbook;
use App\Support\Context\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Contextbook's own endpoint: rendering the block for form state that has not
 * been saved yet. Reading and writing the book itself lives on the organization
 * identity screen, which saves it alongside the Brandbook — see
 * {@see OrganizationIdentityController}.
 */
class OrganizationContextController extends Controller
{
    use AuthorizesOrganizationAdmin;
    use WritesContextbook;

    /**
     * Render the block for an unsaved form state, so the page can show exactly
     * what the models will read (and what it costs) before anything is stored.
     *
     * The form posts the identity payload whole; the brand half of it is simply
     * not part of these rules, and validation ignores what it was not asked about.
     */
    public function preview(Request $request): JsonResponse
    {
        $organization = $this->authorizeOrganization($request, 'the context');

        $context = OrganizationContext::fromArray($request->validate($this->contextRules()));

        return response()->json([
            'preview' => $context->promptBlock($organization->name),
            'tokens' => $context->estimatedTokens($organization->name),
            'max_tokens' => OrganizationContext::MAX_TOKENS,
        ]);
    }
}
