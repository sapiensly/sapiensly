<?php

namespace App\Services\Landing;

/**
 * The block allowlist for the PUBLIC (unauthenticated) landing surface. The
 * public page binds the tenant scope to the app's OWNER so authored CSS/markup
 * resolve — which means any data-backed block (table, chart, stat, form…) would
 * read the owner's tenant records and serve them to anonymous visitors. So the
 * public surface fails closed: only presentational block types render, anything
 * else is dropped server-side (never reaches the wire), and a block carrying a
 * `visibility` rule is dropped too (role rules are meaningless for a guest —
 * assume it was meant to be hidden). The lead-capture form arrives as its own
 * first-class public mechanism, not via this list.
 */
final class PublicLandingBlocks
{
    /**
     * Purely presentational types — no data_source, no record reads, no actions
     * that touch tenant data. Container-likes recurse; their children are
     * filtered by the same rule.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'html', 'container', 'text', 'heading', 'divider', 'spacer', 'markdown',
        'image', 'hero', 'feature_grid', 'cta', 'stat_band', 'testimonials',
        'faq', 'pricing', 'carousel', 'alert', 'avatar', 'badge', 'breadcrumb',
        'stepper', 'flow', 'tabs', 'accordion', 'split_view',
        // The one write path a guest gets: declarative lead capture. The public
        // endpoint only accepts the fields this block declares, so allowing it
        // exposes no reads and no arbitrary writes.
        'lead_form',
    ];

    /**
     * Filter a page's blocks down to the public-safe set, recursively.
     *
     * @param  array<int, mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function filter(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if (! in_array($type, self::ALLOWED, true)) {
                continue;
            }
            // Fail closed on visibility rules: they're authored against app
            // roles/expressions a guest doesn't have.
            if (isset($block['visibility'])) {
                continue;
            }

            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    $block[$key] = self::filter($block[$key]);
                }
            }
            foreach (['tabs', 'sections'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    foreach ($block[$key] as $i => $sub) {
                        if (is_array($sub) && isset($sub['blocks']) && is_array($sub['blocks'])) {
                            $block[$key][$i]['blocks'] = self::filter($sub['blocks']);
                        }
                    }
                }
            }

            // Hero/cta/pricing CTAs carry on_click action sequences (navigate,
            // open_modal, create_record…). A guest has no action endpoint, so
            // strip anything beyond plain rendering: keep the labels, drop the
            // sequences that could reference tenant writes.
            $out[] = self::stripActions($block);
        }

        return $out;
    }

    /**
     * Remove action sequences from a block, recursively — public CTAs render as
     * anchors the authored markup/custom_css style; app-style actions belong to
     * the authenticated runtime (the public lead form is slice 4's own path).
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function stripActions(array $block): array
    {
        unset($block['on_click'], $block['on_submit']);
        foreach (['cta', 'button'] as $key) {
            if (isset($block[$key]) && is_array($block[$key])) {
                unset($block[$key]['on_click']);
            }
        }
        if (isset($block['tiers']) && is_array($block['tiers'])) {
            foreach ($block['tiers'] as $i => $tier) {
                if (is_array($tier) && isset($tier['cta']) && is_array($tier['cta'])) {
                    unset($block['tiers'][$i]['cta']['on_click']);
                }
            }
        }

        return $block;
    }
}
