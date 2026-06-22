<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ExternalLink, Info, Loader2, Search, X } from '@/lib/admin/icons';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface OpenRouterModel {
    id: string;
    label: string;
    contextWindow: number | null;
    inputPricePerMTok: number | null;
    outputPricePerMTok: number | null;
    vision: boolean;
    outputModalities: string[];
    tools: boolean;
    description: string;
    created: number | null;
    raw: Record<string, unknown>;
}

type SortKey =
    | 'name'
    | 'name_desc'
    | 'context'
    | 'context_asc'
    | 'price'
    | 'price_desc'
    | 'output_price'
    | 'newest'
    | 'oldest'
    | 'provider';

const open = defineModel<boolean>('open', { required: true });
const { t } = useI18n();

const loading = ref(false);
const loadError = ref<string | null>(null);
const saving = ref(false);
const models = ref<OpenRouterModel[]>([]);
const selected = ref<Set<string>>(new Set());

// ── Search / sort / filter state ────────────────────────────────────────────
const query = ref('');
const sortBy = ref<SortKey>('name');
const onlyVision = ref(false);
const onlyTools = ref(false);
const onlyFree = ref(false);
const onlySelected = ref(false);
const minContext = ref('0');
const selectedProviders = ref<Set<string>>(new Set());

const contextOptions = [
    { value: '0', label: t('admin.ai.providers.filter_ctx_any') },
    { value: '32000', label: '32K+' },
    { value: '128000', label: '128K+' },
    { value: '200000', label: '200K+' },
    { value: '1000000', label: '1M+' },
];

function family(model: OpenRouterModel): string {
    return model.id.split('/')[0] || 'other';
}

const families = computed(() => {
    const map = new Map<string, number>();
    for (const m of models.value) {
        const f = family(m);
        map.set(f, (map.get(f) ?? 0) + 1);
    }
    return [...map.entries()]
        .map(([id, count]) => ({ id, count }))
        .sort((a, b) => a.id.localeCompare(b.id));
});

function isFree(m: OpenRouterModel): boolean {
    return m.inputPricePerMTok === 0 && m.outputPricePerMTok === 0;
}

// Output modalities beyond plain text (image, audio, video…), used to mark each
// model by what it produces.
function outputTypes(m: OpenRouterModel): string[] {
    return (m.outputModalities ?? []).filter((o) => o !== 'text');
}

const INF = Number.POSITIVE_INFINITY;

const comparators: Record<
    SortKey,
    (a: OpenRouterModel, b: OpenRouterModel) => number
> = {
    name: (a, b) => a.label.localeCompare(b.label),
    name_desc: (a, b) => b.label.localeCompare(a.label),
    context: (a, b) => (b.contextWindow ?? -1) - (a.contextWindow ?? -1),
    context_asc: (a, b) => (a.contextWindow ?? INF) - (b.contextWindow ?? INF),
    price: (a, b) =>
        (a.inputPricePerMTok ?? INF) - (b.inputPricePerMTok ?? INF),
    price_desc: (a, b) =>
        (b.inputPricePerMTok ?? -1) - (a.inputPricePerMTok ?? -1),
    output_price: (a, b) =>
        (a.outputPricePerMTok ?? INF) - (b.outputPricePerMTok ?? INF),
    newest: (a, b) => (b.created ?? 0) - (a.created ?? 0),
    oldest: (a, b) => (a.created ?? INF) - (b.created ?? INF),
    provider: (a, b) =>
        family(a).localeCompare(family(b)) || a.label.localeCompare(b.label),
};

const filtered = computed<OpenRouterModel[]>(() => {
    const q = query.value.trim().toLowerCase();
    const min = Number(minContext.value);
    const providerSet = selectedProviders.value;

    const list = models.value.filter((m) => {
        if (
            q &&
            !m.id.toLowerCase().includes(q) &&
            !m.label.toLowerCase().includes(q)
        ) {
            return false;
        }
        if (onlyVision.value && !m.vision) return false;
        if (onlyTools.value && !m.tools) return false;
        if (onlyFree.value && !isFree(m)) return false;
        if (onlySelected.value && !selected.value.has(m.id)) return false;
        if (providerSet.size && !providerSet.has(family(m))) return false;
        if (min > 0 && (m.contextWindow ?? 0) < min) return false;
        return true;
    });

    return list.sort(comparators[sortBy.value]);
});

