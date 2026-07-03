<script setup lang="ts">
import type { DeckSlideDef } from '@/lib/deck';
import { Minus, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Schema-guarded direct editing for the selected slide: every field of the
 * layout as a plain input with its copy budget visible, list editors for
 * bullets/items/columns, and a chart data editor. Emits a full updated slide;
 * the Builder persists it as a `replace` op (server revalidates atomically).
 */
const props = defineProps<{
    slide: DeckSlideDef;
}>();

const emit = defineEmits<{ change: [slide: DeckSlideDef] }>();

const { t } = useI18n();

const s = computed(() => props.slide as Record<string, any>);

function patch(partial: Record<string, unknown>) {
    emit('change', { ...props.slide, ...partial } as DeckSlideDef);
}

function setText(key: string, value: string) {
    // Empty optional fields are dropped so the manifest stays clean.
    const next: Record<string, unknown> = { ...props.slide };
    if (value.trim() === '') delete next[key];
    else next[key] = value;
    emit('change', next as DeckSlideDef);
}

// ----- list helpers (bullets / closing bullets) -----
function setListItem(key: string, i: number, value: string) {
    const list = [...(s.value[key] ?? [])];
    list[i] = value;
    patch({ [key]: list });
}
function addListItem(key: string, max: number) {
    const list = [...(s.value[key] ?? [])];
    if (list.length >= max) return;
    list.push('');
    patch({ [key]: list });
}
function removeListItem(key: string, i: number) {
    const list = [...(s.value[key] ?? [])];
    list.splice(i, 1);
    patch({ [key]: list });
}

// ----- two_column -----
function setColumn(side: 'left' | 'right', partial: Record<string, unknown>) {
    patch({ [side]: { ...(s.value[side] ?? {}), ...partial } });
}
function setColumnItem(side: 'left' | 'right', i: number, value: string) {
    const items = [...(s.value[side]?.items ?? [])];
    items[i] = value;
    setColumn(side, { items });
}
function addColumnItem(side: 'left' | 'right') {
    const items = [...(s.value[side]?.items ?? [])];
    if (items.length >= 4) return;
    items.push('');
    setColumn(side, { items });
}
function removeColumnItem(side: 'left' | 'right', i: number) {
    const items = [...(s.value[side]?.items ?? [])];
    items.splice(i, 1);
    setColumn(side, { items });
}

// ----- metrics -----
function setMetric(i: number, key: string, value: string) {
    const items = (s.value.items ?? []).map((it: any, j: number) => {
        if (j !== i) return it;
        const next = { ...it };
        if (value.trim() === '' && key !== 'value' && key !== 'label')
            delete next[key];
        else next[key] = value;
        return next;
    });
    patch({ items });
}
function addMetric() {
    const items = [...(s.value.items ?? [])];
    if (items.length >= 4) return;
    items.push({ value: '0', label: '' });
    patch({ items });
}
function removeMetric(i: number) {
    const items = [...(s.value.items ?? [])];
    items.splice(i, 1);
    patch({ items });
}

// ----- timeline -----
function setTimelineItem(i: number, key: string, value: string) {
    const items = (s.value.items ?? []).map((it: any, j: number) => {
        if (j !== i) return it;
        const next = { ...it };
        if (value.trim() === '' && (key === 'description' || key === 'status'))
            delete next[key];
        else next[key] = value;
        return next;
    });
    patch({ items });
}
function addTimelineItem() {
    const items = [...(s.value.items ?? [])];
    if (items.length >= 6) return;
    items.push({ label: '—', title: '', status: 'upcoming' });
    patch({ items });
}
function removeTimelineItem(i: number) {
    const items = [...(s.value.items ?? [])];
    items.splice(i, 1);
    patch({ items });
}

// ----- table -----
function setColumns(value: string) {
    const columns = value
        .split(',')
        .map((c) => c.trim())
        .filter(Boolean);
    // Keep every row aligned to the new column count.
    const rows = (s.value.rows ?? []).map((row: string[]) => {
        const next = [...row];
        while (next.length < columns.length) next.push('');
        return next.slice(0, columns.length);
    });
    patch({ columns, rows });
}
function setTableCell(i: number, j: number, value: string) {
    const rows = (s.value.rows ?? []).map((row: string[], k: number) =>
        k === i
            ? row.map((c: string, l: number) => (l === j ? value : c))
            : row,
    );
    patch({ rows });
}
function addRow() {
    const rows = [...(s.value.rows ?? [])];
    if (rows.length >= 5) return;
    rows.push((s.value.columns ?? []).map(() => ''));
    patch({ rows });
}
function removeRow(i: number) {
    const rows = [...(s.value.rows ?? [])];
    if (rows.length <= 1) return;
    rows.splice(i, 1);
    patch({ rows });
}

// ----- chart -----
const labelsText = computed(() => (s.value.labels ?? []).join(', '));
function setLabels(value: string) {
    patch({
        labels: value
            .split(',')
            .map((l) => l.trim())
            .filter(Boolean),
    });
}
function setSeries(i: number, key: 'name' | 'data', value: string) {
    const series = (s.value.series ?? []).map((sr: any, j: number) =>
        j === i
            ? {
                  ...sr,
                  [key]:
                      key === 'data'
                          ? value
                                .split(',')
                                .map((n) => Number(n.trim()))
                                .filter((n) => !Number.isNaN(n))
                          : value,
              }
            : sr,
    );
    patch({ series });
}
function addSeries() {
    const series = [...(s.value.series ?? [])];
    if (
        series.length >= 3 ||
        s.value.chart_type === 'donut' ||
        s.value.chart_type === 'pie'
    )
        return;
    series.push({
        name: `Serie ${series.length + 1}`,
        data: (s.value.labels ?? []).map(() => 0),
    });
    patch({ series });
}
function removeSeries(i: number) {
    const series = [...(s.value.series ?? [])];
    if (series.length <= 1) return;
    series.splice(i, 1);
    patch({ series });
}
</script>

<template>
    <div class="space-y-4 text-sm">
        <!-- title / section / closing / bullets / two_column / metrics / chart share `title` -->
        <label
            v-if="'title' in slide || slide.layout !== 'quote'"
            class="field"
        >
            <span class="label">{{ t('slides.builder.field.title') }}</span>
            <textarea
                :value="s.title ?? ''"
                rows="2"
                class="input"
                @input="
                    setText(
                        'title',
                        ($event.target as HTMLTextAreaElement).value,
                    )
                "
            />
        </label>

        <template v-if="slide.layout === 'title' || slide.layout === 'closing'">
            <label class="field">
                <span class="label">{{
                    t('slides.builder.field.subtitle')
                }}</span>
                <textarea
                    :value="s.subtitle ?? ''"
                    rows="2"
                    class="input"
                    @input="
                        setText(
                            'subtitle',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
            </label>
        </template>

        <label v-if="slide.layout === 'title'" class="field">
            <span class="label">{{ t('slides.builder.field.meta') }}</span>
            <input
                :value="s.meta ?? ''"
                class="input"
                @input="
                    setText('meta', ($event.target as HTMLInputElement).value)
                "
            />
        </label>

        <label
            v-if="
                slide.layout === 'section' ||
                slide.layout === 'bullets' ||
                slide.layout === 'big_number'
            "
            class="field"
        >
            <span class="label">{{ t('slides.builder.field.kicker') }}</span>
            <input
                :value="s.kicker ?? ''"
                class="input"
                @input="
                    setText('kicker', ($event.target as HTMLInputElement).value)
                "
            />
        </label>

        <!-- bullets -->
        <div v-if="slide.layout === 'bullets'" class="field">
            <span class="label">{{ t('slides.builder.field.bullets') }}</span>
            <div v-for="(b, i) in s.bullets ?? []" :key="i" class="row">
                <textarea
                    :value="b"
                    rows="2"
                    class="input flex-1"
                    @input="
                        setListItem(
                            'bullets',
                            i,
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
                <button
                    type="button"
                    class="icon-btn"
                    @click="removeListItem('bullets', i)"
                >
                    <Minus class="size-3.5" />
                </button>
            </div>
            <button
                v-if="(s.bullets ?? []).length < 5"
                type="button"
                class="add-btn"
                @click="addListItem('bullets', 5)"
            >
                <Plus class="size-3.5" /> {{ t('slides.builder.add_item') }}
            </button>
        </div>

        <!-- two_column -->
        <template v-if="slide.layout === 'two_column'">
            <div
                v-for="side in ['left', 'right'] as const"
                :key="side"
                class="field rounded-lg border border-soft p-3"
            >
                <input
                    :value="s[side]?.heading ?? ''"
                    class="input font-medium"
                    :placeholder="t('slides.builder.field.heading')"
                    @input="
                        setColumn(side, {
                            heading: ($event.target as HTMLInputElement).value,
                        })
                    "
                />
                <div
                    v-for="(item, i) in s[side]?.items ?? []"
                    :key="i"
                    class="row mt-2"
                >
                    <textarea
                        :value="item"
                        rows="2"
                        class="input flex-1"
                        @input="
                            setColumnItem(
                                side,
                                i,
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                    />
                    <button
                        type="button"
                        class="icon-btn"
                        @click="removeColumnItem(side, i)"
                    >
                        <Minus class="size-3.5" />
                    </button>
                </div>
                <button
                    v-if="(s[side]?.items ?? []).length < 4"
                    type="button"
                    class="add-btn mt-2"
                    @click="addColumnItem(side)"
                >
                    <Plus class="size-3.5" /> {{ t('slides.builder.add_item') }}
                </button>
            </div>
        </template>

        <!-- big_number -->
        <template v-if="slide.layout === 'big_number'">
            <div class="grid grid-cols-2 gap-3">
                <label class="field">
                    <span class="label">{{
                        t('slides.builder.field.value')
                    }}</span>
                    <input
                        :value="s.value ?? ''"
                        class="input"
                        @input="
                            setText(
                                'value',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
                <label class="field">
                    <span class="label">{{
                        t('slides.builder.field.delta')
                    }}</span>
                    <input
                        :value="s.delta ?? ''"
                        class="input"
                        @input="
                            setText(
                                'delta',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
            </div>
            <label class="field">
                <span class="label">{{ t('slides.builder.field.label') }}</span>
                <input
                    :value="s.label ?? ''"
                    class="input"
                    @input="
                        setText(
                            'label',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </label>
            <label class="field">
                <span class="label">{{
                    t('slides.builder.field.context')
                }}</span>
                <textarea
                    :value="s.context ?? ''"
                    rows="2"
                    class="input"
                    @input="
                        setText(
                            'context',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
            </label>
        </template>

        <!-- metrics -->
        <div v-if="slide.layout === 'metrics'" class="field">
            <span class="label">{{ t('slides.builder.field.metrics') }}</span>
            <div
                v-for="(item, i) in s.items ?? []"
                :key="i"
                class="rounded-lg border border-soft p-3"
            >
                <div class="row">
                    <input
                        :value="item.value ?? ''"
                        class="input w-24"
                        :placeholder="t('slides.builder.field.value')"
                        @input="
                            setMetric(
                                i,
                                'value',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <input
                        :value="item.label ?? ''"
                        class="input flex-1"
                        :placeholder="t('slides.builder.field.label')"
                        @input="
                            setMetric(
                                i,
                                'label',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <input
                        :value="item.delta ?? ''"
                        class="input w-20"
                        :placeholder="t('slides.builder.field.delta')"
                        @input="
                            setMetric(
                                i,
                                'delta',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <button
                        type="button"
                        class="icon-btn"
                        @click="removeMetric(i)"
                    >
                        <Minus class="size-3.5" />
                    </button>
                </div>
                <p
                    v-if="item.value_source"
                    class="mt-1 text-[11px] text-ink-subtle"
                >
                    {{ t('slides.builder.live_value') }}
                </p>
            </div>
            <button
                v-if="(s.items ?? []).length < 4"
                type="button"
                class="add-btn"
                @click="addMetric"
            >
                <Plus class="size-3.5" /> {{ t('slides.builder.add_item') }}
            </button>
        </div>

        <!-- chart -->
        <template v-if="slide.layout === 'chart'">
            <label class="field">
                <span class="label">{{
                    t('slides.builder.field.chart_type')
                }}</span>
                <select
                    :value="s.chart_type"
                    class="input"
                    @change="
                        patch({
                            chart_type: ($event.target as HTMLSelectElement)
                                .value,
                        })
                    "
                >
                    <option value="bar">Bar</option>
                    <option value="hbar">Horizontal bar</option>
                    <option value="line">Line</option>
                    <option value="area">Area</option>
                    <option value="pie">Pie</option>
                    <option value="donut">Donut</option>
                    <option value="radar">Radar</option>
                </select>
            </label>
            <p
                v-if="s.data_source"
                class="rounded-lg bg-surface px-3 py-2 text-[11px] text-ink-subtle"
            >
                {{ t('slides.builder.live_chart') }}
            </p>
            <label class="field">
                <span class="label">{{
                    t('slides.builder.field.labels')
                }}</span>
                <input
                    :value="labelsText"
                    class="input"
                    @change="
                        setLabels(($event.target as HTMLInputElement).value)
                    "
                />
            </label>
            <div
                v-for="(serie, i) in s.series ?? []"
                :key="i"
                class="rounded-lg border border-soft p-3"
            >
                <div class="row">
                    <input
                        :value="serie.name"
                        class="input w-32"
                        @input="
                            setSeries(
                                i,
                                'name',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <input
                        :value="(serie.data ?? []).join(', ')"
                        class="input flex-1"
                        @change="
                            setSeries(
                                i,
                                'data',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <button
                        v-if="(s.series ?? []).length > 1"
                        type="button"
                        class="icon-btn"
                        @click="removeSeries(i)"
                    >
                        <Minus class="size-3.5" />
                    </button>
                </div>
            </div>
            <button
                v-if="
                    s.chart_type !== 'donut' &&
                    s.chart_type !== 'pie' &&
                    (s.series ?? []).length < 3
                "
                type="button"
                class="add-btn"
                @click="addSeries"
            >
                <Plus class="size-3.5" /> {{ t('slides.builder.add_series') }}
            </button>
            <label class="field">
                <span class="label">{{
                    t('slides.builder.field.takeaway')
                }}</span>
                <textarea
                    :value="s.takeaway ?? ''"
                    rows="2"
                    class="input"
                    @input="
                        setText(
                            'takeaway',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
            </label>
        </template>

        <!-- quote -->
        <template v-if="slide.layout === 'quote'">
            <label class="field">
                <span class="label">{{ t('slides.builder.field.quote') }}</span>
                <textarea
                    :value="s.quote ?? ''"
                    rows="4"
                    class="input"
                    @input="
                        setText(
                            'quote',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label class="field">
                    <span class="label">{{
                        t('slides.builder.field.attribution')
                    }}</span>
                    <input
                        :value="s.attribution ?? ''"
                        class="input"
                        @input="
                            setText(
                                'attribution',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
                <label class="field">
                    <span class="label">{{
                        t('slides.builder.field.role')
                    }}</span>
                    <input
                        :value="s.role ?? ''"
                        class="input"
                        @input="
                            setText(
                                'role',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
            </div>
        </template>

        <!-- timeline -->
        <div v-if="slide.layout === 'timeline'" class="field">
            <span class="label">{{
                t('slides.builder.field.milestones')
            }}</span>
            <div
                v-for="(item, i) in s.items ?? []"
                :key="i"
                class="rounded-lg border border-soft p-3"
            >
                <div class="row">
                    <input
                        :value="item.label ?? ''"
                        class="input w-20"
                        :placeholder="t('slides.builder.field.label')"
                        @input="
                            setTimelineItem(
                                i,
                                'label',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <input
                        :value="item.title ?? ''"
                        class="input flex-1"
                        :placeholder="t('slides.builder.field.title')"
                        @input="
                            setTimelineItem(
                                i,
                                'title',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <select
                        :value="item.status ?? 'upcoming'"
                        class="input w-28"
                        @change="
                            setTimelineItem(
                                i,
                                'status',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="done">done</option>
                        <option value="active">active</option>
                        <option value="upcoming">upcoming</option>
                    </select>
                    <button
                        type="button"
                        class="icon-btn"
                        @click="removeTimelineItem(i)"
                    >
                        <Minus class="size-3.5" />
                    </button>
                </div>
                <input
                    :value="item.description ?? ''"
                    class="input mt-2"
                    :placeholder="t('slides.builder.field.context')"
                    @input="
                        setTimelineItem(
                            i,
                            'description',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </div>
            <button
                v-if="(s.items ?? []).length < 6"
                type="button"
                class="add-btn"
                @click="addTimelineItem"
            >
                <Plus class="size-3.5" /> {{ t('slides.builder.add_item') }}
            </button>
        </div>

        <!-- table -->
        <div v-if="slide.layout === 'table'" class="field">
            <span class="label">{{ t('slides.builder.field.columns') }}</span>
            <input
                :value="(s.columns ?? []).join(', ')"
                class="input"
                @change="setColumns(($event.target as HTMLInputElement).value)"
            />
            <span class="label mt-2">{{ t('slides.builder.field.rows') }}</span>
            <div v-for="(row, i) in s.rows ?? []" :key="i" class="row">
                <input
                    v-for="(cell, j) in row"
                    :key="j"
                    :value="cell"
                    class="input flex-1"
                    @input="
                        setTableCell(
                            i,
                            j,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
                <button type="button" class="icon-btn" @click="removeRow(i)">
                    <Minus class="size-3.5" />
                </button>
            </div>
            <button
                v-if="(s.rows ?? []).length < 5"
                type="button"
                class="add-btn"
                @click="addRow"
            >
                <Plus class="size-3.5" /> {{ t('slides.builder.add_item') }}
            </button>
        </div>

        <!-- closing bullets + cta -->
        <template v-if="slide.layout === 'closing'">
            <div class="field">
                <span class="label">{{
                    t('slides.builder.field.bullets')
                }}</span>
                <div v-for="(b, i) in s.bullets ?? []" :key="i" class="row">
                    <textarea
                        :value="b"
                        rows="2"
                        class="input flex-1"
                        @input="
                            setListItem(
                                'bullets',
                                i,
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                    />
                    <button
                        type="button"
                        class="icon-btn"
                        @click="removeListItem('bullets', i)"
                    >
                        <Minus class="size-3.5" />
                    </button>
                </div>
                <button
                    v-if="(s.bullets ?? []).length < 3"
                    type="button"
                    class="add-btn"
                    @click="addListItem('bullets', 3)"
                >
                    <Plus class="size-3.5" /> {{ t('slides.builder.add_item') }}
                </button>
            </div>
            <label class="field">
                <span class="label">CTA</span>
                <input
                    :value="s.cta ?? ''"
                    class="input"
                    @input="
                        setText(
                            'cta',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </label>
        </template>

        <!-- speaker notes (all layouts) -->
        <label class="field">
            <span class="label">{{ t('slides.builder.field.notes') }}</span>
            <textarea
                :value="s.notes ?? ''"
                rows="3"
                class="input"
                @input="
                    setText(
                        'notes',
                        ($event.target as HTMLTextAreaElement).value,
                    )
                "
            />
        </label>
    </div>
</template>

<style scoped>
.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.label {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--sp-text-tertiary);
}
.input {
    border-radius: 8px;
    border: 1px solid var(--sp-border-medium);
    background: var(--sp-surface);
    padding: 6px 10px;
    font-size: 14px;
    color: var(--sp-text-primary);
    outline: none;
    min-width: 0;
}
.input:focus {
    border-color: var(--sp-border-strong);
}
.row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.icon-btn {
    margin-top: 6px;
    border-radius: 6px;
    padding: 4px;
    color: var(--sp-text-tertiary);
    transition: color 0.15s ease;
}
.icon-btn:hover {
    color: var(--sp-danger);
}
.add-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 500;
    color: var(--sp-accent-blue);
}
.add-btn:hover {
    text-decoration: underline;
}
</style>
