<?php

namespace App\Mcp\Tools\Platform;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mcp\Tools\SysadminTool;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Who belongs to an organization and in what role. Actions: add (seat an existing account), set_role (owner or member), deactivate (keep the membership on record but revoke access), activate, remove (delete the membership outright). Guarded: an organization is never left without an active owner — demoting or removing the last one is refused, because nobody could then administer that tenant. Adding does not change the person\'s active organization; they switch to it in the app. Audited.')]
class ManageOrganizationMembershipTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:120'],
            'user' => ['required', 'string', 'max:320'],
            'action' => ['required', 'string', 'in:add,set_role,activate,deactivate,remove'],
            'role' => ['sometimes', 'string', 'in:owner,member'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $organization = Organization::query()
            ->where('id', $validated['organization'])
            ->orWhere('slug', $validated['organization'])
            ->first();

        if ($organization === null) {
            return Response::error("No organization matches '{$validated['organization']}' (pass its id or slug).");
        }

        $user = str_contains($validated['user'], '@')
            ? User::whereRaw('lower(email) = ?', [strtolower($validated['user'])])->first()
            : User::find($validated['user']);

        if ($user === null) {
            return Response::error("No account matches '{$validated['user']}'. Create it with invite_platform_user first.");
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first();

        $action = $validated['action'];

        if ($action === 'add') {
            if ($membership !== null) {
                return Response::error("{$user->email} already belongs to '{$organization->name}' as {$membership->role?->value}. Use set_role or activate instead.");
            }

            $membership = OrganizationMembership::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => MembershipRole::from($validated['role'] ?? 'member'),
                'status' => MembershipStatus::Active,
            ]);

            return $this->respond($organization, $user, $membership, $actor, 'add',
                "Added {$user->email} to '{$organization->name}' as {$membership->role?->value}",
                'They keep their current active organization and switch to this one from the app.');
        }

        if ($membership === null) {
            return Response::error("{$user->email} does not belong to '{$organization->name}'. Use action=add first.");
        }

        return match ($action) {
            'set_role' => $this->setRole($organization, $user, $membership, $actor, $validated['role'] ?? null),
            'activate' => $this->setStatus($organization, $user, $membership, $actor, MembershipStatus::Active),
            'deactivate' => $this->setStatus($organization, $user, $membership, $actor, MembershipStatus::Inactive),
            'remove' => $this->remove($organization, $user, $membership, $actor),
            default => Response::error('Unsupported action.'),
        };
    }

    private function setRole(
        Organization $organization,
        User $user,
        OrganizationMembership $membership,
        User $actor,
        ?string $role,
    ): Response {
        if ($role === null) {
            return Response::error('`role` is required for set_role (owner | member).');
        }

        if ($membership->role?->value === $role) {
            return Response::error("{$user->email} is already {$role} in '{$organization->name}'.");
        }

        if ($role === 'member' && $this->wouldStrandOrganization($organization, $user)) {
            return Response::error(
                "Refused: {$user->email} is the only active owner of '{$organization->name}'. "
                .'Promote someone else to owner first, or that organization would have nobody who can administer it.'
            );
        }

        $previous = $membership->role?->value;
        $membership->update(['role' => MembershipRole::from($role)]);

        return $this->respond($organization, $user, $membership, $actor, 'set_role',
            "Changed {$user->email} from {$previous} to {$role} in '{$organization->name}'", null,
            ['from' => $previous, 'to' => $role]);
    }

    private function setStatus(
        Organization $organization,
        User $user,
        OrganizationMembership $membership,
        User $actor,
        MembershipStatus $status,
    ): Response {
        if ($membership->status === $status) {
            return Response::error("{$user->email} is already {$status->value} in '{$organization->name}'.");
        }

        if ($status !== MembershipStatus::Active && $this->wouldStrandOrganization($organization, $user)) {
            return Response::error(
                "Refused: {$user->email} is the only active owner of '{$organization->name}'. "
                .'Give another member the owner role first.'
            );
        }

        $previous = $membership->status?->value;
        $membership->update(['status' => $status]);

        return $this->respond($organization, $user, $membership, $actor,
            $status === MembershipStatus::Active ? 'activate' : 'deactivate',
            "Set {$user->email} to {$status->value} in '{$organization->name}'",
            $status === MembershipStatus::Active
                ? null
                : 'Their membership is on record but they lose access to this organization.',
            ['from' => $previous, 'to' => $status->value]);
    }

    private function remove(
        Organization $organization,
        User $user,
        OrganizationMembership $membership,
        User $actor,
    ): Response {
        if ($this->wouldStrandOrganization($organization, $user)) {
            return Response::error(
                "Refused: {$user->email} is the only active owner of '{$organization->name}'. "
                .'Promote another member to owner first.'
            );
        }

        $role = $membership->role?->value;
        $membership->delete();

        $this->audit(
            actor: $actor,
            summary: "Removed {$user->email} from '{$organization->name}'",
            meta: ['role' => $role],
            organizationId: $organization->id,
            targetType: 'organization_membership',
            targetId: (string) $user->id,
            targetLabel: $user->email,
        );

        return Response::json([
            'action' => 'remove',
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'user' => ['id' => $user->id, 'email' => $user->email],
            'note' => 'Membership deleted. Anything they created inside this organization stays with the organization.',
        ]);
    }

    /**
     * Whether losing this user as an active owner would leave the organization
     * with none — the state in which no one can administer that tenant.
     */
    private function wouldStrandOrganization(Organization $organization, User $user): bool
    {
        $otherActiveOwners = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', '!=', $user->id)
            ->where('role', MembershipRole::Owner->value)
            ->where('status', MembershipStatus::Active->value)
            ->count();

        return $otherActiveOwners === 0;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function respond(
        Organization $organization,
        User $user,
        OrganizationMembership $membership,
        User $actor,
        string $action,
        string $summary,
        ?string $note = null,
        array $meta = [],
    ): Response {
        $this->audit(
            actor: $actor,
            summary: $summary,
            meta: $meta,
            organizationId: $organization->id,
            targetType: 'organization_membership',
            targetId: (string) $user->id,
            targetLabel: $user->email,
        );

        return Response::json(array_filter([
            'action' => $action,
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'user' => ['id' => $user->id, 'email' => $user->email],
            'membership' => [
                'role' => $membership->role?->value,
                'status' => $membership->status?->value,
            ],
            'note' => $note,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization id (org_…) or slug.')->required(),
            'user' => $schema->string()->description('Email address or user id.')->required(),
            'action' => $schema->string()->description('add | set_role | activate | deactivate | remove')->required(),
            'role' => $schema->string()->description('owner | member. Used by add and set_role.'),
        ];
    }
}