const activeFilterCount = computed(
    () =>
        (onlyVision.value ? 1 : 0) +
        (onlyTools.value ? 1 : 0) +
        (onlyFree.value ? 1 : 0) +
        (onlySelected.value ? 1 : 0) +
        (minContext.value !== '0' ? 1 : 0) +
        selectedProviders.value.size,
);

function resetFilters() {
    query.value = '';
    onlyVision.value = false;
    onlyTools.value = false;
    onlyFree.value = false;
    onlySelected.value = false;
    minContext.value = '0';
    selectedProviders.value = new Set();
}

function toggleProvider(id: string) {
    const next = new Set(selectedProviders.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    selectedProviders.value = next;
}

// ── Formatting ──────────────────────────────────────────────────────────────
function formatContext(n: number | null): string | null {
    if (!n) return null;
    return n >= 1000 ? `${Math.round(n / 1000)}K` : String(n);
}

function formatPrice(
    input: number | null,
    output: number | null,
): string | null {
    if (input === null && output === null) return null;
    if (input === 0 && (output === 0 || output === null)) {
        return t('admin.ai.providers.price_free');
    }
    const fmt = (p: number | null) => (p === null ? '—' : `$${p.toFixed(2)}`);
    return `${fmt(input)} / ${fmt(output)} /1M`;
}

// ── Detail panel ────────────────────────────────────────────────────────────
const infoModel = ref<OpenRouterModel | null>(null);

function showInfo(model: OpenRouterModel) {
    infoModel.value = model;
}

function closeInfo() {
    infoModel.value = null;
}

// Keep the open detail panel pointing at the freshly loaded object.
watch(models, (list) => {
    if (infoModel.value) {
        infoModel.value =
            list.find((m) => m.id === infoModel.value?.id) ?? null;
    }
});

function raw(key: string): unknown {
    return infoModel.value?.raw?.[key] ?? null;
}

function asString(value: unknown): string {
    return value === null || value === undefined || value === ''
        ? '—'
        : String(value);
}

function formatCreated(value: number | null): string {
    if (!value) return '—';
    return new Date(value * 1000).toLocaleDateString();
}

/** Key facts shown as a label/value list. */
const infoSpecs = computed<{ label: string; value: string }[]>(() => {
    const m = infoModel.value;
    if (!m) return [];
    const arch = (m.raw.architecture ?? {}) as Record<string, unknown>;
    const top = (m.raw.top_provider ?? {}) as Record<string, unknown>;
    const join = (v: unknown) =>
        Array.isArray(v) && v.length ? v.join(', ') : '—';

    return [
        {
            label: t('admin.ai.providers.detail_context'),
            value: asString(formatContext(m.contextWindow)),
        },
        {
            label: t('admin.ai.providers.detail_max_output'),
            value: top.max_completion_tokens
                ? asString(formatContext(Number(top.max_completion_tokens)))
                : '—',
        },
        {
            label: t('admin.ai.providers.detail_input'),
            value: join(arch.input_modalities),
        },
        {
            label: t('admin.ai.providers.detail_output'),
            value: join(arch.output_modalities),
        },
        {
            label: t('admin.ai.providers.detail_tokenizer'),
            value: asString(arch.tokenizer),
        },
        {
            label: t('admin.ai.providers.detail_instruct'),
            value: asString(arch.instruct_type),
        },
        {
            label: t('admin.ai.providers.detail_moderated'),
            value:
                top.is_moderated === undefined || top.is_moderated === null
                    ? '—'
                    : top.is_moderated
                      ? t('common.yes')
                      : t('common.no'),
        },
        {
            label: t('admin.ai.providers.detail_created'),
            value: formatCreated(m.created),
        },
    ];
});

/** Every priced dimension OpenRouter reports, formatted by its unit. */
const infoPricing = computed<{ label: string; value: string }[]>(() => {
    const pricing = (infoModel.value?.raw.pricing ?? {}) as Record<
        string,
        unknown
    >;
    const defs: { key: string; label: string; unit: 'mtok' | 'each' }[] = [
        {
            key: 'prompt',
            label: t('admin.ai.providers.price_prompt'),
            unit: 'mtok',
        },
        {
            key: 'completion',
            label: t('admin.ai.providers.price_completion'),
            unit: 'mtok',
        },
        {
            key: 'internal_reasoning',
            label: t('admin.ai.providers.price_reasoning'),
            unit: 'mtok',
        },
        {
            key: 'input_cache_read',
            label: t('admin.ai.providers.price_cache_read'),
            unit: 'mtok',
        },
        {
            key: 'input_cache_write',
            label: t('admin.ai.providers.price_cache_write'),
            unit: 'mtok',
        },
        {
            key: 'request',
            label: t('admin.ai.providers.price_request'),
            unit: 'each',
        },
        {
            key: 'image',
            label: t('admin.ai.providers.price_image'),
            unit: 'each',
        },
        {
            key: 'web_search',
            label: t('admin.ai.providers.price_web_search'),
            unit: 'each',
        },
    ];

    return defs
        .map((d) => {
            const v = pricing[d.key];
            if (v === undefined || v === null || v === '') return null;
            const num = Number(v);
            if (Number.isNaN(num)) return null;
            if (num === 0)
                return {
                    label: d.label,
                    value: t('admin.ai.providers.price_free'),
                };
            const value =
                d.unit === 'mtok'
                    ? `$${(num * 1_000_000).toFixed(2)} /1M`
                    : `$${num.toFixed(4)}`;
            return { label: d.label, value };
        })
        .filter((row): row is { label: string; value: string } => row !== null);
});

const infoCapabilities = computed<string[]>(() => {
    const params = infoModel.value?.raw.supported_parameters;
    return Array.isArray(params) ? (params as string[]) : [];
});

// ── Data ──────────────────────────────────────────────────────────────────
async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const response = await fetch('/admin/ai/providers/openrouter/models', {
            headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        models.value = data.models ?? [];
        selected.value = new Set<string>(data.enabled ?? []);
        if (data.error) {
            loadError.value = data.error;
        }
    } catch {
        loadError.value = t('admin.ai.providers.models_load_failed');
    } finally {
        loading.value = false;
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        resetFilters();
        sortBy.value = 'name';
        infoModel.value = null;
        load();
    }
});

