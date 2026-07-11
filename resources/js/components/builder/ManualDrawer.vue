<script setup lang="ts">
/**
 * Manual-adjust drawer: edits ONE selected block through the versioned
 * update endpoint. Fixed to the viewport (never scrolls with the page) and
 * AUTO-APPLIED — every control saves on change (text debounced), no apply
 * button. Options are enumerated from the block's axes and its object's
 * fields; the server re-checks (schema, label grounding, aggregation
 * legality) anyway.
 */
import axios from 'axios';
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
}>();

const emit = defineEmits<{ (e: 'saved'): void; (e: 'close'): void }>();

const isChart = computed(() => props.block.type === 'chart');
const isTemporal = computed(() => !!props.block.x_field_id);
const chartTypes = computed(() =>
    isTemporal.value
        ? ['line', 'area', 'bar']
        : ['bar', 'hbar', 'donut', 'pie', 'treemap', 'pareto'],
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
});
const saving = ref(false);
let seeding = false;

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
    // Let the seed-triggered watchers drain before re-arming auto-apply.
    setTimeout(() => (seeding = false), 0);
}
watch(() => props.block, seed, { immediate: true });

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
                      : key === 'col_span' && next === 0
                        ? undefined
                        : key === 'min_height' && next === 0
                          ? null
                          : next;
            if (value === undefined) return;
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
</script>

<template>
    <Teleport to="body">
        <aside
            class="fixed inset-y-0 right-0 z-[70] flex w-80 flex-col gap-4 overflow-y-auto border-l border-soft bg-navy p-4 shadow-2xl"
        >
            <header class="flex items-center justify-between">
                <div class="min-w-0">
                    <p
                        class="text-[10px] tracking-wider text-ink-subtle uppercase"
                    >
                        Ajuste manual
                    </p>
                    <p class="truncate text-sm font-semibold text-ink">
                        {{ form.label || (block.type as string) }}
                    </p>
                </div>
                <span class="flex items-center gap-2">
                    <span
                        v-if="saving"
                        class="size-2 animate-pulse rounded-full bg-accent-blue"
                        title="Aplicando…"
                    />
                    <button
                        type="button"
                        class="rounded-pill px-2 py-1 text-xs text-ink-muted hover:text-ink"
                        @click="emit('close')"
                    >
                        ✕
                    </button>
                </span>
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

                <label
                    v-if="!isTemporal && stringFields.length"
                    class="space-y-1 text-xs text-ink-muted"
                >
                    <span>Dimensión</span>
                    <select
                        v-model="form.group_by_field_id"
                        class="w-full rounded-sp-sm border border-medium bg-surface px-2.5 py-1.5 text-sm text-ink"
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
                        <option
                            v-for="n in [3, 4, 5, 6, 7, 8, 9, 12]"
                            :key="n"
                            :value="n"
                        >
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
                Los cambios se aplican al instante y quedan versionados.
                También puedes arrastrar los bordes de la card para el tamaño,
                o el asa superior para reordenarla.
            </p>
        </aside>
    </Teleport>
</template>
