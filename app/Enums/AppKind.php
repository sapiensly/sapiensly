<?php

namespace App\Enums;

/**
 * Product classification for an app: a regular App (data entry / CRUD / a
 * website), a Dashboard (analytics — charts, KPIs, insights), or a Landing (a
 * public, chrome-less marketing page). App/Dashboard are derived from the
 * manifest's content at version-write time; Landing is declared explicitly via
 * `settings.surface` (a landing and a dashboard can share a hero, so intent
 * can't be inferred from blocks alone).
 */
enum AppKind: string
{
    case App = 'app';
    case Dashboard = 'dashboard';
    case Landing = 'landing';

    public function label(): string
    {
        return match ($this) {
            self::App => __('App'),
            self::Dashboard => __('Dashboard'),
            self::Landing => __('Landing'),
        };
    }

    /**
     * Classify a manifest from its content: a dashboard is analytical (charts /
     * KPI cards / insights) with NO data-entry (forms or editable grids).
     * Anything that has CRUD, or has no analytical blocks at all (an empty app,
     * a website), is an App.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function classify(array $manifest): self
    {
        // An explicit surface declaration wins over content inference — a landing
        // and a dashboard can share a hero, so intent is declared, not guessed.
        $surface = $manifest['settings']['surface'] ?? null;
        if (is_string($surface) && ($explicit = self::tryFrom($surface)) !== null) {
            return $explicit;
        }

        $types = [];
        self::collectBlockTypes($manifest['pages'] ?? [], $types);

        // Data-entry blocks make it an app regardless of any charts alongside.
        foreach (['form', 'multi_step_form', 'data_grid'] as $crud) {
            if (isset($types[$crud])) {
                return self::App;
            }
        }

        // Otherwise, any strongly-analytical block marks it a dashboard.
        foreach (['chart', 'pivot', 'metric_grid', 'insight', 'stat', 'gauge', 'progress', 'sparkline', 'funnel', 'heatmap', 'word_cloud'] as $analytical) {
            if (isset($types[$analytical])) {
                return self::Dashboard;
            }
        }

        return self::App;
    }

    /**
     * Recursively collect every block `type` present under a list of pages or
     * blocks, descending through layout containers (container/modal/split_view/
     * tabs/accordion) so a nested chart still counts.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<string, true>  $out
     */
    private static function collectBlockTypes(array $nodes, array &$out): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (isset($node['type']) && is_string($node['type'])) {
                $out[$node['type']] = true;
            }
            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) {
                    self::collectBlockTypes($node[$key], $out);
                }
            }
            foreach (['tabs', 'sections'] as $key) {
                foreach ($node[$key] ?? [] as $sub) {
                    if (is_array($sub) && isset($sub['blocks']) && is_array($sub['blocks'])) {
                        self::collectBlockTypes($sub['blocks'], $out);
                    }
                }
            }
        }
    }
}
