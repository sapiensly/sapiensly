<?php

namespace App\Services\Apps;

use App\Services\Records\ExpressionResolver;

/**
 * Drops blocks a user must not see before their data is resolved — by the role
 * (`visibility.roles`) and/or the `visibility.expression` evaluated against the
 * runtime context (so a block can render only when e.g. {{params.order}} is set).
 * Shared by the page runtime and the action endpoint so a hidden block's data
 * never reaches the wire from either surface.
 */
class BlockVisibilityFilter
{
    public function __construct(private ExpressionResolver $expressions) {}

    /**
     * Recursively keep only visible blocks, descending into every layout
     * container (container/modal, split_view, tabs, accordion).
     *
     * Pass `$objects` (the manifest's objects) to also drop, from every form on
     * the page, the inputs for fields this role may not read — see
     * {@see stripHiddenFormFields()}.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $objects
     * @return list<array<string, mixed>>
     */
    public function visibleBlocks(array $blocks, AppAccessContext $access, array $context, array $objects = []): array
    {
        $kept = [];

        foreach ($blocks as $block) {
            if (! $access->isBlockVisible($block['visibility'] ?? null)) {
                continue;
            }
            if (! $this->passesExpression($block['visibility'] ?? null, $context)) {
                continue;
            }

            $block = $this->stripHiddenFormFields($block, $access, $objects);

            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    $block[$key] = $this->visibleBlocks($block[$key], $access, $context, $objects);
                }
            }
            foreach (['tabs', 'sections'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    $block[$key] = array_map(function (array $child) use ($access, $context, $objects): array {
                        $child['blocks'] = $this->visibleBlocks($child['blocks'] ?? [], $access, $context, $objects);

                        return $child;
                    }, $block[$key]);
                }
            }

            $kept[] = $block;
        }

        return $kept;
    }

    /**
     * Drop, from a form, the inputs for fields the role may not READ.
     *
     * A row's hidden fields are already stripped on the way out, but nothing
     * stopped a form from rendering an input for one — so the field's existence
     * and type leaked, and on an edit form the input rendered EMPTY (its value
     * having been withheld) and would have written that emptiness back over a
     * value the user was never allowed to see.
     *
     * Read-hidden is the right test rather than write-readonly: those are two
     * independent lists on a policy, and a field you cannot read is one you
     * cannot meaningfully fill in. Writing a readonly field is refused by the
     * action executor, which is a different guarantee and stays where it is.
     *
     * @param  array<string, mixed>  $block
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, mixed>
     */
    private function stripHiddenFormFields(array $block, AppAccessContext $access, array $objects): array
    {
        $type = $block['type'] ?? null;
        if (! in_array($type, ['form', 'multi_step_form'], true) || $objects === []) {
            return $block;
        }

        $objectId = $block['object_id'] ?? null;
        if (! is_string($objectId)) {
            return $block;
        }

        $hidden = $access->hiddenFieldSlugs($objectId);
        if ($hidden === []) {
            return $block;
        }

        $object = null;
        foreach ($objects as $candidate) {
            if (($candidate['id'] ?? null) === $objectId) {
                $object = $candidate;
                break;
            }
        }
        if ($object === null) {
            return $block;
        }

        $hiddenIds = [];
        foreach ($object['fields'] ?? [] as $field) {
            if (in_array($field['slug'] ?? null, $hidden, true) && isset($field['id'])) {
                $hiddenIds[] = $field['id'];
            }
        }
        if ($hiddenIds === []) {
            return $block;
        }

        $keep = fn (array $entries): array => array_values(array_filter(
            $entries,
            fn (array $f): bool => ! in_array($f['field_id'] ?? null, $hiddenIds, true),
        ));

        // A form with no explicit `fields` renders every field of the object, so
        // it has to be made explicit here before the hidden ones can be removed.
        if ($type === 'form') {
            $entries = $block['fields'] ?? array_map(
                fn (array $f): array => ['field_id' => $f['id']],
                $object['fields'] ?? [],
            );
            $block['fields'] = $keep($entries);

            return $block;
        }

        $block['steps'] = array_map(function (array $step) use ($keep): array {
            $step['fields'] = $keep($step['fields'] ?? []);

            return $step;
        }, $block['steps'] ?? []);

        return $block;
    }

    /**
     * Evaluate a visibility rule's optional `expression`. No expression ⇒ visible;
     * a truthy result keeps the block, null/false/empty hides it.
     *
     * @param  array<string, mixed>|null  $rule
     * @param  array<string, mixed>  $context
     */
    private function passesExpression(?array $rule, array $context): bool
    {
        $expression = $rule['expression'] ?? null;
        if (! is_string($expression) || trim($expression) === '') {
            return true;
        }

        $value = $this->expressions->resolve($expression, $context);

        return ! in_array($value, [null, false, '', 0, '0'], true);
    }
}
