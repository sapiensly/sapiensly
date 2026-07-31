<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Change an organization at the platform level. Actions: rename (display name only), suspend (soft-delete — the tenant stops being reachable, its data is retained and hidden, nothing is destroyed), restore (undo a suspend). Suspending is REVERSIBLE and is not a purge: rows stay in the database behind RLS, so this is not how you honour a deletion request. Renaming does not change the slug, so URLs and the MCP endpoint keep working. Audited.')]
class ManageOrganizationTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:120'],
            'action' => ['required', 'string', 'in:rename,suspend,restore'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
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

        $action = $validated['action'];

        return match ($action) {
            'rename' => $this->rename($organization, $actor, $validated['name'] ?? null),
            'suspend' => $this->suspend($organization, $actor),
            'restore' => $this->restore($organization, $actor),
        };
    }

    private function rename(Organization $organization, User $actor, ?string $name): Response
    {
        $name = trim((string) $name);

        if ($name === '') {
            return Response::error('`name` is required to rename an organization.');
        }

        $previous = $organization->name;
        $organization->update(['name' => $name]);

        $this->audit(
            actor: $actor,
            summary: "Renamed organization '{$previous}' to '{$name}'",
            meta: ['from' => $previous, 'to' => $name],
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            targetLabel: $name,
        );

        return Response::json([
            'action' => 'rename',
            'organization' => ['id' => $organization->id, 'name' => $name, 'slug' => $organization->slug],
            'note' => 'The slug is unchanged, so the MCP URL and every published URL still resolve.',
        ]);
    }

    private function suspend(Organization $organization, User $actor): Response
    {
        if ($organization->trashed()) {
            return Response::error("'{$organization->name}' is already suspended.");
        }

        $memberCount = $organization->memberships()->count();
        $organization->delete();

        $this->audit(
            actor: $actor,
            summary: "Suspended organization '{$organization->name}' ({$memberCount} member(s))",
            meta: ['members' => $memberCount],
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            targetLabel: $organization->name,
        );

        return Response::json([
            'action' => 'suspend',
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'members_affected' => $memberCount,
            'note' => 'Soft-deleted. The tenant\'s rows remain in the database (hidden by RLS) and its slug stays reserved. Reverse with action=restore.',
        ]);
    }

    private function restore(Organization $organization, User $actor): Response
    {
        if (! $organization->trashed()) {
            return Response::error("'{$organization->name}' is not suspended.");
        }

        $organization->restore();

        $this->audit(
            actor: $actor,
            summary: "Restored organization '{$organization->name}'",
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            targetLabel: $organization->name,
        );

        return Response::json([
            'action' => 'restore',
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'note' => 'Active again, with its data intact.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization id (org_…) or slug.')->required(),
            'action' => $schema->string()->description('rename | suspend | restore')->required(),
            'name' => $schema->string()->description('New display name. Required for rename.'),
        ];
    }
}
