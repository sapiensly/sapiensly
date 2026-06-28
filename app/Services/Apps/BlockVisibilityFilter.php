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
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    public function visibleBlocks(array $blocks, AppAccessContext $access, array $context): array
    {
        $kept = [];

        foreach ($blocks as $block) {
            if (! $access->isBlockVisible($block['visibility'] ?? null)) {
                continue;
            }
            if (! $this->passesExpression($block['visibility'] ?? null, $context)) {
                continue;
            }

            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    $block[$key] = $this->visibleBlocks($block[$key], $access, $context);
                }
            }
            foreach (['tabs', 'sections'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    $block[$key] = array_map(function (array $child) use ($access, $context): array {
                        $child['blocks'] = $this->visibleBlocks($child['blocks'] ?? [], $access, $context);

                        return $child;
                    }, $block[$key]);
                }
            }

            $kept[] = $block;
        }

        return $kept;
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
