<?php

namespace App\Services\Apps;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\App;
use App\Models\AppApiKey;
use App\Models\AppUserRole;
use App\Models\OrganizationMembership;
use App\Models\User;

/**
 * Resolves a user's effective capabilities on an app from the manifest's
 * permission model + the per-user grants in `app_user_roles`, into an immutable
 * {@see AppAccessContext}. This is the single source of truth consumed by every
 * runtime surface.
 *
 * Layering: runs AFTER HasVisibility (the entry gate) and WITHIN Postgres RLS
 * (org isolation). App roles can only ever NARROW what RLS already exposes.
 */
class AppAccessResolver
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function resolve(App $app, array $manifest, ?User $user, ?string $previewRoleSlug = null): AppAccessContext
    {
        // 1. Administrators bypass all app policies.
        if ($user !== null && $this->isAdministrator($app, $user)) {
            // …unless they asked to SEE the app as one of its roles. This is
            // the only way to check what a role actually gets without creating
            // a throwaway account, and it can only ever narrow: it is offered
            // exclusively to someone who already had unrestricted access, and
            // it hands them a real role's context instead.
            if ($previewRoleSlug !== null && $previewRoleSlug !== '') {
                $preview = $this->resolveForRole($manifest, $previewRoleSlug);
                if ($preview->hasAccess) {
                    return $preview;
                }
            }

            return AppAccessContext::bypass();
        }

        $permissions = $manifest['permissions'] ?? [];
        $roles = $permissions['roles'] ?? [];
        $accessMode = $permissions['access_mode'] ?? 'open';

        // 2-3. Determine the user's effective role slug(s).
        $roleSlugs = $this->resolveRoleSlugs($app, $roles, $accessMode, $user);
        if ($roleSlugs === []) {
            return AppAccessContext::denied();
        }

        $roleIdBySlug = [];
        foreach ($roles as $role) {
            $roleIdBySlug[$role['slug']] = $role['id'];
        }
        $userRoleIds = [];
        foreach ($roleSlugs as $slug) {
            if (isset($roleIdBySlug[$slug])) {
                $userRoleIds[] = $roleIdBySlug[$slug];
            }
        }

        $slugByFieldId = $this->slugByFieldId($manifest);

        return new AppAccessContext(...[
            'bypass' => false,
            'hasAccess' => true,
            'roleSlugs' => $roleSlugs,
            'viewablePageIds' => $this->viewablePages($manifest, $permissions['page_policies'] ?? [], $userRoleIds, $roleSlugs),
            ...$this->objectCapabilities($permissions['object_policies'] ?? [], $userRoleIds, $slugByFieldId),
        ]);
    }

    /**
     * The capabilities of a named app ROLE, with no person behind it — what an
     * API key acting as that role may do.
     *
     * Deliberately the SAME semantics a member holding the role gets, not the
     * portal's strict ones: a key that behaved differently from the role it
     * names would make "why does my key 403 when the UI works?" a permanent
     * support question. The narrowing that makes a key least-privilege lives on
     * the key's own scopes ({@see AppApiKey::allows()}), which are
     * checked alongside this — role is the ceiling, scope is the grant.
     *
     * There is no bypass here whatever the role is called: an app-owner-shaped
     * role does not turn a credential into an owner.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function resolveForRole(array $manifest, string $roleSlug): AppAccessContext
    {
        $permissions = $manifest['permissions'] ?? [];

        $role = null;
        foreach ($permissions['roles'] ?? [] as $candidate) {
            if (($candidate['slug'] ?? null) === $roleSlug) {
                $role = $candidate;
                break;
            }
        }

        // A key whose role was renamed or removed stops working rather than
        // falling back to the default role — a dangling grant must never widen.
        if ($role === null) {
            return AppAccessContext::denied();
        }

        $roleIds = [$role['id']];

        return new AppAccessContext(...[
            'bypass' => false,
            'hasAccess' => true,
            'roleSlugs' => [$role['slug']],
            'viewablePageIds' => $this->viewablePages($manifest, $permissions['page_policies'] ?? [], $roleIds, [$role['slug']]),
            ...$this->objectCapabilities(
                $permissions['object_policies'] ?? [],
                $roleIds,
                $this->slugByFieldId($manifest),
            ),
        ]);
    }

    /**
     * The capabilities of an ANONYMOUS visitor to a public portal — a stranger
     * with no account, no org membership and no grant.
     *
     * Everything about this path is deliberately narrower than {@see resolve()}:
     * there is no administrator bypass to reach, no default-role fallback (a
     * dangling role_id denies rather than widening), and the resulting context
     * is `strict`, so an object or page the author never wrote a policy for is
     * invisible instead of open. `allow_writes` is the outer gate: while it is
     * false the portal is read-only no matter what the object policies grant,
     * so a policy left over from an earlier design cannot open the door by
     * itself.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function resolvePublic(array $manifest): AppAccessContext
    {
        $permissions = $manifest['permissions'] ?? [];
        $public = $permissions['public'] ?? null;

        if (! is_array($public) || ($public['enabled'] ?? false) !== true) {
            return AppAccessContext::denied();
        }

        $roleId = (string) ($public['role_id'] ?? '');
        $role = null;
        foreach ($permissions['roles'] ?? [] as $candidate) {
            if (($candidate['id'] ?? null) === $roleId) {
                $role = $candidate;
                break;
            }
        }

        // Both refusals mirror a validator rail. They are repeated here because
        // a manifest saved before the rail existed must not become a leak the
        // day someone publishes it.
        if ($role === null || ($role['is_default'] ?? false) === true) {
            return AppAccessContext::denied();
        }

        $capabilities = $this->objectCapabilities(
            $permissions['object_policies'] ?? [],
            [$roleId],
            $this->slugByFieldId($manifest),
        );

        if (($public['allow_writes'] ?? false) !== true) {
            $capabilities['objectActions'] = array_map(
                fn (array $actions): array => array_values(array_intersect($actions, ['read'])),
                $capabilities['objectActions'],
            );
        }

        return new AppAccessContext(...[
            'bypass' => false,
            'hasAccess' => true,
            'roleSlugs' => [$role['slug']],
            'viewablePageIds' => $this->viewablePages(
                $manifest,
                $permissions['page_policies'] ?? [],
                [$roleId],
                [$role['slug']],
                strict: true,
            ),
            ...$capabilities,
            'strict' => true,
        ]);
    }

    private function isAdministrator(App $app, User $user): bool
    {
        if ($user->hasRole('sysadmin')) {
            return true;
        }
        if ($app->isOwnedBy($user)) {
            return true;
        }

        return $app->organization_id !== null
            && OrganizationMembership::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $app->organization_id)
                ->where('status', MembershipStatus::Active)
                ->where('role', MembershipRole::Owner)
                ->exists();
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     * @return list<string>
     */
    private function resolveRoleSlugs(App $app, array $roles, string $accessMode, ?User $user): array
    {
        $validSlugs = array_column($roles, 'slug');

        if ($user !== null) {
            $grant = AppUserRole::query()
                ->where('app_id', $app->id)
                ->where('assigned_user_id', $user->id)
                ->first();

            // A grant whose slug no longer exists in the manifest is dangling →
            // fall through to the default-role logic.
            if ($grant !== null && in_array($grant->role_slug, $validSlugs, true)) {
                return [$grant->role_slug];
            }
        }

        if ($accessMode === 'allowlist') {
            return []; // no grant ⇒ no access
        }

        // open: every visible member gets the default role. Defensive fallback to
        // the first role if none is flagged default (validator requires one).
        foreach ($roles as $role) {
            if (($role['is_default'] ?? false) === true) {
                return [$role['slug']];
            }
        }

        return isset($roles[0]['slug']) ? [$roles[0]['slug']] : [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array<string, mixed>>  $pagePolicies
     * @param  list<string>  $userRoleIds
     * @param  list<string>  $roleSlugs
     * @return array<string, true>
     */
    private function viewablePages(array $manifest, array $pagePolicies, array $userRoleIds, array $roleSlugs, bool $strict = false): array
    {
        $viewable = [];
        foreach ($manifest['pages'] ?? [] as $page) {
            if ($this->pageViewable($page, $pagePolicies, $userRoleIds, $roleSlugs, $strict)) {
                $viewable[$page['id']] = true;
            }
        }

        return $viewable;
    }

    /**
     * A page is viewable iff its visibility rule (if any) passes AND, when
     * page_policies exist for it, one of the user's roles is granted can_view.
     *
     * A page with NO policy at all is viewable for an org member and NOT for a
     * public visitor ($strict) — see the note on {@see AppAccessContext}.
     *
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $pagePolicies
     * @param  list<string>  $userRoleIds
     * @param  list<string>  $roleSlugs
     */
    private function pageViewable(array $page, array $pagePolicies, array $userRoleIds, array $roleSlugs, bool $strict = false): bool
    {
        $rule = $page['visibility'] ?? null;
        if (is_array($rule) && ! empty($rule['roles']) && array_intersect($roleSlugs, $rule['roles']) === []) {
            return false;
        }

        $forPage = array_filter($pagePolicies, fn (array $p): bool => ($p['page_id'] ?? null) === ($page['id'] ?? null));
        if ($forPage === []) {
            return ! $strict;
        }

        foreach ($forPage as $policy) {
            if (in_array($policy['role_id'] ?? null, $userRoleIds, true) && ($policy['can_view'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compute per-object actions / row_filter / hidden / readonly for the user's
     * roles. Most-permissive combine: actions union, row_filters OR (a role with
     * read but no filter ⇒ unrestricted), readonly union, hidden intersection.
     *
     * @param  list<array<string, mixed>>  $objectPolicies
     * @param  list<string>  $userRoleIds
     * @param  array<string, array<string, string>>  $slugByFieldId
     * @return array{objectsWithPolicies: array<string, true>, objectActions: array<string, list<string>>, objectRowFilters: array<string, array<string, mixed>|null>, objectHidden: array<string, list<string>>, objectReadonly: array<string, list<string>>}
     */
    private function objectCapabilities(array $objectPolicies, array $userRoleIds, array $slugByFieldId): array
    {
        $byObject = [];
        foreach ($objectPolicies as $policy) {
            $byObject[$policy['object_id']][] = $policy;
        }

        $objectsWithPolicies = [];
        $actions = [];
        $rowFilters = [];
        $hidden = [];
        $readonly = [];

        foreach ($byObject as $objectId => $policies) {
            $objectsWithPolicies[$objectId] = true;
            $mine = array_values(array_filter(
                $policies,
                fn (array $p): bool => in_array($p['role_id'] ?? null, $userRoleIds, true),
            ));

            $actionSet = [];
            $filters = [];
            $anyUnrestricted = false;
            $hiddenSets = [];
            $readonlySet = [];

            foreach ($mine as $policy) {
                foreach ($policy['actions'] ?? [] as $action) {
                    $actionSet[$action] = true;
                }
                if (isset($policy['row_filter'])) {
                    $filters[] = $policy['row_filter'];
                } else {
                    $anyUnrestricted = true;
                }
                $restrictions = $policy['field_restrictions'] ?? [];
                $hiddenSets[] = $this->mapSlugs($restrictions['hidden'] ?? [], $slugByFieldId[$objectId] ?? []);
                foreach ($this->mapSlugs($restrictions['readonly'] ?? [], $slugByFieldId[$objectId] ?? []) as $slug) {
                    $readonlySet[$slug] = true;
                }
            }

            $actions[$objectId] = array_keys($actionSet);
            $rowFilters[$objectId] = $this->mergeRowFilters($filters, $anyUnrestricted);
            $hidden[$objectId] = $this->intersectSets($hiddenSets);
            $readonly[$objectId] = array_keys($readonlySet);
        }

        return [
            'objectsWithPolicies' => $objectsWithPolicies,
            'objectActions' => $actions,
            'objectRowFilters' => $rowFilters,
            'objectHidden' => $hidden,
            'objectReadonly' => $readonly,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     * @return array<string, mixed>|null
     */
    private function mergeRowFilters(array $filters, bool $anyUnrestricted): ?array
    {
        if ($anyUnrestricted || $filters === []) {
            return null;
        }
        if (count($filters) === 1) {
            return $filters[0];
        }

        return ['op' => 'or', 'conditions' => array_values($filters)];
    }

    /**
     * @param  list<string>  $fieldIds
     * @param  array<string, string>  $slugMap
     * @return list<string>
     */
    private function mapSlugs(array $fieldIds, array $slugMap): array
    {
        $out = [];
        foreach ($fieldIds as $fieldId) {
            if (isset($slugMap[$fieldId])) {
                $out[] = $slugMap[$fieldId];
            }
        }

        return $out;
    }

    /**
     * @param  list<list<string>>  $sets
     * @return list<string>
     */
    private function intersectSets(array $sets): array
    {
        if ($sets === []) {
            return [];
        }
        $intersection = $sets[0];
        foreach (array_slice($sets, 1) as $set) {
            $intersection = array_values(array_intersect($intersection, $set));
        }

        return array_values($intersection);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, string>> objectId => (fieldId => slug)
     */
    private function slugByFieldId(array $manifest): array
    {
        $map = [];
        foreach ($manifest['objects'] ?? [] as $object) {
            $fields = [];
            foreach ($object['fields'] ?? [] as $field) {
                $fields[$field['id']] = $field['slug'];
            }
            $map[$object['id']] = $fields;
        }

        return $map;
    }
}
