<?php

namespace App\Services\Apps;

use App\Enums\MembershipStatus;
use App\Models\App;
use App\Models\AppUserRole;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Shared assignment logic behind the app "Access" surface: list the org roster
 * with each member's current app role, grant/replace a member's role, and revoke
 * it. Extracted from the HTTP controller so a future MCP tool reuses the same
 * validation (slug ∈ manifest roles, member ∈ the app's org).
 *
 * App roles live in `app_user_roles` (tenant schema, RLS) and store the role
 * SLUG — stable across manifest versions, unlike the regenerated role id.
 */
class AppRoleAssignmentService
{
    /**
     * The org roster paired with each member's current app-role slug (null when
     * unassigned), plus the manifest's role catalog and access mode — everything
     * the Access panel renders.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{access_mode: string, roles: list<array{slug: string, name: string, is_default: bool}>, members: list<array{user_id: int, name: string, email: string, role_slug: string|null, assignment_id: string|null}>}
     */
    public function roster(App $app, array $manifest): array
    {
        $permissions = $manifest['permissions'] ?? [];
        $roles = array_map(fn (array $r): array => [
            'slug' => $r['slug'],
            'name' => $r['name'] ?? $r['slug'],
            'is_default' => ($r['is_default'] ?? false) === true,
        ], $permissions['roles'] ?? []);

        $assignmentsByUser = AppUserRole::query()
            ->where('app_id', $app->id)
            ->get()
            ->keyBy('assigned_user_id');

        $members = [];
        if ($app->organization_id !== null) {
            $memberships = OrganizationMembership::query()
                ->where('organization_id', $app->organization_id)
                ->where('status', MembershipStatus::Active)
                ->with('user:id,name,email')
                ->get();

            foreach ($memberships as $membership) {
                $user = $membership->user;
                if ($user === null) {
                    continue;
                }
                $assignment = $assignmentsByUser->get($user->id);
                $members[] = [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_slug' => $assignment?->role_slug,
                    'assignment_id' => $assignment?->id,
                ];
            }
        }

        return [
            'access_mode' => $permissions['access_mode'] ?? 'open',
            'roles' => array_values($roles),
            'members' => $members,
        ];
    }

    /**
     * Grant (or replace) a member's app role. Validates the slug against the
     * manifest's roles and that the target is an active member of the app's org.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws ValidationException
     */
    public function assign(App $app, array $manifest, User $actor, int $assignedUserId, string $roleSlug): AppUserRole
    {
        $validSlugs = array_column($manifest['permissions']['roles'] ?? [], 'slug');
        if (! in_array($roleSlug, $validSlugs, true)) {
            throw ValidationException::withMessages([
                'role_slug' => "Role '{$roleSlug}' is not defined in this app.",
            ]);
        }

        if ($app->organization_id === null) {
            throw ValidationException::withMessages([
                'assigned_user_id' => 'This app is not owned by an organization, so roles cannot be assigned.',
            ]);
        }

        $isMember = OrganizationMembership::query()
            ->where('organization_id', $app->organization_id)
            ->where('user_id', $assignedUserId)
            ->where('status', MembershipStatus::Active)
            ->exists();
        if (! $isMember) {
            throw ValidationException::withMessages([
                'assigned_user_id' => 'That user is not an active member of this app\'s organization.',
            ]);
        }

        return AppUserRole::updateOrCreate(
            ['app_id' => $app->id, 'assigned_user_id' => $assignedUserId],
            [
                'organization_id' => $app->organization_id,
                'role_slug' => $roleSlug,
                'granted_by_user_id' => $actor->id,
            ],
        );
    }

    /**
     * Revoke an assignment, scoped to the app so a stray id from another app
     * can't be deleted through this app's surface. Returns whether a row was
     * removed (false ⇒ already gone / not this app's).
     */
    public function revoke(App $app, string $assignmentId): bool
    {
        return AppUserRole::query()
            ->where('app_id', $app->id)
            ->whereKey($assignmentId)
            ->delete() > 0;
    }
}
