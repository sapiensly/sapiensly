<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\Organization;
use App\Models\User;
use App\Services\Platform\OrganizationPurge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('PERMANENTLY destroy a suspended organization and everything belonging to it: every row in every table carrying its tenant key, across both schemas, plus its stored files and cached values. This is how you honour a deletion request; manage_organization action=suspend is NOT (it only hides). IRREVERSIBLE — there is no restore afterwards and no backup taken. Two rails: the organization must already be suspended, and `confirm` must equal its slug. Call with dry_run=true first to see exactly what would go. User accounts survive (a person is not tenant property) and so does the audit log (it is the record that this happened). Audited.')]
class PurgeOrganizationTool extends SysadminTool
{
    public function handle(Request $request, OrganizationPurge $purge): Response
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:120'],
            'confirm' => ['sometimes', 'nullable', 'string', 'max:120'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $organization = Organization::query()
            ->withTrashed()
            ->where('id', $validated['organization'])
            ->orWhere('slug', $validated['organization'])
            ->first();

        if ($organization === null) {
            return Response::error("No organization matches '{$validated['organization']}' (pass its id or slug).");
        }

        if (($validated['dry_run'] ?? false) === true) {
            $report = $purge->preview($organization);

            return Response::json([
                'action' => 'purge',
                'dry_run' => true,
                'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
                'suspended' => $organization->trashed(),
                'would_delete' => $report,
                'note' => $organization->trashed()
                    ? "Nothing was deleted. To go ahead, call again with confirm='{$organization->slug}'."
                    : 'Nothing was deleted, and a purge would be refused: suspend the organization first.',
            ]);
        }

        // Suspended first, always. A reversible step before the irreversible
        // one is the only thing that makes a mis-aimed purge survivable — and
        // it means somebody had to decide twice.
        if (! $organization->trashed()) {
            return Response::error("'{$organization->name}' is not suspended. Suspend it first (manage_organization action=suspend); that step is reversible and this one is not.");
        }

        // The slug rather than a yes: a confirmation somebody can give without
        // reading it is not a confirmation, and this destroys a whole tenant.
        if (($validated['confirm'] ?? null) !== $organization->slug) {
            return Response::error("To destroy '{$organization->name}' pass confirm='{$organization->slug}'. This cannot be undone and no backup is taken.");
        }

        $before = $purge->preview($organization);
        $result = $purge->purge($organization, $actor->id);

        // Written AFTER, and to the one table the purge does not touch.
        $this->audit(
            actor: $actor,
            summary: sprintf(
                "PURGED organization '%s' (%s): %s row(s), %s file(s) destroyed",
                $organization->name,
                $organization->slug,
                number_format($result['rows']),
                number_format($result['files']),
            ),
            meta: [
                'slug' => $organization->slug,
                'rows' => $result['rows'],
                'files' => $result['files'],
                'files_failed' => $result['files_failed'],
                'tables' => $before['tables'],
                'stuck' => $result['stuck'],
            ],
            organizationId: null,
            targetType: 'organization',
            targetId: $organization->id,
            targetLabel: $organization->name,
        );

        return Response::json([
            'action' => 'purge',
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'destroyed' => [
                'rows' => $result['rows'],
                'files' => $result['files'],
                'tables' => $result['tables'],
            ],
            // Both failure modes are reported rather than folded into success:
            // bytes left on a bucket and rows left in a table are exactly what
            // somebody who asked for deletion needs to hear about.
            'files_failed' => $result['files_failed'],
            'tables_not_emptied' => $result['stuck'],
            'kept' => $result['kept'],
            'note' => $result['stuck'] === [] && $result['files_failed'] === 0
                ? 'Gone. User accounts and the audit log survive by design.'
                : 'Incomplete — see tables_not_emptied and files_failed. Re-running is safe and picks up what is left.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization id (org_…) or slug. Must already be suspended.')->required(),
            'confirm' => $schema->string()->description('The organization SLUG, typed back. Required to destroy anything.'),
            'dry_run' => $schema->boolean()->description('Report what would be destroyed and delete nothing. Do this first.'),
        ];
    }
}
