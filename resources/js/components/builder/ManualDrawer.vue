<script setup lang="ts">
/**
 * Manual-adjust drawer: edits ONE selected block through the versioned
 * update endpoint. Every option is enumerated from the block's own axes and
 * its object's fields — the drawer cannot build an illegal chart, and the
 * server re-checks (schema, label grounding, aggregation legality) anyway.
 */
import axios from 'axios';
import { computed, reactive, watch } from 'vue';
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
}>();

const emit = defineEmits<{ (e: 'saved'): void; (e: 'close'): void }>();

const isChart = computed(() => props.block.type === 'chart');
const isTemporal = computed(() => !!props.block.x_field_id);

const CHART_TYPES_TEMPORAL = ['line', 'area', 'bar'];
const CHART_TYPES_CATEGORICAL = [
    'bar',
    'hbar',
    'donut',
    'pie',
    'treemap',
    'pareto',
];
const chartTypes = computed(() =>
    isTemporal.value ? CHART_TYPES_TEMPORAL : CHART_TYPES_CATEGORICAL,
);

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
    saving: false,
});

function seed() {
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
}
watch(() => props.block, seed, { immediate: true });

async function save() {
    const b = props.block as Record<string, any>;
    const changes: Record<string, unknown> = {};
    if (form.label !== String(b.label ?? '')) changes.label = form.label;
    if (form.description !== String(b.description ?? ''))
        changes.description = form.description === '' ? null : form.description;
    if (isChart.value) {
        if (form.chart_type !== b.chart_type)
            changes.chart_type = form.chart_type;
        if (form.aggregation !== (b.aggregation ?? 'count'))
            changes.aggregation = form.aggregation;
        if ((form.y_field_id || null) !== (b.y_field_id ?? null))
            changes.y_field_id = form.y_field_id || null;
        if (
            form.group_by_field_id &&
            form.group_by_field_id !== b.group_by_field_id
        )
            changes.group_by_field_id = form.group_by_field_id;
        if (form.limit !== Number(b.data_source?.limit ?? 12))
            changes.limit = form.limit;
    }
    if (form.col_span > 0 && form.col_span !== Number(b.style?.col_span ?? 0))
        changes.col_span = form.col_span;
    if (
        form.min_height > 0 &&
        form.min_height !== Number(b.style?.min_height ?? 0)
    )
        changes.min_height = form.min_height;

    if (Object.keys(changes).length === 0) {
        toast.info('Nada que guardar.');
        return;
    }
    form.saving = true;
    try {
        await axios.post(`/apps/${props.appId}/builder/blocks/update`, {
            block_id: (props.block as { id: string }).id,
            changes,
        });
        toast.success('Ajuste aplicado.');
        emit('saved');
    } catch (e: unknown) {
        const msg =
            (e as { response?: { data?: { message?: string } } }).response
                ?.data?.message ?? 'No se pudo aplicar el ajuste.';
        toast.error(msg);
    } finally {
        form.saving = false;
    }
}
</script>