function toggle(id: string) {
    const next = new Set(selected.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    selected.value = next;
}

const allFilteredSelected = computed(
    () =>
        filtered.value.length > 0 &&
        filtered.value.every((m) => selected.value.has(m.id)),
);

const selectAllState = computed<boolean | 'indeterminate'>(() => {
    if (allFilteredSelected.value) return true;
    return filtered.value.some((m) => selected.value.has(m.id))
        ? 'indeterminate'
        : false;
});

function toggleAll() {
    const next = new Set(selected.value);
    if (allFilteredSelected.value) {
        filtered.value.forEach((m) => next.delete(m.id));
    } else {
        filtered.value.forEach((m) => next.add(m.id));
    }
    selected.value = next;
}

function save() {
    saving.value = true;
    const payload = models.value
        .filter((m) => selected.value.has(m.id))
        .map((m) => ({
            id: m.id,
            label: m.label,
            contextWindow: m.contextWindow,
            inputPricePerMTok: m.inputPricePerMTok,
            outputPricePerMTok: m.outputPricePerMTok,
        }));

    router.post(
        '/admin/ai/providers/openrouter/models',
        { models: payload },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

// Boolean filter toggles, rendered as a list (refs are reactive on their own).
const booleanFilters = [
    { key: 'vision', ref: onlyVision },
    { key: 'tools', ref: onlyTools },
    { key: 'free', ref: onlyFree },
    { key: 'selected', ref: onlySelected },
];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex h-[85vh] flex-col gap-4 rounded-sp-sm border-medium bg-navy sm:max-w-6xl"
        >
            <DialogHeader>
                <DialogTitle class="text-ink">
                    {{ t('admin.ai.providers.models_title') }}
                </DialogTitle>
                <DialogDescription class="text-ink-muted">
                    {{ t('admin.ai.providers.models_description') }}
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="loading"
                class="flex flex-1 items-center justify-center gap-2 text-xs text-ink-muted"
            >
                <Loader2 class="size-4 animate-spin" />
                {{ t('admin.ai.providers.models_loading') }}
            </div>

            <div
                v-else-if="loadError"
                class="rounded-xs border border-sp-danger/30 bg-sp-danger/10 px-3 py-4 text-xs text-sp-danger"
            >
                {{ loadError }}
            </div>

            <div v-else class="flex min-h-0 flex-1 gap-4">
                <!-- Left: filters + sort -->
                <aside
                    v-if="!infoModel"
                    class="flex w-56 shrink-0 flex-col gap-4 overflow-y-auto border-r border-soft pr-3"
                >
                    <div class="space-y-1.5">
                        <Label class="text-[11px] text-ink-muted">
                            {{ t('admin.ai.providers.sort_label') }}
                        </Label>
                        <Select v-model="sortBy">
                            <SelectTrigger
                                class="h-8 border-medium bg-surface text-xs"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="name">
                                    {{ t('admin.ai.providers.sort_name') }}
                                </SelectItem>
                                <SelectItem value="name_desc">
                                    {{ t('admin.ai.providers.sort_name_desc') }}
                                </SelectItem>
                                <SelectItem value="context">
                                    {{ t('admin.ai.providers.sort_context') }}
                                </SelectItem>
                                <SelectItem value="context_asc">
                                    {{
                                        t('admin.ai.providers.sort_context_asc')
                                    }}
                                </SelectItem>
                                <SelectItem value="price">
                                    {{ t('admin.ai.providers.sort_price') }}
                                </SelectItem>
                                <SelectItem value="price_desc">
                                    {{
                                        t('admin.ai.providers.sort_price_desc')
                                    }}
                                </SelectItem>
                                <SelectItem value="output_price">
                                    {{
                                        t(
                                            'admin.ai.providers.sort_output_price',
                                        )
                                    }}
                                </SelectItem>
                                <SelectItem value="newest">
                                    {{ t('admin.ai.providers.sort_newest') }}
                                </SelectItem>
                                <SelectItem value="oldest">
                                    {{ t('admin.ai.providers.sort_oldest') }}
                                </SelectItem>
                                <SelectItem value="provider">
                                    {{ t('admin.ai.providers.sort_provider') }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-1.5">
                        <Label class="text-[11px] text-ink-muted">
                            {{ t('admin.ai.providers.filter_context') }}
                        </Label>
                        <Select v-model="minContext">
                            <SelectTrigger
                                class="h-8 border-medium bg-surface text-xs"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in contextOptions"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-2">
                        <Label class="text-[11px] text-ink-muted">
                            {{ t('admin.ai.providers.filter_capabilities') }}
                        </Label>
                        <div
                            v-for="f in booleanFilters"
                            :key="f.key"
                            class="flex cursor-pointer items-center gap-2 text-xs text-ink"
                            @click="f.ref.value = !f.ref.value"
                        >
                            <Checkbox
                                :model-value="f.ref.value"
                                class="pointer-events-none"
                            />
                            {{ t(`admin.ai.providers.filter_${f.key}`) }}
                        </div>
                    </div>

                    <div class="min-h-0 space-y-2">
                        <Label class="text-[11px] text-ink-muted">
                            {{ t('admin.ai.providers.filter_provider') }}
                        </Label>
                        <div class="space-y-1.5">
                            <div
                                v-for="fam in families"
                                :key="fam.id"
                                class="flex cursor-pointer items-center gap-2 text-xs text-ink"
                                @click="toggleProvider(fam.id)"
                            >
                                <Checkbox
                                    :model-value="selectedProviders.has(fam.id)"
                                    class="pointer-events-none"
                                />
                                <span class="flex-1 truncate">{{
                                    fam.id
                                }}</span>
                                <span class="text-ink-subtle">{{
                                    fam.count
                                }}</span>
                            </div>
                        </div>
                    </div>
                </aside>

                <!-- Right: search + results -->
                <div class="flex min-w-0 flex-1 flex-col gap-2">
                    <div class="flex items-center gap-3">
                        <div class="relative flex-1">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-ink-subtle"
                            />
                            <Input
                                v-model="query"
                                :placeholder="
                                    t('admin.ai.providers.models_search')
                                "
                                class="h-8 border-medium bg-surface pl-8 text-xs"
                            />
                        </div>
                        <button
                            v-if="activeFilterCount > 0"
                            type="button"
                            class="shrink-0 text-[11px] text-accent-blue hover:underline"
                            @click="resetFilters"
                        >
                            {{ t('admin.ai.providers.filter_reset') }}
                        </button>
                        <span class="shrink-0 text-[11px] text-ink-subtle">
                            {{
                                t('admin.ai.providers.results_count', {
                                    count: filtered.length,
                                })
                            }}
                        </span>
                    </div>

                    <div
                        v-if="filtered.length > 0"
                        class="flex items-center gap-2 px-1 text-xs text-ink-muted"
                    >
                        <div
                            class="flex cursor-pointer items-center gap-2"
                            @click="toggleAll"
                        >
                            <Checkbox
                                :model-value="selectAllState"
                                class="pointer-events-none"
                            />
                            {{
                                allFilteredSelected
                                    ? t('admin.ai.providers.unselect_all')
                                    : t('admin.ai.providers.select_all')
                            }}
                        </div>
                        <span class="ml-auto text-ink-subtle">
                            {{
                                t('admin.ai.providers.models_selected', {
                                    count: selected.size,
                                })
                            }}
                        </span>
                    </div>

                    <div
                        v-if="filtered.length === 0"
                        class="flex flex-1 items-center justify-center rounded-xs border border-soft bg-white/[0.02] text-xs text-ink-muted"
                    >
                        {{ t('admin.ai.providers.models_empty') }}
                    </div>

                    <div
                        v-else
                        class="-mr-1 flex-1 space-y-1 overflow-y-auto pr-1"
                    >
                        <div
                            v-for="model in filtered"
                            :key="model.id"
                            class="flex cursor-pointer items-center gap-3 rounded-xs border bg-white/[0.02] px-3 py-2 transition-colors hover:border-accent-blue/30 hover:bg-white/[0.05]"
                            :class="
                                infoModel?.id === model.id
                                    ? 'border-accent-blue/60'
                                    : 'border-soft'
                            "
                            @click="toggle(model.id)"
                        >
                            <Checkbox
                                :model-value="selected.has(model.id)"
                                class="pointer-events-none"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5">
                                    <p class="truncate text-sm text-ink">
                                        {{ model.label }}
                                    </p>
                                    <span
                                        v-if="model.vision"
                                        class="shrink-0 rounded-pill bg-accent-blue/15 px-1.5 text-[9px] font-semibold tracking-wide text-accent-blue uppercase"
                                    >
                                        {{
                                            t('admin.ai.providers.badge_vision')
                                        }}
                                    </span>
                                    <span
                                        v-if="model.tools"
                                        class="bg-sp-spectrum-magenta/15 text-sp-spectrum-magenta shrink-0 rounded-pill px-1.5 text-[9px] font-semibold tracking-wide uppercase"
                                    >
                                        {{
                                            t('admin.ai.providers.badge_tools')
                                        }}
                                    </span>
                                    <span
                                        v-for="out in outputTypes(model)"
                                        :key="out"
                                        class="bg-sp-spectrum-cyan/15 text-sp-spectrum-cyan shrink-0 rounded-pill px-1.5 text-[9px] font-semibold tracking-wide uppercase"
                                        :title="
                                            t('admin.ai.providers.badge_output')
                                        "
                                    >
                                        {{ out }}
                                    </span>
                                </div>
                                <p
                                    class="truncate font-mono text-[11px] text-ink-subtle"
                                >
                                    {{ model.id }}
                                </p>
                            </div>
                            <div
                                class="shrink-0 text-right text-[11px] leading-tight"
                            >
                                <p
                                    v-if="formatContext(model.contextWindow)"
                                    class="text-ink-muted"
                                >
                                    {{ formatContext(model.contextWindow) }}
                                </p>
                                <p
                                    v-if="
                                        formatPrice(
                                            model.inputPricePerMTok,
                                            model.outputPricePerMTok,
                                        )
                                    "
                                    class="text-ink-subtle"
                                >
                                    {{
                                        formatPrice(
                                            model.inputPricePerMTok,
                                            model.outputPricePerMTok,
                                        )
                                    }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 rounded-xs p-1 text-ink-subtle transition-colors hover:bg-surface hover:text-accent-blue"
                                :title="t('admin.ai.providers.detail_info')"
                                @click.stop="showInfo(model)"
                            >
                                <Info class="size-3.5" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Detail panel -->
                <aside
                    v-if="infoModel"
                    class="flex w-96 shrink-0 flex-col gap-4 overflow-y-auto border-l border-soft pl-4"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">
                                {{ infoModel.label }}
                            </p>
                            <p
                                class="truncate font-mono text-[11px] text-ink-subtle"
                            >
                                {{ infoModel.id }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 rounded-xs p-1 text-ink-subtle hover:bg-surface hover:text-ink"
                            :title="t('common.close')"
                            @click="closeInfo"
                        >
                            <X class="size-4" />
                        </button>
                    </div>

                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            selected.has(infoModel.id) ? 'default' : 'outline'
                        "
                        :class="
                            selected.has(infoModel.id)
                                ? 'gap-1.5 bg-accent-blue text-white hover:bg-accent-blue-hover'
                                : 'gap-1.5 border-medium bg-surface'
                        "
                        @click="toggle(infoModel.id)"
                    >
                        {{
                            selected.has(infoModel.id)
                                ? t('admin.ai.providers.detail_enabled')
                                : t('admin.ai.providers.detail_enable')
                        }}
                    </Button>

                    <p
                        v-if="infoModel.description"
                        class="text-xs leading-relaxed whitespace-pre-line text-ink-muted"
                    >
                        {{ infoModel.description }}
                    </p>

                    <a
                        :href="`https://openrouter.ai/${infoModel.id}`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1.5 text-xs text-accent-blue hover:underline"
                    >
                        <ExternalLink class="size-3.5" />
                        {{ t('admin.ai.providers.detail_openrouter_link') }}
                    </a>

                    <!-- Specs -->
                    <div class="space-y-1.5">
                        <p
                            class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                        >
                            {{ t('admin.ai.providers.detail_specs') }}
                        </p>
                        <dl class="space-y-1">
                            <div
                                v-for="row in infoSpecs"
                                :key="row.label"
                                class="flex items-baseline justify-between gap-3 text-xs"
                            >
                                <dt class="text-ink-subtle">{{ row.label }}</dt>
                                <dd class="text-right font-mono text-ink">
                                    {{ row.value }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Pricing -->
                    <div v-if="infoPricing.length" class="space-y-1.5">
                        <p
                            class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                        >
                            {{ t('admin.ai.providers.detail_pricing') }}
                        </p>
                        <dl class="space-y-1">
                            <div
                                v-for="row in infoPricing"
                                :key="row.label"
                                class="flex items-baseline justify-between gap-3 text-xs"
                            >
                                <dt class="text-ink-subtle">{{ row.label }}</dt>
                                <dd class="text-right font-mono text-ink">
                                    {{ row.value }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Capabilities -->
                    <div v-if="infoCapabilities.length" class="space-y-1.5">
                        <p
                            class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                        >
                            {{ t('admin.ai.providers.detail_capabilities') }}
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="param in infoCapabilities"
                                :key="param"
                                class="rounded-pill border border-soft bg-white/[0.03] px-2 py-0.5 font-mono text-[10px] text-ink-muted"
                            >
                                {{ param }}
                            </span>
                        </div>
                    </div>

                    <!-- Meta -->
                    <div class="space-y-1.5">
                        <p
                            class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                        >
                            {{ t('admin.ai.providers.detail_meta') }}
                        </p>
                        <dl class="space-y-1">
                            <div
                                class="flex items-baseline justify-between gap-3 text-xs"
                            >
                                <dt class="text-ink-subtle">
                                    {{
                                        t('admin.ai.providers.detail_canonical')
                                    }}
                                </dt>
                                <dd
                                    class="truncate text-right font-mono text-ink"
                                >
                                    {{ asString(raw('canonical_slug')) }}
                                </dd>
                            </div>
                            <div
                                class="flex items-baseline justify-between gap-3 text-xs"
                            >
                                <dt class="text-ink-subtle">
                                    {{ t('admin.ai.providers.detail_hf') }}
                                </dt>
                                <dd
                                    class="truncate text-right font-mono text-ink"
                                >
                                    {{ asString(raw('hugging_face_id')) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </aside>
            </div>

            <DialogFooter
                class="items-center justify-between gap-2 sm:justify-between"
            >
                <span class="text-[11px] text-ink-subtle">
                    {{
                        t('admin.ai.providers.models_selected', {
                            count: selected.size,
                        })
                    }}
                </span>
                <div class="flex items-center gap-2">
                    <Button type="button" variant="ghost" @click="open = false">
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        :disabled="saving || loading"
                        class="rounded-pill bg-accent-blue text-white shadow-btn-primary hover:bg-accent-blue-hover"
                        @click="save"
                    >
                        {{ t('admin.ai.providers.models_save') }}
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
