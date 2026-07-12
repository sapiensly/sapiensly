<script setup lang="ts">
/**
 * Manual-adjust drawer: edits ONE selected block through the versioned
 * update endpoint. Fixed to the viewport (never scrolls with the page) and
 * AUTO-APPLIED — every control saves on change (text debounced), no apply
 * button. The server re-checks (schema, label grounding, aggregation
 * legality) anyway.
 *
 * Layout follows the Claude-design reference (Drawer Ajuste Manual —
 * Mejoras): a LIVE mini preview in the header fed by the block's real rows,
 * intent-grouped collapsible sections (Contenido · Datos · Diseño), a
 * visual chart-type tile picker, the 12-column width grid with stepper,
 * height presets, and a sticky footer with a Restablecer action that
 * reverts to the values the card had when it was selected.
 */
import axios from 'axios';
import { ChevronDown, TrendingUp, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

interface FieldDef {
    id: string;
    name?: string;
    slug?: string;
    type?: string;
}

const props = defineProps<{
    appId: string;
    block: Record<string, unknown>;
    object: { fields?: FieldDef[]; name?: string } | null;
    /** The block's resolved rows (deferred previewBlockData), for the mini preview. */
    data?: { rows?: Array<{ data: Record<string, unknown> }> } | null;
}>();

const emit = defineEmits<{ (e: 'saved'): void; (e: 'close'): void }>();

const isChart = computed(() => props.block.type === 'chart');
const isTemporal = computed(() => !!props.block.x_field_id);
const chartTypes = computed(() =>
    isTemporal.value
        ? ['area', 'line', 'bar']
        : ['bar', 'hbar', 'donut', 'pie', 'treemap', 'pareto'],
);
const CHART_LABELS: Record<string, string> = {
    area: 'Área',
    line: 'Línea',
    bar: 'Barras',
    hbar: 'Barras H',
    donut: 'Dona',
    pie: 'Pie',
    treemap: 'Treemap',
    pareto: 'Pareto',
};
const numericFields = computed(() =>
    (props.object?.fields ?? []).filter((f) => f.type === 'number'),
);
const stringFields = computed(() =>
    (props.object?.fields ?? []).filter(
        (f) => f.type === 'string' || f.type === 'single_select',
    ),
);

const form = reactive({
    label: '',
    description: '',
    chart_type: '',
    aggregation: '',
    y_field_id: '' as string | null,
    group_by_field_id: '',
    limit: 12,
    col_span: 0,
    min_height: 0,
});
const saving = ref(false);
let seeding = false;

// Restablecer reverts to the values the card had when SELECTED — the
// snapshot survives the prop refreshes each auto-apply triggers.
let snapshotBlockId = '';
const snapshot = ref<Record<string, unknown>>({});

function seed() {
    seeding = true;
    const b = props.block as Record<string, any>;
    form.label = String(b.label ?? '');
    form.description = String(b.description ?? '');
    form.chart_type = String(b.chart_type ?? '');
    form.aggregation = String(b.aggregation ?? 'count');
    form.y_field_id = (b.y_field_id as string) ?? null;
    form.group_by_field_id = String(b.group_by_field_id ?? '');
    form.limit = Number(b.data_source?.limit ?? 12);
    form.col_span = Number(b.style?.col_span ?? 0);
    form.min_height = Number(b.style?.min_height ?? 0);
    if (String(b.id ?? '') !== snapshotBlockId) {
        snapshotBlockId = String(b.id ?? '');
        snapshot.value = { ...form };
    }
    // Let the seed-triggered watchers drain before re-arming auto-apply.
    setTimeout(() => (seeding = false), 0);
}
watch(() => props.block, seed, { immediate: true });

const dirty = computed(() =>
    Object.keys(snapshot.value).some(
        (k) =>
            (form as Record<string, unknown>)[k] !==
            (snapshot.value as Record<string, unknown>)[k],
    ),
);

async function apply(changes: Record<string, unknown>) {
    saving.value = true;
    try {
        await axios.post(`/apps/${props.appId}/builder/blocks/update`, {
            block_id: (props.block as { id: string }).id,
            changes,
        });
        emit('saved');
    } catch (e: unknown) {
        const msg =
            (e as { response?: { data?: { message?: string } } }).response
                ?.data?.message ?? 'No se pudo aplicar el ajuste.';
        toast.error(msg);
        seed(); // revert the control to the block's real value
    } finally {
        saving.value = false;
    }
}

/** Auto-apply: selects fire immediately; text debounces. */
function autoApply(key: string, getter: () => unknown, debounceMs = 0) {
    let timer: ReturnType<typeof setTimeout> | undefined;
    watch(getter, (next) => {
        if (seeding) return;
        const b = props.block as Record<string, any>;
        const current =
            key === 'limit'
                ? Number(b.data_source?.limit ?? 12)
                : key === 'col_span' || key === 'min_height'
                  ? Number(b.style?.[key] ?? 0)
                  : (b[key] ?? (key === 'y_field_id' ? null : ''));
        if (next === current || (next === '' && (current ?? '') === '')) {
            return;
        }
        clearTimeout(timer);
        timer = setTimeout(() => {
            const value =
                key === 'description' && next === ''
                    ? null
                    : key === 'y_field_id' && next === ''
                      ? null
                      : (key === 'col_span' || key === 'min_height') &&
                          next === 0
                        ? null
                        : next;
            apply({ [key]: value });
        }, debounceMs);
    });
}
autoApply('label', () => form.label, 700);
autoApply('description', () => form.description, 700);
autoApply('chart_type', () => form.chart_type);
autoApply('aggregation', () => form.aggregation);
autoApply('y_field_id', () => form.y_field_id);
autoApply('group_by_field_id', () => form.group_by_field_id);
autoApply('limit', () => form.limit, 350);
autoApply('col_span', () => form.col_span);
autoApply('min_height', () => form.min_height);

function restore() {
    const snap = snapshot.value as typeof form;
    const changes: Record<string, unknown> = {};
    for (const k of Object.keys(snap) as Array<keyof typeof form>) {
        if (form[k] === snap[k]) continue;
        const v = snap[k];
        changes[k] =
            k === 'description' && v === ''
                ? null
                : k === 'y_field_id' && v === ''
                  ? null
                  : (k === 'col_span' || k === 'min_height') && v === 0
                    ? null
                    : v;
    }
    if (Object.keys(changes).length === 0) return;
    apply(changes);
}

// ---- Collapsible sections --------------------------------------------------
const open = reactive({ contenido: true, datos: true, diseno: true });

// ---- Width stepper ----------------------------------------------------------
function stepWidth(delta: number) {
    // From «auto», stepping starts at full width and narrows from there.
    const current = form.col_span === 0 ? 12 : form.col_span;
    form.col_span = Math.min(12, Math.max(3, current + delta));
}

// ---- Live mini preview -------------------------------------------------------
// Aggregate the block's REAL rows with the CURRENT form values (mirror of
// BlockChart's bucketing, trimmed): the preview reacts to every edit even
// before the canvas refreshes.
const miniSeries = computed<{ label: string; value: number }[]>(() => {
    const rows = props.data?.rows ?? [];
    if (!isChart.value || rows.length === 0) return [];
    const fields = props.object?.fields ?? [];
    const groupId =
        (props.block.x_field_id as string) || form.group_by_field_id;
    const groupSlug = fields.find((f) => f.id === groupId)?.slug;
    const ySlug = fields.find((f) => f.id === form.y_field_id)?.slug;

    const buckets = new Map<string, number[]>();
    for (const r of rows) {
        const key = String(groupSlug ? (r.data[groupSlug] ?? '—') : 'all');
        const raw = ySlug ? Number(r.data[ySlug] ?? 0) : 1;
        if (!buckets.has(key)) buckets.set(key, []);
        buckets.get(key)!.push(Number.isFinite(raw) ? raw : 0);
    }
    let out = [...buckets.entries()].map(([label, values]) => {
        switch (form.aggregation) {
            case 'sum':
                return { label, value: values.reduce((a, b) => a + b, 0) };
            case 'avg':
                return {
                    label,
                    value: values.reduce((a, b) => a + b, 0) / values.length,
                };
            case 'min':
                return { label, value: Math.min(...values) };
            case 'max':
                return { label, value: Math.max(...values) };
            default:
                return { label, value: values.length };
        }
    });
    if (isTemporal.value) {
        out.sort((a, b) => (a.label < b.label ? -1 : 1));
    } else {
        out.sort((a, b) => b.value - a.value);
    }
    const cap =
        form.chart_type === 'hbar' ? 5 : isTemporal.value ? 24 : 8;
    out = out.slice(0, cap);
    return out;
});

const miniMax = computed(() =>
    Math.max(1, ...miniSeries.value.map((s) => s.value)),
);
const miniTotal = computed(() =>
    miniSeries.value.reduce((a, s) => a + s.value, 0),
);

// Area/line path over a 300×56 viewBox.
const miniLinePath = computed(() => {
    const pts = miniSeries.value;
    if (pts.length < 2) return '';
    const step = 300 / (pts.length - 1);
    return pts
        .map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'}${(i * step).toFixed(1)} ${(
                    52 -
                    (p.value / miniMax.value) * 46
                ).toFixed(1)}`,
        )
        .join(' ');
});
const miniAreaPath = computed(() =>
    miniLinePath.value ? `${miniLinePath.value} L300 56 L0 56 Z` : '',
);
// Donut segments as stroke-dash arcs on a circle (r=20, C≈125.6).
const miniDonut = computed(() => {
    const C = 2 * Math.PI * 20;
    let acc = 0;
    return miniSeries.value.slice(0, 5).map((s, i) => {
        const frac = miniTotal.value > 0 ? s.value / miniTotal.value : 0;
        const seg = {
            dash: `${(frac * C).toFixed(1)} ${C.toFixed(1)}`,
            offset: (-acc * C).toFixed(1),
            opacity: 1 - i * 0.17,
        };
        acc += frac;
        return seg;
    });
});

const measureName = computed(
    () =>
        numericFields.value.find((f) => f.id === form.y_field_id)?.name ??
        'conteo',
);
const dimensionName = computed(() => {
    if (isTemporal.value) return 'período';
    return (
        stringFields.value.find((f) => f.id === form.group_by_field_id)
            ?.name ?? 'categoría'
    );
});
const previewChips = computed(() => {
    const chips = [CHART_LABELS[form.chart_type] ?? form.chart_type];
    chips.push(
        form.y_field_id
            ? `${form.aggregation} · ${measureName.value}`
            : 'conteo',
    );
    return chips;
});
</script>

<template>
    <Teleport to="body">
        <aside
            class="fixed inset-y-0 right-0 z-[70] flex w-80 flex-col border-l border-soft bg-navy shadow-2xl"
        >
            <!-- Header: identity + live preview -->
            <header class="border-b border-soft px-4 pt-4 pb-3">
                <div class="mb-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span
                            class="flex size-5 items-center justify-center rounded-sp-sm bg-accent-blue/10 text-accent-blue"
                        >
                            <TrendingUp class="size-3" />
                        </span>
                        <span
                            class="text-[10px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                        >
                            Ajuste manual
                        </span>
                    </div>
                    <button
                        type="button"
                        class="flex size-6 items-center justify-center rounded-sp-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                        @click="emit('close')"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>
                <p class="truncate text-[15px] font-semibold text-ink">
                    {{ form.label || (block.type as string) }}
                </p>

                <div
                    v-if="isChart"
                    class="mt-3 rounded-sp-sm border border-medium bg-surface px-3 pt-2.5 pb-2"
                >
                    <div class="mb-1.5 flex items-center justify-between">
                        <span
                            class="text-[9px] font-semibold tracking-[0.1em] text-ink-subtle uppercase"
                        >
                            Vista previa
                        </span>
                        <span
                            class="flex items-center gap-1.5 text-[11px] font-semibold text-emerald-500"
                        >
                            <span
                                class="size-1.5 rounded-full bg-emerald-500"
                            />
                            En vivo
                        </span>
                    </div>

                    <!-- Mini chart from the block's real rows -->
                    <div class="text-accent-blue">
                        <svg
                            v-if="
                                miniSeries.length &&
                                (form.chart_type === 'line' ||
                                    form.chart_type === 'area')
                            "
                            viewBox="0 0 300 56"
                            preserveAspectRatio="none"
                            class="block h-14 w-full"
                        >
                            <path
                                v-if="form.chart_type === 'area'"
                                :d="miniAreaPath"
                                fill="currentColor"
                                opacity="0.15"
                            />
                            <path
                                :d="miniLinePath"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                                stroke-linecap="round"
                            />
                        </svg>
                        <div
                            v-else-if="
                                miniSeries.length && form.chart_type === 'hbar'
                            "
                            class="flex h-14 flex-col justify-center gap-1"
                        >
                            <div
                                v-for="(s, i) in miniSeries"
                                :key="s.label"
                                class="h-2 rounded-pill bg-current"
                                :style="{
                                    width: (s.value / miniMax) * 100 + '%',
                                    opacity: 1 - i * 0.15,
                                }"
                            />
                        </div>
                        <svg
                            v-else-if="
                                miniSeries.length &&
                                (form.chart_type === 'donut' ||
                                    form.chart_type === 'pie')
                            "
                            viewBox="0 0 56 56"
                            class="mx-auto block h-14"
                        >
                            <circle
                                v-for="(seg, i) in miniDonut"
                                :key="i"
                                cx="28"
                                cy="28"
                                r="20"
                                fill="none"
                                stroke="currentColor"
                                :stroke-width="
                                    form.chart_type === 'pie' ? 20 : 9
                                "
                                :stroke-dasharray="seg.dash"
                                :stroke-dashoffset="seg.offset"
                                :opacity="seg.opacity"
                                transform="rotate(-90 28 28)"
                            />
                        </svg>
                        <div
                            v-else-if="
                                miniSeries.length &&
                                form.chart_type === 'treemap'
                            "
                            class="flex h-14 gap-0.5"
                        >
                            <div
                                v-for="(s, i) in miniSeries.slice(0, 4)"
                                :key="s.label"
                                class="rounded-[3px] bg-current"
                                :style="{
                                    flexGrow: Math.max(1, s.value),
                                    opacity: 1 - i * 0.18,
                                }"
                            />
                        </div>
                        <div
                            v-else-if="miniSeries.length"
                            class="flex h-14 items-end gap-1"
                        >
                            <div
                                v-for="(s, i) in miniSeries"
                                :key="s.label"
                                class="flex-1 rounded-t-[3px] bg-current"
                                :style="{
                                    height:
                                        Math.max(
                                            6,
                                            (s.value / miniMax) * 100,
                                        ) + '%',
                                    opacity:
                                        form.chart_type === 'pareto'
                                            ? 1 - i * 0.09
                                            : 1,
                                }"
                            />
                        </div>
                        <div
                            v-else
                            class="flex h-14 items-center justify-center text-[11px] text-ink-subtle"
                        >
                            Cargando datos…
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span
                            v-for="(chip, i) in previewChips"
                            :key="chip"
                            class="rounded-sp-sm px-2 py-0.5 text-[10.5px] font-semibold"
                            :class="
                                i === 0
                                    ? 'bg-accent-blue/10 text-accent-blue'
                                    : 'bg-surface text-ink-muted'
                            "
                        >
                            {{ chip }}
                        </span>
                    </div>
                </div>
            </header>

            <!-- Sections -->
            <div class="min-h-0 flex-1 overflow-y-auto">
                <!-- CONTENIDO -->
                <section class="border-b border-soft">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-4 pt-3.5 pb-2.5"
                        @click="open.contenido = !open.contenido"
                    >
                        <span
                            class="flex-1 text-left text-[10.5px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                        >
                            Contenido
                        </span>
                        <ChevronDown
                            class="size-4 text-ink-subtle transition-transform"
                            :class="open.contenido ? '' : '-rotate-90'"
                        />
                    </button>
                    <div v-show="open.contenido" class="space-y-3 px-4 pb-4">
                        <label class="block space-y-1.5 text-xs text-ink-muted">
                            <span>Título</span>
                            <input
                                v-model="form.label"
                                type="text"
                                class="w-full rounded-sp-sm border border-medium bg-surface px-3 py-2 text-[13px] text-ink outline-none focus:border-accent-blue"
                            />
                        </label>
                        <label class="block space-y-1.5 text-xs text-ink-muted">
                            <span class="flex items-center justify-between">
                                Descripción
                                <span class="text-[10.5px] text-ink-subtle">
                                    {{ form.description.length }} / 300
                                </span>
                            </span>
                            <textarea
                                v-model="form.description"
                                rows="2"
                                maxlength="300"
                                class="w-full resize-none rounded-sp-sm border border-medium bg-surface px-3 py-2 text-[13px] leading-relaxed text-ink outline-none focus:border-accent-blue"
                            />
                        </label>
                    </div>
                </section>

                <!-- DATOS -->
                <section v-if="isChart" class="border-b border-soft">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-4 pt-3.5 pb-2.5"
                        @click="open.datos = !open.datos"
                    >
                        <span
                            class="flex-1 text-left text-[10.5px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                        >
                            Datos
                        </span>
                        <ChevronDown
                            class="size-4 text-ink-subtle transition-transform"
                            :class="open.datos ? '' : '-rotate-90'"
                        />
                    </button>
                    <div v-show="open.datos" class="px-4 pb-4">
                        <!-- Visual chart-type picker -->
                        <p class="mb-2 text-xs text-ink-muted">
                            Tipo de gráfica
                        </p>
                        <div class="mb-4 grid grid-cols-3 gap-2">
                            <button
                                v-for="ct in chartTypes"
                                :key="ct"
                                type="button"
                                class="flex flex-col items-center gap-1.5 rounded-sp-sm border px-1.5 py-2.5 text-[11px] font-semibold transition-colors"
                                :class="
                                    form.chart_type === ct
                                        ? 'border-accent-blue bg-accent-blue/10 text-accent-blue'
                                        : 'border-transparent bg-surface text-ink-muted hover:text-ink'
                                "
                                @click="form.chart_type = ct"
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    class="size-5"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.9"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <template v-if="ct === 'area'">
                                        <path d="M3 3v18h18" />
                                        <path d="M7 14l3-3 3 2 4-6" />
                                        <path
                                            d="M7 14l3-3 3 2 4-6V17H7z"
                                            fill="currentColor"
                                            fill-opacity="0.15"
                                            stroke="none"
                                        />
                                    </template>
                                    <template v-else-if="ct === 'line'">
                                        <path d="M3 3v18h18" />
                                        <path d="M7 14l3-3 3 2 4-6" />
                                    </template>
                                    <template v-else-if="ct === 'bar'">
                                        <path d="M3 3v18h18" />
                                        <rect x="7" y="11" width="3" height="6" />
                                        <rect x="13" y="7" width="3" height="10" />
                                    </template>
                                    <template v-else-if="ct === 'hbar'">
                                        <path d="M3 3v18h18" />
                                        <rect x="6" y="7" width="10" height="3" />
                                        <rect x="6" y="13" width="6" height="3" />
                                    </template>
                                    <template
                                        v-else-if="
                                            ct === 'donut' || ct === 'pie'
                                        "
                                    >
                                        <circle cx="12" cy="12" r="9" />
                                        <circle
                                            v-if="ct === 'donut'"
                                            cx="12"
                                            cy="12"
                                            r="3.5"
                                        />
                                        <path v-else d="M12 3v9l6.5 6.2" />
                                    </template>
                                    <template v-else-if="ct === 'treemap'">
                                        <rect x="3" y="3" width="18" height="18" rx="1" />
                                        <path d="M12 3v18M12 12h9" />
                                    </template>
                                    <template v-else-if="ct === 'pareto'">
                                        <path d="M3 3v18h18" />
                                        <rect x="6" y="9" width="3" height="8" />
                                        <rect x="11" y="12" width="3" height="5" />
                                        <rect x="16" y="14" width="3" height="3" />
                                        <path d="M6 8c4-3 9-5 13-5.5" />
                                    </template>
                                </svg>
                                {{ CHART_LABELS[ct] ?? ct }}
                            </button>
                        </div>

                        <!-- Medida + Agregación -->
                        <div class="mb-2 flex gap-2.5">
                            <label
                                class="min-w-0 flex-1 space-y-1.5 text-xs text-ink-muted"
                            >
                                <span>Medida</span>
                                <select
                                    v-model="form.y_field_id"
                                    class="w-full rounded-sp-sm border border-medium bg-surface px-2.5 py-2 text-[13px] text-ink outline-none focus:border-accent-blue"
                                >
                                    <option :value="null">— conteo —</option>
                                    <option
                                        v-for="f in numericFields"
                                        :key="f.id"
                                        :value="f.id"
                                    >
                                        {{ f.name ?? f.slug }}
                                    </option>
                                </select>
                            </label>
                            <label
                                class="w-[104px] shrink-0 space-y-1.5 text-xs text-ink-muted"
                            >
                                <span>Agregación</span>
                                <select
                                    v-model="form.aggregation"
                                    class="w-full rounded-sp-sm border border-medium bg-surface px-2.5 py-2 text-[13px] text-ink outline-none focus:border-accent-blue"
                                >
                                    <option
                                        v-for="a in [
                                            'count',
                                            'sum',
                                            'avg',
                                            'min',
                                            'max',
                                        ]"
                                        :key="a"
                                        :value="a"
                                    >
                                        {{ a }}
                                    </option>
                                </select>
                            </label>
                        </div>
                        <p class="mb-4 text-[11.5px] text-ink-subtle">
                            Se graficará
                            <strong class="font-semibold text-accent-blue">
                                {{
                                    form.y_field_id
                                        ? `${form.aggregation}(${measureName})`
                                        : 'el conteo'
                                }}
                            </strong>
                            por {{ dimensionName }}.
                        </p>

                        <label
                            v-if="!isTemporal && stringFields.length"
                            class="mb-4 block space-y-1.5 text-xs text-ink-muted"
                        >
                            <span>Dimensión</span>
                            <select
                                v-model="form.group_by_field_id"
                                class="w-full rounded-sp-sm border border-medium bg-surface px-2.5 py-2 text-[13px] text-ink outline-none focus:border-accent-blue"
                            >
                                <option
                                    v-for="f in stringFields"
                                    :key="f.id"
                                    :value="f.id"
                                >
                                    {{ f.name ?? f.slug }}
                                </option>
                            </select>
                        </label>

                        <!-- Categories slider with range context -->
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs text-ink-muted">
                                Límite de categorías
                            </span>
                            <span
                                class="rounded-sp-sm bg-surface px-2 py-0.5 text-xs font-semibold text-ink"
                            >
                                {{ form.limit }}
                            </span>
                        </div>
                        <input
                            v-model.number="form.limit"
                            type="range"
                            min="3"
                            max="25"
                            class="w-full" style="accent-color: var(--sp-accent-blue)"
                        />
                        <div
                            class="flex justify-between text-[10.5px] text-ink-subtle"
                        >
                            <span>3</span><span>máx. 25</span>
                        </div>
                    </div>
                </section>

                <!-- DISEÑO -->
                <section>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-4 pt-3.5 pb-2.5"
                        @click="open.diseno = !open.diseno"
                    >
                        <span
                            class="flex-1 text-left text-[10.5px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                        >
                            Diseño
                        </span>
                        <ChevronDown
                            class="size-4 text-ink-subtle transition-transform"
                            :class="open.diseno ? '' : '-rotate-90'"
                        />
                    </button>
                    <div v-show="open.diseno" class="px-4 pb-5">
                        <!-- Width as the real 12-column grid + stepper -->
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs text-ink-muted">Ancho</span>
                            <span class="text-xs font-semibold text-ink">
                                <template v-if="form.col_span > 0">
                                    {{ form.col_span }}
                                    <span class="font-normal text-ink-subtle">
                                        / 12 col
                                    </span>
                                </template>
                                <template v-else>Auto</template>
                            </span>
                        </div>
                        <div class="mb-2 flex items-center gap-2">
                            <button
                                type="button"
                                class="flex size-7 shrink-0 items-center justify-center rounded-sp-sm border border-medium text-base text-ink-muted transition-colors hover:border-accent-blue hover:text-accent-blue"
                                @click="stepWidth(-1)"
                            >
                                −
                            </button>
                            <div class="flex flex-1 gap-[3px]">
                                <div
                                    v-for="i in 12"
                                    :key="i"
                                    class="h-5 flex-1 rounded-[3px]"
                                    :class="
                                        form.col_span === 0
                                            ? 'bg-accent-blue/25'
                                            : i <= form.col_span
                                              ? 'bg-accent-blue'
                                              : 'bg-surface'
                                    "
                                />
                            </div>
                            <button
                                type="button"
                                class="flex size-7 shrink-0 items-center justify-center rounded-sp-sm border border-medium text-base text-ink-muted transition-colors hover:border-accent-blue hover:text-accent-blue"
                                @click="stepWidth(1)"
                            >
                                +
                            </button>
                        </div>
                        <button
                            v-if="form.col_span > 0"
                            type="button"
                            class="mb-4 text-[11px] font-semibold text-ink-subtle transition-colors hover:text-accent-blue"
                            @click="form.col_span = 0"
                        >
                            Volver a auto (columnas iguales)
                        </button>
                        <div v-else class="mb-4" />

                        <!-- Height presets with explicit unit -->
                        <p class="mb-1.5 text-xs text-ink-muted">
                            Alto mínimo
                        </p>
                        <div class="flex gap-1.5">
                            <button
                                v-for="h in [240, 320, 420, 0]"
                                :key="h"
                                type="button"
                                class="flex flex-1 items-center justify-center gap-0.5 rounded-sp-sm border py-2 text-xs font-semibold transition-colors"
                                :class="
                                    form.min_height === h
                                        ? 'border-accent-blue bg-accent-blue/10 text-accent-blue'
                                        : 'border-transparent bg-surface text-ink-muted hover:text-ink'
                                "
                                @click="form.min_height = h"
                            >
                                <template v-if="h > 0">
                                    {{ h }}<span class="opacity-55">px</span>
                                </template>
                                <template v-else>Auto</template>
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Sticky footer: state + reset -->
            <footer
                class="flex items-center justify-between border-t border-soft bg-surface px-4 py-3"
            >
                <span
                    class="flex items-center gap-1.5 text-[11.5px] text-ink-muted"
                >
                    <span
                        v-if="saving"
                        class="size-2 animate-pulse rounded-full bg-accent-blue"
                    />
                    <svg
                        v-else
                        viewBox="0 0 24 24"
                        class="size-3.5 text-emerald-500"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M20 6 9 17l-5-5" />
                    </svg>
                    Cambios en vivo ·
                    <span class="text-ink-subtle">versionado</span>
                </span>
                <button
                    type="button"
                    class="rounded-sp-sm border border-medium px-3 py-1.5 text-xs font-semibold transition-colors"
                    :class="
                        dirty
                            ? 'text-ink-muted hover:border-accent-blue hover:text-accent-blue'
                            : 'cursor-default text-ink-subtle opacity-50'
                    "
                    :disabled="!dirty"
                    @click="restore()"
                >
                    Restablecer
                </button>
            </footer>
        </aside>
    </Teleport>
</template>
