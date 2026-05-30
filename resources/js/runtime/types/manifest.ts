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
    | 'rich_text';

export interface FieldDef {
    id: string;
    slug: string;
    name: string;
    type: FieldType;
    currency_code?: string;
    options?: Array<{ id: string; value: string; label: string; color?: string }>;
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
export function resolveField(object: ObjectDef | undefined, fieldId: string | undefined): FieldDef | undefined {
    if (!fieldId) return undefined;
    if (fieldId in SYSTEM_FIELDS) return SYSTEM_FIELDS[fieldId];
    return object?.fields.find((f) => f.id === fieldId);
}

export interface BlockBase {
    id: string;
    type: string;
    style?: { padding?: string; margin?: string; background?: string };
}

export interface BlockContainer extends BlockBase {
    type: 'container';
    direction?: 'row' | 'column';
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
    columns: Array<{ id: string; field_id: string; label_override?: string; width?: number }>;
    empty_state_message?: string;
}

export interface BlockStat extends BlockBase {
    type: 'stat';
    label: string;
    query: { object_id: string };
    aggregation: 'count' | 'sum' | 'avg' | 'min' | 'max';
    field_id?: string;
    format?: 'number' | 'currency' | 'percentage' | 'duration';
}

export type AnyBlock = BlockContainer | BlockText | BlockHeading | BlockDivider | BlockSpacer | BlockTable | BlockStat;

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

export type BlockData = Record<string, TableBlockData | StatBlockData | BlockErrorData>;

export interface TableBlockData {
    rows: Array<{ id: string; data: Record<string, unknown> }>;
}

export interface StatBlockData {
    value: number;
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
    app: { id: string; slug: string; name: string; icon: string | null; color: string | null };
    manifest: {
        navigation: { items?: unknown[] } | null;
        pages: PageSummary[];
        settings: { default_currency?: string; default_locale?: string; theme?: RuntimeTheme };
        objects: ObjectDef[];
    };
    page: PageDef;
    blockData: BlockData;
}