<template>
    <aside
        class="absolute inset-y-0 right-0 z-40 flex w-80 flex-col gap-4 overflow-y-auto border-l border-soft bg-navy p-4 shadow-2xl"
    >
        <header class="flex items-center justify-between">
            <div>
                <p class="text-[10px] tracking-wider text-ink-subtle uppercase">
                    Ajuste manual
                </p>
                <p class="text-sm font-semibold text-ink">
                    {{ form.label || (block.type as string) }}
                </p>
            </div>
            <button
                type="button"
                class="rounded-pill px-2 py-1 text-xs text-ink-muted hover:text-ink"
                @click="emit('close')"
            >
                ✕
            </button>
        </header>

        <label class="space-y-1 text-xs text-ink-muted">
            <span>Título</span>
            <input
                v-model="form.label"
                type="text"
                class="w-full rounded-sp-sm border border-medium bg-surface px-2.5 py-1.5 text-sm text-ink"
            />
        </label>
        <label class="space-y-1 text-xs text-ink-muted">
            <span>Descripción</span>
            <textarea
                v-model="form.description"
                rows="2"
                class="w-full rounded-sp-sm border border-medium bg-surface px-2.5 py-1.5 text-sm text-ink"
            />
        </label>

        <template v-if="isChart">
            <label class="space-y-1 text-xs text-ink-muted">
                <span>Tipo de gráfica</span>
                <select
                    v-model="form.chart_type"
                    class="w-full rounded-sp-sm border border-medium bg-surface px-2.5 py-1.5 text-sm text-ink"
                >
                    <option v-for="ct in chartTypes" :key="ct" :value="ct">
                        {{ ct }}
                    </option>
                </select>
            </label>

            <div class="grid grid-cols-2 gap-2">
                <label class="space-y-1 text-xs text-ink-muted">
                    <span>Medida</span>
                    <select
                        v-model="form.y_field_id"
                        class="w-full rounded-sp-sm border border-medium bg-surface px-2 py-1.5 text-sm text-ink"
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
                <label class="space-y-1 text-xs text-ink-muted">
                    <span>Agregación</span>
                    <select
                        v-model="form.aggregation"
                        class="w-full rounded-sp-sm border border-medium bg-surface px-2 py-1.5 text-sm text-ink"
                    >
                        <option
                            v-for="a in ['count', 'sum', 'avg', 'min', 'max']"
                            :key="a"
                            :value="a"
                        >
                            {{ a }}
                        </option>
                    </select>
                </label>
            </div>

            <label
                v-if="!isTemporal && stringFields.length"
                class="space-y-1 text-xs text-ink-muted"
            >
                <span>Dimensión</span>
                <select
                    v-model="form.group_by_field_id"
                    class="w-full rounded-sp-sm border border-medium bg-surface px-2.5 py-1.5 text-sm text-ink"
                >
                    <option v-for="f in stringFields" :key="f.id" :value="f.id">
                        {{ f.name ?? f.slug }}
                    </option>
                </select>
            </label>

            <label class="space-y-1 text-xs text-ink-muted">
                <span>Límite de categorías: {{ form.limit }}</span>
                <input
                    v-model.number="form.limit"
                    type="range"
                    min="3"
                    max="25"
                    class="w-full"
                />
            </label>
        </template>

        <div class="grid grid-cols-2 gap-2">
            <label class="space-y-1 text-xs text-ink-muted">
                <span>Ancho (de 12)</span>
                <select
                    v-model.number="form.col_span"
                    class="w-full rounded-sp-sm border border-medium bg-surface px-2 py-1.5 text-sm text-ink"
                >
                    <option :value="0">auto</option>
                    <option v-for="n in [3, 4, 5, 6, 7, 8, 9, 12]" :key="n" :value="n">
                        {{ n }}
                    </option>
                </select>
            </label>
            <label class="space-y-1 text-xs text-ink-muted">
                <span>Alto mínimo</span>
                <select
                    v-model.number="form.min_height"
                    class="w-full rounded-sp-sm border border-medium bg-surface px-2 py-1.5 text-sm text-ink"
                >
                    <option :value="0">auto</option>
                    <option :value="260">S · 260px</option>
                    <option :value="360">M · 360px</option>
                    <option :value="480">L · 480px</option>
                </select>
            </label>
        </div>

        <p class="text-[11px] leading-relaxed text-ink-subtle">
            También puedes arrastrar los bordes derecho e inferior de la card
            seleccionada para ajustar su tamaño.
        </p>

        <button
            type="button"
            class="mt-auto rounded-pill bg-accent-blue px-4 py-2 text-sm font-medium text-white transition-opacity disabled:opacity-50"
            :disabled="form.saving"
            @click="save"
        >
            {{ form.saving ? 'Aplicando…' : 'Aplicar cambios' }}
        </button>
    </aside>
</template>
