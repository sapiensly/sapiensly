/**
 * Per-step-type metadata used by the palette + node card + side panel.
 *
 * One source of truth, so adding/renaming a step type only requires editing
 * here, not chasing strings across 3 components. The catalog is keyed by
 * the same `type` string the manifest uses so a node's metadata is one
 * lookup away.
 */

import type { ManifestStep, StepType } from '@/types/appWorkflows';

export interface SummaryContext {
    /** Lookup table from object_id → object metadata, used by record.* summaries. */
    objectsById?: Map<string, { id: string; slug: string; name: string }>;
}

export interface StepCatalogEntry {
    type: StepType;
    /** Short user-facing label. Localised at render time via the labelKey. */
    labelKey: string;
    /** Tooltip / palette description. */
    descriptionKey: string;
    /** Lucide icon name — string so the consumer can dynamic-resolve. */
    icon: string;
    /** Accent colour for the node's left border + icon tint. */
    color: string;
    /**
     * Default props for a freshly-dragged step of this type. We fill in
     * `id` and `type` at create time; the rest is whatever skeleton makes
     * the step pass schema validation right away (so a "Save" works even
     * before the user opens the panel).
     */
    defaults: () => Partial<ManifestStep>;
    /**
     * One-line human summary shown inside the node card. Takes the live
     * step payload + an optional context with the App's objects list
     * (so record.* summaries can show "from Película" instead of the
     * cryptic raw `obj_01ksj…` id).
     */
    summary: (step: ManifestStep, ctx?: SummaryContext) => string;
}

/** Resolve a step's object_id to a friendly label when the context has it. */
function objectLabel(step: ManifestStep, ctx?: SummaryContext): string {
    const id = typeof step.object_id === 'string' ? step.object_id : '';
    if (!id) return '?';
    const obj = ctx?.objectsById?.get(id);
    return obj ? obj.name : id;
}

export const STEP_CATALOG: Record<StepType, StepCatalogEntry> = {
    log: {
        type: 'log',
        labelKey: 'apps.builder.workflows.steps.log.label',
        descriptionKey: 'apps.builder.workflows.steps.log.description',
        icon: 'FileText',
        color: '#94a3b8', // slate-400
        defaults: () => ({ message: '' }),
        summary: (s) => (typeof s.message === 'string' && s.message !== '' ? s.message : '—'),
    },
    set_variable: {
        type: 'set_variable',
        labelKey: 'apps.builder.workflows.steps.set_variable.label',
        descriptionKey: 'apps.builder.workflows.steps.set_variable.description',
        icon: 'Variable',
        color: '#a78bfa', // violet-400
        defaults: () => ({ variable: '', value: '' }),
        summary: (s) => {
            const v = typeof s.variable === 'string' ? s.variable : '';
            const val = typeof s.value === 'string' ? s.value : JSON.stringify(s.value ?? null);
            return v ? `${v} = ${val}` : '—';
        },
    },
    'record.create': {
        type: 'record.create',
        labelKey: 'apps.builder.workflows.steps.record_create.label',
        descriptionKey: 'apps.builder.workflows.steps.record_create.description',
        icon: 'Plus',
        color: '#34d399', // emerald-400
        defaults: () => ({ object_id: '', values: {} }),
        summary: (s, ctx) => `→ ${objectLabel(s, ctx)}`,
    },
    'record.update': {
        type: 'record.update',
        labelKey: 'apps.builder.workflows.steps.record_update.label',
        descriptionKey: 'apps.builder.workflows.steps.record_update.description',
        icon: 'Pencil',
        color: '#fbbf24', // amber-400
        defaults: () => ({ object_id: '', record_id: '', values: {} }),
        summary: (s, ctx) => objectLabel(s, ctx),
    },
    'record.delete': {
        type: 'record.delete',
        labelKey: 'apps.builder.workflows.steps.record_delete.label',
        descriptionKey: 'apps.builder.workflows.steps.record_delete.description',
        icon: 'Trash2',
        color: '#f87171', // red-400
        defaults: () => ({ object_id: '', record_id: '' }),
        summary: (s, ctx) => objectLabel(s, ctx),
    },
    'record.query': {
        type: 'record.query',
        labelKey: 'apps.builder.workflows.steps.record_query.label',
        descriptionKey: 'apps.builder.workflows.steps.record_query.description',
        icon: 'Search',
        color: '#60a5fa', // blue-400
        defaults: () => ({ object_id: '' }),
        summary: (s, ctx) => `from ${objectLabel(s, ctx)}`,
    },
    branch: {
        type: 'branch',
        labelKey: 'apps.builder.workflows.steps.branch.label',
        descriptionKey: 'apps.builder.workflows.steps.branch.description',
        icon: 'GitBranch',
        color: '#f472b6', // pink-400
        defaults: () => ({
            // Schema requires at least one case. Give the user a placeholder
            // they can edit (rather than refusing save out of the gate).
            cases: [{ condition: 'true', steps: [] }],
        }),
        summary: (s) => {
            const cases = Array.isArray(s.cases) ? s.cases : [];
            return `${cases.length} case${cases.length === 1 ? '' : 's'}`;
        },
    },
    'ai.complete': {
        type: 'ai.complete',
        labelKey: 'apps.builder.workflows.steps.ai_complete.label',
        descriptionKey: 'apps.builder.workflows.steps.ai_complete.description',
        icon: 'Sparkles',
        color: '#22d3ee', // cyan-400
        defaults: () => ({ prompt: '' }),
        summary: (s) => (typeof s.prompt === 'string' && s.prompt !== '' ? truncate(s.prompt, 30) : '—'),
    },
    'http.request': {
        type: 'http.request',
        labelKey: 'apps.builder.workflows.steps.http_request.label',
        descriptionKey: 'apps.builder.workflows.steps.http_request.description',
        icon: 'Globe',
        color: '#fb923c', // orange-400
        defaults: () => ({ method: 'GET', url: '' }),
        summary: (s) => {
            const method = typeof s.method === 'string' ? s.method : 'GET';
            const url = typeof s.url === 'string' ? s.url : '?';
            return `${method} ${truncate(url, 24)}`;
        },
    },
};

function truncate(s: string, max: number): string {
    return s.length > max ? s.slice(0, max - 1) + '…' : s;
}

/** Ordered list for the palette (drag-from rows). */
export const STEP_CATALOG_ORDERED: StepCatalogEntry[] = [
    STEP_CATALOG.log,
    STEP_CATALOG.set_variable,
    STEP_CATALOG['record.create'],
    STEP_CATALOG['record.update'],
    STEP_CATALOG['record.delete'],
    STEP_CATALOG['record.query'],
    STEP_CATALOG.branch,
    STEP_CATALOG['ai.complete'],
    STEP_CATALOG['http.request'],
];
