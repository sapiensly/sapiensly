<?php

namespace App\Services\Apps;

/**
 * Immutable, precomputed view of a user's effective capabilities on one app,
 * produced by {@see AppAccessResolver}. Every runtime surface (page render,
 * block stripping, data resolution, action execution, agent tools) reads from
 * this — it never recomputes policy logic.
 *
 * Two short-circuits:
 *  - $bypass  → owner / app-owner / sysadmin: everything allowed, policies skipped.
 *  - !$hasAccess → allowlist app with no grant (or open app with no role at all):
 *    the user must be denied entry (403). Only meaningful when !$bypass.
 *
 * When the app declares roles but NO object/page policies, the resolver leaves
 * the per-object/page maps empty and these accessors fall back to "allowed",
 * preserving the open-within-visibility behaviour of apps without authored
 * policies.
 */
final class AppAccessContext
{
    public const ACTIONS = ['create', 'read', 'update', 'delete'];

    /**
     * @param  list<string>  $roleSlugs
     * @param  array<string, true>  $viewablePageIds
     * @param  array<string, true>  $objectsWithPolicies
     * @param  array<string, list<string>>  $objectActions
     * @param  array<string, array<string, mixed>|null>  $objectRowFilters
     * @param  array<string, list<string>>  $objectHidden  field slugs
     * @param  array<string, list<string>>  $objectReadonly  field slugs
     */
    public function __construct(
        public readonly bool $bypass,
        public readonly bool $hasAccess,
        public readonly array $roleSlugs = [],
        private readonly array $viewablePageIds = [],
        private readonly array $objectsWithPolicies = [],
        private readonly array $objectActions = [],
        private readonly array $objectRowFilters = [],
        private readonly array $objectHidden = [],
        private readonly array $objectReadonly = [],
    ) {}

    public static function bypass(): self
    {
        return new self(bypass: true, hasAccess: true);
    }

    public static function denied(): self
    {
        return new self(bypass: false, hasAccess: false);
    }

    public function canViewPage(string $pageId): bool
    {
        return $this->bypass || isset($this->viewablePageIds[$pageId]);
    }

    /**
     * Allowed CRUD actions on an object. Objects with no authored policy are
     * fully open (within app visibility); objects WITH policies return only the
     * union granted to the user's roles (possibly empty ⇒ no access).
     *
     * @return list<string>
     */
    public function objectActions(string $objectId): array
    {
        if ($this->bypass || ! isset($this->objectsWithPolicies[$objectId])) {
            return self::ACTIONS;
        }

        return $this->objectActions[$objectId] ?? [];
    }

    public function can(string $objectId, string $action): bool
    {
        return in_array($action, $this->objectActions($objectId), true);
    }

    /**
     * The role's row_filter (a manifest filter_expression) for an object, ANDed
     * onto reads/writes downstream. Null ⇒ unrestricted within the org's RLS rows.
     *
     * @return array<string, mixed>|null
     */
    public function rowFilter(string $objectId): ?array
    {
        if ($this->bypass) {
            return null;
        }

        return $this->objectRowFilters[$objectId] ?? null;
    }

    /** @return list<string> field slugs hidden from reads for this user. */
    public function hiddenFieldSlugs(string $objectId): array
    {
        if ($this->bypass) {
            return [];
        }

        return $this->objectHidden[$objectId] ?? [];
    }

    /** @return list<string> field slugs the user may not write. */
    public function readonlyFieldSlugs(string $objectId): array
    {
        if ($this->bypass) {
            return [];
        }

        return $this->objectReadonly[$objectId] ?? [];
    }

    /**
     * Evaluate a block/nav `visibility` rule against the user's roles. No rule ⇒
     * visible. A `roles` list ⇒ visible iff the user holds one. (An `expression`
     * with no roles is treated as visible for now — expression eval is deferred.)
     *
     * @param  array<string, mixed>|null  $rule
     */
    public function isBlockVisible(?array $rule): bool
    {
        if ($this->bypass || $rule === null) {
            return true;
        }

        $roles = $rule['roles'] ?? null;
        if (is_array($roles) && $roles !== []) {
            return array_intersect($this->roleSlugs, $roles) !== [];
        }

        return true;
    }
}
