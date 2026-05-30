<?php

namespace App\Ai\Tools\Builder;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Catalog of UI block types Claude is allowed to put in a page. Hard-coded so
 * the model cannot invent unsupported types — the runtime would refuse to
 * render them anyway, this gives Claude the same context up-front.
 */
class ListAvailableComponentsTool implements Tool
{
    public function name(): string
    {
        return 'list_available_components';
    }

    public function description(): string
    {
        return 'List the UI block types you may use inside page.blocks. The runtime can only render these types — any other type will fail validation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $catalog = [
            ['type' => 'container', 'description' => 'Layout group. Has direction (row|column), gap, and nested blocks.'],
            ['type' => 'text', 'description' => 'Paragraph of body text. Has content (string) and size (xs|sm|base|lg|xl).'],
            ['type' => 'heading', 'description' => 'Section heading. Has content and level (1-6).'],
            ['type' => 'divider', 'description' => 'Horizontal rule, no props.'],
            ['type' => 'spacer', 'description' => 'Vertical gap. Has size (sm|md|lg|xl).'],
            ['type' => 'table', 'description' => 'Tabular list of records. Requires data_source ({object_id, filter?, sort?}) and columns[]. Each column is EITHER a data column `{id, field_id, label_override?, width?}` OR an action column `{id, type:"action", label, icon?, variant? (primary|secondary|danger|ghost), confirm? {title, message}, on_click: [actions]}`. Inside on_click you can reference the clicked row via {{row.id}} and {{row.data.<slug>}} — e.g. an "update_record" action with record_id_expression="{{row.id}}" and values={completada:true} toggles a checkbox per row. The `icon` prop accepts either a single emoji (✓, ✏️, 🗑️) OR one of these Lucide names: check, pencil, edit, trash, x, close, plus, add, refresh, send. Any other string renders as plain text next to the label. Use action columns for inline Mark / Edit / Delete buttons; do NOT try to use formula fields or extra buttons outside the table.'],
            ['type' => 'stat', 'description' => 'Single numeric KPI. Requires label, query ({object_id, filter?}), aggregation (count|sum|avg|min|max). field_id is required for sum/avg/min/max.'],
            ['type' => 'form', 'description' => 'User-editable form. Requires object_id and mode (create|edit). Optional: fields ([{field_id, label_override?, default_expression?, readonly_expression?, visible_if?, required_if?}]), submit_label, on_submit (action sequence), on_cancel. mode=edit also requires record_id_expression. Per-field options: `default_expression` pre-fills the initial value (resolved server-side; use {{current_user.id}}, {{today()}}, {{now()}}). `readonly_expression` resolves to a boolean that disables the field. `visible_if` / `required_if` are STRUCTURED conditions {field_id, op, value} evaluated live against another field of THIS form — op ∈ eq|neq|gt|gte|lt|lte|in|not_in|contains|is_null|is_not_null|is_truthy|is_falsy (omit value for is_* ops, array for in/not_in). Example: show "motivo" only when estado == "rechazado" → visible_if {field_id:"<estado fld id>", op:"eq", value:"rechazado"}. Hidden fields are not submitted; required_if is enforced before submit.'],
            ['type' => 'button', 'description' => 'Clickable button that fires on_click (action sequence). Requires label and on_click. Optional: variant (primary|secondary|danger|ghost), size, icon, confirm ({title, message}).'],
            ['type' => 'modal', 'description' => 'Dialog opened/closed by open_modal/close_modal actions. Props: title (string), description? (string — sub-title that also satisfies the a11y aria-describedby; if omitted, a sr-only fallback is rendered), size (sm|md|lg), blocks (nested, typically a form). Reference its id from a button via open_modal.'],
            ['type' => 'chart', 'description' => 'Visualisation of aggregated records. Requires chart_type (bar=vertical columns, hbar=horizontal bars/progress style, line, area, pie, donut), data_source (query block), aggregation (count|sum|avg|min|max). For sum/avg/min/max also pass y_field_id (numeric). Use group_by_field_id (typically single_select) to split the series; without it the whole result becomes one bucket. Tip: hbar is better when labels are long; bar is better when comparing few categories at a glance.'],
            ['type' => 'kanban', 'description' => 'Read-only kanban board. Requires data_source, group_by_field_id (must be single_select — its options become the columns) and card_title_field_id. Optional: card_meta_fields ([{field_id}]) for secondary lines on each card. No drag-and-drop yet.'],
            ['type' => 'calendar', 'description' => 'Month calendar of records. Requires data_source, date_field_id (date|datetime field — drives when each event lands) and title_field_id. Optional: color_field_id (single_select) — each event picks up the option color as its accent.'],
            ['type' => 'markdown', 'description' => 'Static rich text rendered from a markdown string. Just one prop: content. Good for intros, instructions, FAQs, hero copy. Supports headings, lists, bold/italic, links, code blocks, tables and blockquotes.'],
            ['type' => 'image', 'description' => 'Static image from a URL. Props: src (required), alt, fit (contain|cover|fill), rounded, max_height (px). Use for logos, hero shots, screenshots. NOT for record attachments yet — that needs the file_upload block (not implemented in MVP).'],
            ['type' => 'metric_grid', 'description' => 'Several stats laid out in a responsive grid. Props: columns (1-6, default 3) and items[] where each item has {id, label, query, aggregation, field_id?, format?} — same shape as a stat block. Prefer this over multiple stat blocks side by side.'],
            ['type' => 'sparkline', 'description' => 'Compact line showing a trend without axis labels. Requires data_source. Optional: x_field_id (typically a date, used to bucket+sort), y_field_id (numeric, defaults to count(*)), aggregation (default count), color (hex), label.'],
            ['type' => 'gauge', 'description' => 'Half-circle gauge showing one aggregate against a max_value (required). Props: label, query, aggregation (count|sum|avg|min|max), field_id (required for non-count), max_value (number — the 100% mark), format (number|currency|percentage), color (hex). Use for goals/quotas.'],
            ['type' => 'heatmap', 'description' => 'GitHub-style activity heatmap. Requires data_source and date_field_id. Optional: weeks (4-104, default 26), color (hex). Each cell is one day; intensity is the row count for that day.'],
            ['type' => 'timeline', 'description' => 'Vertical timeline sorted by date (newest first). Requires data_source, date_field_id (date|datetime) and title_field_id. Optional: description_field_id (long_text|string), color_field_id (single_select). Good for activity logs, notes, comms history.'],
            ['type' => 'funnel', 'description' => 'Conversion funnel — each stage is its own aggregate query (likely the same object with a different filter). Props: label, stages[] where each stage has {id, label, query, aggregation (count|sum|avg), field_id?, color?}. The first stage is the 100% baseline; later stages show conversion %.'],
            ['type' => 'map', 'description' => 'Interactive map (maplibre + free OpenFreeMap tiles, no API key). Requires data_source, lat_field_id and lng_field_id (both number fields). Optional: popup_field_id (text shown on marker click), color_field_id (single_select), height_px (200-800, default 400). The map auto-fits to the markers.'],
            ['type' => 'tabs', 'description' => 'Tabbed panel. Each tab has its own nested blocks array. Props: tabs[] ([{id, label, icon?, blocks[]}]), default_tab_id?. Use to organise a busy page (e.g. tabs for Overview / Records / Settings).'],
            ['type' => 'accordion', 'description' => 'Collapsible sections. Props: sections[] ([{id, title, default_open?, blocks[]}]), allow_multiple? (default false — opening one closes the others). Use for FAQs, advanced settings, optional details.'],
            ['type' => 'split_view', 'description' => 'Two-column layout. Props: left_blocks[], right_blocks[], left_fraction? (1-11, default 4 → 1/3 left, 2/3 right). Use for master-detail layouts (e.g. left a table, right a summary). MVP is static — no row-click linking between sides yet.'],
            ['type' => 'card_grid', 'description' => 'Records rendered as cards in a responsive grid (alternative to table when visual scan beats density). Requires data_source and title_field_id. Optional: columns (1-6, default 3), subtitle_field_id, image_field_id (string field with a URL), meta_fields[].'],
            ['type' => 'multi_step_form', 'description' => 'Wizard form that splits fields across N ordered steps. Requires object_id, mode (create|edit), and steps[] where each step has {id, title, description?, fields[{field_id, label_override?, default_expression?, readonly_expression?, visible_if?, required_if?}]} (same per-field options as the `form` block). Optional: show_progress (default true) for the numbered indicator at the top, submit_label, cancel_label, on_submit (action sequence), on_cancel. mode=edit also requires record_id_expression. Use this instead of `form` when you have 8+ fields or a logical grouping (Personal info → Address → Preferences). Required fields are validated locally before advancing; the backend re-checks everything on submit. Each field_id can appear in at most one step.'],
        ];

        return json_encode(['components' => $catalog], JSON_THROW_ON_ERROR);
    }
}
