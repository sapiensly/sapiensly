<?php

namespace App\Services\Manifest;

/**
 * Lets a patch name things the way a person does — by slug — and turns those
 * names into ids before anything is validated.
 *
 * `unresolved_ref` was 18 of 30 rejected patches across the two builds in the
 * failure ledger, far and away the most expensive thing a build does wrong: each
 * rejection is a whole round trip re-billed against the cached conversation. And
 * the rejections were not random. Read verbatim, they are pattern-completion:
 * one build aimed a relation at `obj_01kzaeq206ma38yxvtp0ewy24x`, which is the
 * APP's ULID with the prefix swapped; another wrote `fld_…1r3qvz` where the real
 * id was `pag_…1r3qwf`, and then repeated that invented id across six pointers
 * in one call.
 *
 * The cause is the id format, not the model's care. Every ULID minted inside one
 * build shares its timestamp prefix — every id in a given app starts with the
 * same ten characters and differs only in the tail — which is the worst possible
 * string to copy accurately. The builder is asked to reproduce dozens of them
 * while it reasons in names.
 *
 * So it no longer has to. Anywhere an id is expected, a slug now works: the
 * resolver knows the thing the model does not, which is the SCOPE. A column's
 * `field_id` is scoped by its block's queried object, so `"sku"` resolves
 * exactly, where `fld_…akc3` could only be guessed at.
 *
 * Ids always win. A value that already matches a real id is left alone, and only
 * a value matching nothing is looked up as a slug — so this can never re-point an
 * existing reference, and a slug that cannot be resolved is left untouched for
 * the validator to reject exactly as before. The id PATTERN cannot be used to
 * tell the two apart (`fecha_inicio_programada` satisfies it), which is why
 * existence, not shape, is the test.
 */
class ManifestRefResolver
{
    /**
     * Field-id lists that are bare arrays of strings rather than `*_id` keys.
     * `permissions.object_policies[].field_restrictions.hidden` is where two of
     * the ledger's rejections landed.
     */
    private const FIELD_ID_LISTS = ['hidden', 'readonly', 'required'];

    /** @var array<string, string> slug => object id */
    private array $objectsBySlug = [];

    /** @var array<string, true> */
    private array $objectIds = [];

    /** @var array<string, array<string, string>> object id => [slug => field id] */
    private array $fieldsByObject = [];

    /** @var array<string, true> */
    private array $fieldIds = [];

    /** @var array<string, string> field id => target object id (relations) */
    private array $relationTargets = [];

    /** @var array<string, string> slug => page id */
    private array $pagesBySlug = [];

    /** @var array<string, true> */
    private array $pageIds = [];

    /** @var array<string, string> slug => role id */
    private array $rolesBySlug = [];

