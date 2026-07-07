export type FieldType =
    | 'string'
    | 'long_text'
    | 'number'
    | 'currency'
    | 'boolean'
    | 'date'
    | 'datetime'
    | 'single_select'
    | 'multi_select'
    | 'relation'
    | 'rating'
    | 'slider'
    | 'date_range'
    | 'file'
    | 'rich_text'
    | 'color';

export interface FieldDef {
    id: string;
    slug: string;
    name: string;
    type: FieldType;
    currency_code?: string;
    options?: Array<{
        id: string;
        value: string;
        label: string;
        color?: string;
    }>;
    // Optional type-specific config the manifest may carry; read by the runtime
    // inputs/renderers (so they don't need inline casts in templates).
    format?: string;
    display?: string;
    icon?: string;
    min?: number;
    max?: number;
    step?: number;
    include_time?: boolean;
    max_size_mb?: number;
    mime_types?: string[];
}

export interface ObjectDef {
    id: string;
    slug: string;
    name: string;
    name_plural?: string;
    primary_display_field_id?: string;
    fields: FieldDef[];
}

/**
 * System fields are virtual datetime fields exposed on every object. The
 * backend resolver injects `sys_created_at` / `sys_updated_at` into every
 * row's `data`, and field-id lookups across visual blocks fall through to
 * this map when the id starts with `sys_`.
 */
export const SYSTEM_FIELDS: Record<string, FieldDef> = {
    sys_created_at: {
        id: 'sys_created_at',
        slug: 'sys_created_at',
        name: 'Created at',
        type: 'datetime',
    },
    sys_updated_at: {
        id: 'sys_updated_at',
        slug: 'sys_updated_at',
        name: 'Updated at',
        type: 'datetime',
    },
};

/**
 * Resolve a field id against an object's declared fields OR the system-fields
 * map. Returns undefined when neither matches.
 */
export function resolveField(
    object: ObjectDef | undefined,
    fieldId: string | undefined,
): FieldDef | undefined {
    if (!fieldId) return undefined;
    if (fieldId in SYSTEM_FIELDS) return SYSTEM_FIELDS[fieldId];
    return object?.fields.find((f) => f.id === fieldId);
}

export interface BlockBase {
    id: string;
    type: string;
    style?: {
        padding?: string;
        margin?: string;
        col_span?: number;
        background?: string;
        color?: string;
        max_width?: string;
        full_bleed?: boolean;
        gradient?: { from: string; to: string; direction?: string };
    };
}

export interface BlockContainer extends BlockBase {
    type: 'container';
    direction?: 'row' | 'column' | 'masonry';
    gap?: 'none' | 'sm' | 'md' | 'lg';
    blocks: AnyBlock[];
}

export interface BlockText extends BlockBase {
    type: 'text';
    content: string;
    size?: 'xs' | 'sm' | 'base' | 'lg' | 'xl';
}

export interface BlockHeading extends BlockBase {
    type: 'heading';
    content: string;
    level?: 1 | 2 | 3 | 4 | 5 | 6;
    size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'display';
}

export interface BlockDivider extends BlockBase {
    type: 'divider';
}

export interface BlockSpacer extends BlockBase {
    type: 'spacer';
    size?: 'sm' | 'md' | 'lg' | 'xl';
}

export interface BlockTable extends BlockBase {
    type: 'table';
    data_source: { object_id: string };
    columns: Array<{
        id: string;
        field_id: string;
        label_override?: string;
        width?: number;
    }>;
    empty_state_message?: string;
}

/** Optional inline trend line for a KPI card (stat / metric_grid item). */
export interface SparkSpec {
    data_source: { object_id: string };
    x_field_id?: string;
    y_field_id?: string;
    aggregation?: 'count' | 'sum' | 'avg' | 'min' | 'max';
    color?: string;
}

export interface BlockStat extends BlockBase {
    type: 'stat';
    label: string;
    query: { object_id: string };
    aggregation: 'count' | 'sum' | 'avg' | 'min' | 'max';
    field_id?: string;
    format?: 'number' | 'currency' | 'percentage' | 'duration';
    icon?: string;
    delta_good?: 'up' | 'down';
    compare_label?: string;
    subtitle?: string;
    spark?: SparkSpec;
}

/**
 * Every other runtime block type that doesn't (yet) have a dedicated typed
 * interface here. Listed so `AnyBlock['type']` is the COMPLETE set of block
 * types the runtime renders — callers can compare `block.type` against any real
 * type without a cast. Each Block*.vue component still declares its own precise
 * props locally; the index signature keeps these loosely-typed blocks readable
 * from generic code (AppRenderer, the Builder preview) without per-block types.
 */
export interface BlockOther extends BlockBase {
    type:
        | 'accordion'
        | 'alert'
        | 'avatar'
        | 'badge'
        | 'breadcrumb'
        | 'button'
        | 'calendar'
        | 'card_grid'
        | 'carousel'
        | 'chart'
        | 'cta'
        | 'data_grid'
        | 'faq'
        | 'feature_grid'
        | 'filter_bar'
        | 'flow'
        | 'form'
        | 'funnel'
        | 'gantt'
        | 'gauge'
        | 'heatmap'
        | 'hero'
        | 'image'
        | 'insight'
        | 'kanban'
        | 'map'
        | 'markdown'
        | 'metric_grid'
        | 'modal'
        | 'multi_step_form'
        | 'pricing'
        | 'progress'
        | 'record_detail'
        | 'related_list'
        | 'sparkline'
        | 'split_view'
        | 'stat_band'
        | 'stepper'
        | 'tabs'
        | 'testimonials'
        | 'timeline'
        | 'word_cloud';
    [key: string]: unknown;
}

export type AnyBlock =
    | BlockContainer
    | BlockText
    | BlockHeading
    | BlockDivider
    | BlockSpacer
    | BlockTable
    | BlockStat
    | BlockOther;

export interface PageDef {
    id: string;
    slug: string;
    name: string;
    path: string;
    icon?: string;
    blocks: AnyBlock[];
}

export interface PageSummary {
    id: string;
    slug: string;
    name: string;
    icon: string | null;
}

export type BlockData = Record<
    string,
    TableBlockData | StatBlockData | BlockErrorData
>;

export interface TableBlockData {
    rows: Array<{ id: string; data: Record<string, unknown> }>;
}

export interface StatBlockData {
    value: number;
    compare_value?: number;
    spark_rows?: { id: string; data: Record<string, unknown> }[];
}

/**
 * Returned for any block whose server-side data resolution failed (e.g. a
 * stale field_id from a previous edit). The renderer paints a placeholder
 * instead of crashing the whole page.
 */
export interface BlockErrorData {
    error: string;
}

export type RuntimeTheme = 'light' | 'dark';

export interface RuntimePageProps {
    app: {
        id: string;
        slug: string;
        name: string;
        icon: string | null;
        color: string | null;
    };
    manifest: {
        navigation: { items?: unknown[] } | null;
        pages: PageSummary[];
        settings: {
            default_currency?: string;
            default_locale?: string;
            theme?: RuntimeTheme;
        };
        objects: ObjectDef[];
        agent?: { enabled: boolean; name?: string } | null;
    };
    page: PageDef;
    blockData: BlockData;
    /** Current URL filter params, so a filter_bar renders pre-filled. */
    params?: Record<string, string | string[]>;
    /** Author CSS, already compiled + scoped to `.sp-app-surface` (may be ''). */
    customCss?: string;
}
