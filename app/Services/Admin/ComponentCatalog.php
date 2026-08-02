<?php

namespace App\Services\Admin;

use App\Ai\Tools\Builder\ListAvailableComponentsTool;
use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Mcp\Tools\Chatbots\BotFlowReferenceTool;
use Illuminate\Support\Str;

/**
 * What each module can be built out of, read from the places that decide it.
 *
 * Nothing here is a list of components. A block type already has to be declared
 * in six places that agree — the manifest schema being the one that says
 * whether a manifest is legal — and BlockRegistryParityTest exists because
 * missing one fails in ways tests never see. A hand-written catalogue on an
 * admin page would be the seventh, and the first to go stale.
 *
 * So: the schema says which app blocks exist, the dashboard planner says which
 * of them a dashboard may use, and the bot-flow reference says what a
 * conversation is made of. This class only joins and describes them.
 */
class ComponentCatalog
{
    /**
     * Rough families, for filtering. Deliberately a fallback rather than a
     * requirement: a type this does not recognise is still listed, under
     * "other", so adding a block type can never make it disappear from here.
     *
     * @var array<string, list<string>>
     */
    private const FAMILIES = [
        'structure' => ['container', 'tabs', 'accordion', 'split_view', 'spacer', 'divider', 'card', 'section'],
        'content' => ['text', 'heading', 'markdown', 'image', 'html', 'video', 'icon', 'badge', 'quote'],
        'data' => ['table', 'data_grid', 'record_detail', 'related_list', 'kanban', 'calendar', 'timeline', 'gantt', 'card_grid', 'list', 'map'],
        'insight' => ['chart', 'stat', 'metric_grid', 'gauge', 'progress', 'sparkline', 'heatmap', 'pivot', 'word_cloud', 'insight'],
        'input' => ['form', 'multi_step_form', 'filter_bar', 'button', 'modal', 'lead_form', 'search'],
        'marketing' => ['hero', 'feature_grid', 'cta', 'testimonials', 'pricing', 'faq', 'stat_band', 'logo_cloud'],
    ];

    /**
     * The three modules, each with its own components.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function all(): array
    {
        $described = $this->appDescriptions();
        $schemaTypes = $this->schemaBlockTypes();
        $dashboardTypes = $this->dashboardBlockTypes();

        $apps = [];
        foreach ($schemaTypes as $type) {
            $apps[] = $this->entry($type, $described[$type] ?? null, $type);
        }

        $dashboards = [];
        foreach ($dashboardTypes as $type) {
            // A planner block the schema has never heard of would be rejected
            // at save time; showing it here would be advertising a dead end.
            if (! in_array($type, $schemaTypes, true)) {
                continue;
            }
            $dashboards[] = $this->entry($type, $described[$type] ?? null, $type);
        }

        return [
            'chat' => $this->chatNodes(),
            'apps' => $apps,
            'dashboards' => $dashboards,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $type, ?string $description, string $key): array
    {
        return [
            'type' => $type,
            'name' => Str::headline($type),
            'description' => $description ?? '',
            'family' => $this->familyOf($key),
            // Said plainly because it is the first question an author has: does
            // this block need records behind it, or can it stand on its own?
            'needs_data' => $description !== null && (
                str_contains($description, 'data_source')
                || str_contains($description, 'object_id')
                || str_contains($description, 'query')
            ),
        ];
    }

    private function familyOf(string $type): string
    {
        foreach (self::FAMILIES as $family => $types) {
            if (in_array($type, $types, true)) {
                return $family;
            }
        }

        return 'other';
    }

    /**
     * Every block type the manifest schema accepts — the authority on what the
     * runtime can be asked to render.
     *
     * @return list<string>
     */
    private function schemaBlockTypes(): array
    {
        $schema = json_decode(
            (string) file_get_contents(base_path('resources/schemas/app-manifest/v1.json')),
            true,
        );

        $types = [];
        foreach ($schema['$defs']['block']['oneOf'] ?? [] as $branch) {
            $def = $schema['$defs'][str_replace('#/$defs/', '', (string) $branch['$ref'])] ?? [];
            foreach ($def['allOf'] ?? [] as $part) {
                $const = $part['properties']['type']['const'] ?? null;
                if (is_string($const)) {
                    $types[] = $const;
                }
            }
        }

        sort($types);

        return array_values(array_unique($types));
    }

    /**
     * The authoring descriptions the builder model is given, keyed by type — so
     * the page shows an author exactly what the model was told, rather than a
     * second telling of it that can disagree.
     *
     * @return array<string, string>
     */
    private function appDescriptions(): array
    {
        $out = [];
        foreach (ListAvailableComponentsTool::catalog() as $component) {
            $out[(string) $component['type']] = (string) ($component['description'] ?? '');
        }

        return $out;
    }

    /**
     * What the dashboard planner will accept in a plan.
     *
     * @return list<string>
     */
    private function dashboardBlockTypes(): array
    {
        $known = (new \ReflectionClass(PlanDashboardTool::class))->getConstant('KNOWN_BLOCKS');

        return is_array($known) ? array_values(array_map('strval', $known)) : [];
    }

    /**
     * The node types a conversation flow is built from.
     *
     * @return list<array<string, mixed>>
     */
    private function chatNodes(): array
    {
        $nodes = BotFlowReferenceTool::nodeTypes();

        $out = [];
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (! is_array($node) || ! isset($node['type'])) {
                continue;
            }
            $out[] = [
                'type' => (string) $node['type'],
                'name' => Str::headline((string) $node['type']),
                'description' => (string) ($node['description'] ?? $node['purpose'] ?? ''),
                'family' => 'flow',
                'needs_data' => is_array($node['fields'] ?? null)
                    && collect(array_keys($node['fields']))->contains(
                        fn (string $f): bool => str_contains($f, 'object') || str_contains($f, 'query'),
                    ),
            ];
        }

        return $out;
    }
}
