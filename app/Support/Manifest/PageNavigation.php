<?php

namespace App\Support\Manifest;

/**
 * The primary-navigation rule for an app's pages, in one place so the runtime
 * chrome and the builder preview agree.
 *
 * A page is a menu item only if it is *directly addressable* — reachable with no
 * record in context. A record-scoped "detail" page — one whose content is a
 * record_detail bound to a route param like {{params.id}} — cannot render without
 * a specific record, so it is reached by drilling in from its parent collection
 * page, never surfaced in the menu. An explicit `nav: false` also hides a page.
 *
 * This is a general UI rule, not an app-specific fix: it is the same distinction
 * REST draws between an `index` route (menu-worthy) and a `show` route (needs an
 * `:id`), and the master–detail navigation pattern (the list is the entry point;
 * the detail is a child view).
 */
class PageNavigation
{
    /** Blocks that render an object's records as a browsable collection (a "list"). */
    private const COLLECTION_BLOCKS = ['table', 'kanban', 'gantt', 'card_grid', 'data_grid', 'board', 'calendar'];

    /**
     * @param  array<string, mixed>  $page
     */
    public static function isNavigable(array $page): bool
    {
        if (($page['nav'] ?? true) === false) {
            return false;
        }

        return ! self::isRecordScoped($page['blocks'] ?? []);
    }

    /**
     * The slug of the menu item a page should activate. A navigable page activates
     * itself; a record-scoped detail page activates its PARENT — the navigable
     * page that lists the same object (Categorías > Categoría highlights
     * "Categorías") — so drilling into a detail keeps its collection lit in the
     * menu, the way a breadcrumb keeps the trail. Falls back to the page's own
     * slug when no parent list is found.
     *
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $pages  all of the app's pages
     */
    public static function activeSlug(array $page, array $pages): string
    {
        $ownSlug = (string) ($page['slug'] ?? '');

        if (self::isNavigable($page)) {
            return $ownSlug;
        }

        $objectId = self::detailObjectId($page['blocks'] ?? []);
        if ($objectId === null) {
            return $ownSlug;
        }

        foreach ($pages as $candidate) {
            if (($candidate['slug'] ?? null) === $ownSlug || ! self::isNavigable($candidate)) {
                continue;
            }
            if (self::listsObject($candidate['blocks'] ?? [], $objectId)) {
                return (string) ($candidate['slug'] ?? $ownSlug);
            }
        }

        return $ownSlug;
    }

    /**
     * A page is record-scoped when it shows a single record chosen by a route
     * param — a record_detail whose record_id_expression reads {{params.…}}. That
     * is the canonical "show page": it needs a record to render, so it belongs
     * behind a drill-in, not in the menu.
     *
     * Note this deliberately keys on record_detail, NOT on any {{params.…}} usage:
     * a filterable LIST page that reads {{params.status}} in a data filter has no
     * record_detail and stays navigable.
     *
     * @param  list<mixed>  $blocks
     */
    private static function isRecordScoped(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'record_detail'
                && self::readsParam($block['record_id_expression'] ?? null)) {
                return true;
            }

            // Containers (modals, tabs, …) nest their own blocks.
            if (is_array($block['blocks'] ?? null) && self::isRecordScoped($block['blocks'])) {
                return true;
            }
        }

        return false;
    }

    private static function readsParam(mixed $expression): bool
    {
        return is_string($expression) && str_contains($expression, 'params');
    }

    /**
     * The object a detail page is about — the object_id of its param-keyed
     * record_detail block (the same block isRecordScoped keys on), recursing
     * containers.
     *
     * @param  list<mixed>  $blocks
     */
    private static function detailObjectId(array $blocks): ?string
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'record_detail'
                && self::readsParam($block['record_id_expression'] ?? null)
                && isset($block['object_id'])) {
                return (string) $block['object_id'];
            }

            if (is_array($block['blocks'] ?? null)) {
                $found = self::detailObjectId($block['blocks']);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Whether a page presents the given object as a browsable collection — a
     * table/kanban/… whose data_source targets it. Aggregation blocks (a
     * dashboard's charts/metrics over the same object) are deliberately excluded,
     * so a detail page's parent is its LIST, not the dashboard.
     *
     * @param  list<mixed>  $blocks
     */
    private static function listsObject(array $blocks, string $objectId): bool
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (in_array($block['type'] ?? null, self::COLLECTION_BLOCKS, true)
                && ($block['data_source']['object_id'] ?? null) === $objectId) {
                return true;
            }

            if (is_array($block['blocks'] ?? null) && self::listsObject($block['blocks'], $objectId)) {
                return true;
            }
        }

        return false;
    }
}
