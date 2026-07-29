<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\AuthorizesOrganizationAdmin;
use App\Services\Site\SiteImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Import from your website", for both organization books at once.
 *
 * One endpoint rather than one per book: the Brandbook and the Contextbook are
 * read off the same page, and splitting them meant the admin typed the URL twice
 * and paid for the page to be downloaded twice.
 *
 * A URL that cannot be read comes back 200 with a `reason`, not a 422. The
 * caller is a form that has to say something specific and useful about it —
 * "that address is not a website" and "that site did not answer" are different
 * problems — and a validation error would collapse all of them into one
 * unhelpful message.
 */
class OrganizationSiteImportController extends Controller
{
    use AuthorizesOrganizationAdmin;

    public function store(Request $request, SiteImportService $import): JsonResponse
    {
        $organization = $this->authorizeOrganization($request, 'the organization books');

        $validated = $request->validate([
            'website' => ['nullable', 'string', 'max:300'],
            'brief' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($import->import(
            $organization,
            $validated['website'] ?? null,
            (string) ($validated['brief'] ?? ''),
            $request->user(),
        ));
    }
}