    /** @var array<string, true> */
    private array $roleIds = [];

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public static function resolve(array $manifest): array
    {
        return (new self)->run($manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function run(array $manifest): array
    {
        $this->index($manifest);

        // Nothing to resolve against — an app mid-build, or one with no objects.
        if ($this->objectIds === [] && $this->pageIds === [] && $this->roleIds === []) {
            return $manifest;
        }

        foreach ($manifest['objects'] ?? [] as $i => $object) {
            if (is_array($object)) {
                $manifest['objects'][$i] = $this->node($object, (string) ($object['id'] ?? ''));
            }
        }

        foreach (['pages', 'workflows'] as $collection) {
            foreach ($manifest[$collection] ?? [] as $i => $node) {
                if (is_array($node)) {
                    $manifest[$collection][$i] = $this->node($node, null);
                }
            }
        }

        if (is_array($manifest['permissions'] ?? null)) {
            $manifest['permissions'] = $this->node($manifest['permissions'], null);
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function index(array $manifest): void
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if (! is_array($object)) {
                continue;
            }
            $id = (string) ($object['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $this->objectIds[$id] = true;
            $slug = (string) ($object['slug'] ?? '');
            if ($slug !== '' && ! isset($this->objectsBySlug[$slug])) {
                $this->objectsBySlug[$slug] = $id;
            }

            $this->fieldsByObject[$id] = [];
            foreach ($object['fields'] ?? [] as $field) {
                if (! is_array($field)) {
                    continue;
                }
                $fid = (string) ($field['id'] ?? '');
                if ($fid === '') {
                    continue;
                }
                $this->fieldIds[$fid] = true;
                $fslug = (string) ($field['slug'] ?? '');
                if ($fslug !== '' && ! isset($this->fieldsByObject[$id][$fslug])) {
                    $this->fieldsByObject[$id][$fslug] = $fid;
                }
                if (($field['type'] ?? '') === 'relation' && is_string($field['target_object_id'] ?? null)) {
                    $this->relationTargets[$fid] = $field['target_object_id'];
                }
            }
        }

        foreach ($manifest['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }
            $id = (string) ($page['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $this->pageIds[$id] = true;
            $slug = (string) ($page['slug'] ?? '');
            if ($slug !== '' && ! isset($this->pagesBySlug[$slug])) {
                $this->pagesBySlug[$slug] = $id;
            }
        }

        foreach ($manifest['permissions']['roles'] ?? [] as $role) {
            if (! is_array($role)) {
                continue;
            }
            $id = (string) ($role['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $this->roleIds[$id] = true;
            $slug = (string) ($role['slug'] ?? '');
            if ($slug !== '' && ! isset($this->rolesBySlug[$slug])) {
                $this->rolesBySlug[$slug] = $id;
            }
        }
    }

    /**
     * Rewrite one node and everything under it.
     *
     * Generic on every key rather than a list of the places references live —
     * that list is exactly what goes stale, and a reference the walk misses is a
     * rejection the author still has to debug by hand.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function node(array $node, ?string $scope): array
    {
        // An `object_id` on this node re-scopes everything beneath it, so it is
        // resolved BEFORE its siblings are read against that scope.
        foreach (['object_id', 'target_object_id'] as $key) {
            if (is_string($node[$key] ?? null)) {
                $node[$key] = $this->object($node[$key]);
            }
        }
        if (is_array($node['data_source'] ?? null) && is_string($node['data_source']['object_id'] ?? null)) {
            $node['data_source']['object_id'] = $this->object($node['data_source']['object_id']);
        }

        $scope = $this->scopeOf($node, $scope);

        foreach ($node as $key => $value) {
            if ($key === 'page_id' && is_string($value)) {
                $node[$key] = $this->lookup($value, $this->pageIds, $this->pagesBySlug);

                continue;
            }
            if ($key === 'role_id' && is_string($value)) {
                $node[$key] = $this->lookup($value, $this->roleIds, $this->rolesBySlug);

                continue;
            }
            if (is_string($value) && $key !== 'object_id' && $key !== 'target_object_id' && str_contains($key, 'field_id')) {
                $node[$key] = $this->field($value, $this->scopeForFieldKey($key, $node, $scope));

                continue;
            }
            if (in_array($key, self::FIELD_ID_LISTS, true) && is_array($value)) {
                $node[$key] = array_map(
                    fn ($v) => is_string($v) ? $this->field($v, $scope) : $v,
                    $value,
                );

                continue;
            }
            if (is_array($value)) {
                $node[$key] = $this->node($value, $scope);
            }
        }

        return $node;
    }

    /**
     * The object a node's field references are read against.
     *
     * @param  array<string, mixed>  $node
     */
    private function scopeOf(array $node, ?string $inherited): ?string
    {
        foreach ([$node['object_id'] ?? null, $node['data_source']['object_id'] ?? null] as $candidate) {
            if (is_string($candidate) && isset($this->objectIds[$candidate])) {
                return $candidate;
            }
        }

        return $inherited;
    }

    /**
     * A rollup/lookup's `target_field_id` belongs to the RELATED object, not the
     * one holding the field — the one place where the enclosing scope is the
     * wrong answer, and the validator says so in as many words ("does not belong
     * to the related object").
     *
     * @param  array<string, mixed>  $node
     */
    private function scopeForFieldKey(string $key, array $node, ?string $scope): ?string
    {
        if ($key !== 'target_field_id' && $key !== 'inverse_field_id') {
            return $scope;
        }

        if ($key === 'inverse_field_id' && is_string($node['target_object_id'] ?? null)) {
            return $node['target_object_id'];
        }

        $via = $node['via_relation_field_id'] ?? null;
        if (is_string($via)) {
            // The relation may itself still be written as a slug here.
            $viaId = $this->field($via, $scope);

            return $this->relationTargets[$viaId] ?? $scope;
        }

        return $scope;
    }

    private function object(string $value): string
    {
        return $this->lookup($value, $this->objectIds, $this->objectsBySlug);
    }

    private function field(string $value, ?string $scope): string
    {
        if (isset($this->fieldIds[$value]) || $scope === null) {
            return $value;
        }

        return $this->fieldsByObject[$scope][$value] ?? $value;
    }

    /**
     * @param  array<string, true>  $ids
     * @param  array<string, string>  $bySlug
     */
    private function lookup(string $value, array $ids, array $bySlug): string
    {
        return isset($ids[$value]) ? $value : ($bySlug[$value] ?? $value);
    }
}
