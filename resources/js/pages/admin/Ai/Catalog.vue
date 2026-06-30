<script setup lang="ts">
import AiTabs from '@/components/admin/AiTabs.vue';
import DriverChip from '@/components/admin/DriverChip.vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AdminLayout from '@/layouts/AdminLayout.vue';
import {
    Activity,
    AlertTriangle,
    Check,
    Loader2,
    Pencil,
    Search,
} from '@/lib/admin/icons';
import type { AiModel } from '@/lib/admin/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * The catalog endpoint also serialises an editable `label` (broker rows can
 * rename their model) which the shared `AiModel` type does not declare.
 */
interface CatalogModel extends AiModel {
    label: string;
}

const props = defineProps<{ models: CatalogModel[] }>();
const { t } = useI18n();

const filter = ref<'all' | 'chat' | 'embedding'>('all');
const activeTab = ref<'direct' | 'broker'>('direct');
const search = reactive<{ direct: string; broker: string }>({
    direct: '',
    broker: '',
});

// Extra filters. Connected defaults to hiding models whose provider has no key.
const providerFilter = ref<string>('all');
const enabledFilter = ref<'all' | 'enabled' | 'disabled'>('all');
const connectedFilter = ref<'all' | 'connected' | 'disconnected'>('connected');

// Reset the provider picker when switching tabs — its options are tab-scoped.
watch(activeTab, () => {
    providerFilter.value = 'all';
});

const kindRows = computed(() => {
    if (filter.value === 'all') return props.models;
    return props.models.filter((m) => m.kind === filter.value);
});

const directRows = computed(() =>
    kindRows.value.filter((m) => m.providerKind === 'direct'),
);
const brokerRows = computed(() =>
    kindRows.value.filter((m) => m.providerKind === 'broker'),
);

// Drivers present in the active tab (ignores the other filters so the
// dropdown stays stable).
const availableDrivers = computed(() => [
    ...new Set(
        props.models
            .filter((m) => m.providerKind === activeTab.value)
            .map((m) => m.driver),
    ),
]);

function matches(model: CatalogModel, query: string): boolean {
    const q = query.trim().toLowerCase();
    if (!q) return true;
    return (
        model.name.toLowerCase().includes(q) ||
        model.label.toLowerCase().includes(q) ||
        model.driver.toLowerCase().includes(q)
    );
}

const visibleRows = computed(() => {
    const base =
        activeTab.value === 'direct' ? directRows.value : brokerRows.value;
    return base.filter((m) => {
        if (!matches(m, search[activeTab.value])) return false;
        if (providerFilter.value !== 'all' && m.driver !== providerFilter.value)
            return false;
        if (enabledFilter.value === 'enabled' && !m.enabled) return false;
        if (enabledFilter.value === 'disabled' && m.enabled) return false;
        if (connectedFilter.value === 'connected' && !m.providerConfigured)
            return false;
        if (connectedFilter.value === 'disconnected' && m.providerConfigured)
            return false;
        return true;
    });
});

function formatContext(n: number | null): string {
    if (!n) return '—';
    return n >= 1000 ? `${Math.round(n / 1000)}K` : String(n);
}

function formatPrice(input: number | null, output: number | null): string {
    if (input === null && output === null) return '—';
    if (input === 0 && (output === 0 || output === null)) {
        return t('admin.ai.providers.price_free');
    }
    const fmt = (p: number | null) => (p === null ? '—' : `$${p.toFixed(2)}`);
    return `${fmt(input)} / ${fmt(output)}`;
}

function toggle(model: AiModel, next: boolean) {
    router.patch(
        `/admin/ai/catalog/${model.id}`,
        { enabled: next },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['models'],
        },
    );
}

// ── Per-model invocation test ────────────────────────────────────────────────
type TestResult = { success: boolean; message: string; detail?: string };
const testing = reactive<Record<string, boolean>>({});
const testResult = reactive<Record<string, TestResult | null>>({});

async function testModel(model: AiModel) {
    testing[model.id] = true;
    testResult[model.id] = null;

    try {
        const response = await fetch(`/admin/ai/catalog/${model.id}/test`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
        });
        testResult[model.id] = await response.json();
    } catch {
        testResult[model.id] = {
            success: false,
            message: t('admin.ai.catalog.test_failed'),
        };
    } finally {
        testing[model.id] = false;
    }

    const id = model.id;
    const delay = testResult[id]?.success ? 5000 : 15000;
    setTimeout(() => {
        testResult[id] = null;
    }, delay);
}

// ── Inline label editing (brokers) ──────────────────────────────────────────
const editingId = ref<string | null>(null);
const editLabel = ref('');

function startEdit(model: CatalogModel) {
    editingId.value = model.id;
    editLabel.value = model.label;
}

// Function ref: focus + select the input as soon as it mounts.
function focusLabelInput(el: unknown) {
    if (el instanceof HTMLInputElement) {
        el.focus();
        el.select();
    }
}

function cancelEdit() {
    editingId.value = null;
}

function saveEdit(model: CatalogModel) {
    if (editingId.value !== model.id) return;
    const next = editLabel.value.trim();
    editingId.value = null;
    if (!next || next === model.label) return;
    router.patch(
        `/admin/ai/catalog/${model.id}`,
        { label: next },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['models'],
        },
    );
}
</script>

<template>
    <Head :title="t('admin.nav.ai')" />

    <AdminLayout :title="t('admin.nav.ai')">
        <div class="space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] leading-tight font-semibold text-ink">
                    {{ t('admin.ai.heading') }}
                </h1>
                <p class="text-xs text-ink-muted">
                    {{ t('admin.ai.catalog.description') }}
                </p>
            </header>

            <AiTabs current="catalog" />

            <Tabs v-model="activeTab">
                <TabsList class="grid w-full max-w-xs grid-cols-2">
                    <TabsTrigger value="direct" class="gap-1.5 text-xs">
                        {{ t('admin.ai.catalog.direct_title') }}
                        <span class="text-ink-subtle">{{
                            directRows.length
                        }}</span>
                    </TabsTrigger>
                    <TabsTrigger value="broker" class="gap-1.5 text-xs">
                        {{ t('admin.ai.catalog.broker_title') }}
                        <span class="text-ink-subtle">{{
                            brokerRows.length
                        }}</span>
                    </TabsTrigger>
                </TabsList>
            </Tabs>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="relative w-full max-w-xs">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-ink-subtle"
                    />
                    <Input
                        v-model="search[activeTab]"
                        :placeholder="t('admin.ai.catalog.search')"
                        class="h-8 border-medium bg-surface pl-8 text-xs"
                    />
                </div>

                <ToggleGroup v-model="filter" type="single" class="gap-0.5">
                    <ToggleGroupItem
                        value="all"
                        class="h-8 rounded-pill px-3 text-xs"
                    >
                        {{ t('admin.ai.catalog.filter.all') }}
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="chat"
                        class="h-8 rounded-pill px-3 text-xs"
                    >
                        {{ t('admin.ai.catalog.filter.chat') }}
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="embedding"
                        class="h-8 rounded-pill px-3 text-xs"
                    >
                        {{ t('admin.ai.catalog.filter.embedding') }}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <!-- Provider -->
                <Select v-model="providerFilter">
                    <SelectTrigger
                        class="h-8 w-auto min-w-[140px] border-medium bg-surface text-xs"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">
                            {{ t('admin.ai.catalog.filter.all_providers') }}
                        </SelectItem>
                        <SelectItem
                            v-for="d in availableDrivers"
                            :key="d"
                            :value="d"
                        >
                            <span class="inline-flex items-center gap-2">
                                <DriverChip :driver="d" size="sm" />
                                {{ d }}
                            </span>
                        </SelectItem>
                    </SelectContent>
                </Select>

                <!-- Enabled -->
                <Select v-model="enabledFilter">
                    <SelectTrigger
                        class="h-8 w-auto min-w-[120px] border-medium bg-surface text-xs"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">
                            {{ t('admin.ai.catalog.filter.enabled_any') }}
                        </SelectItem>
                        <SelectItem value="enabled">
                            {{ t('admin.ai.catalog.filter.enabled_only') }}
                        </SelectItem>
                        <SelectItem value="disabled">
                            {{ t('admin.ai.catalog.filter.disabled_only') }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <!-- Connected -->
                <Select v-model="connectedFilter">
                    <SelectTrigger
                        class="h-8 w-auto min-w-[150px] border-medium bg-surface text-xs"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">
                            {{ t('admin.ai.catalog.filter.connected_any') }}
                        </SelectItem>
                        <SelectItem value="connected">
                            {{ t('admin.ai.catalog.filter.connected_only') }}
                        </SelectItem>
                        <SelectItem value="disconnected">
                            {{ t('admin.ai.catalog.filter.disconnected_only') }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div
                class="overflow-hidden rounded-sp-sm border border-soft bg-navy"
            >
                <Table>
                    <TableHeader>
                        <TableRow class="border-soft hover:bg-transparent">
                            <TableHead
                                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin.ai.catalog.col.driver') }}
                            </TableHead>
                            <TableHead
                                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin.ai.catalog.col.model') }}
                            </TableHead>
                            <TableHead
                                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin.ai.catalog.col.kind') }}
                            </TableHead>
                            <TableHead
                                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin.ai.catalog.col.context') }}
                            </TableHead>
                            <TableHead
                                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin.ai.catalog.col.price') }}
                            </TableHead>
                            <TableHead
                                class="text-right text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin.ai.catalog.col.enabled') }}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty
                            v-if="visibleRows.length === 0"
                            :colspan="6"
                        >
                            {{ t('admin.ai.catalog.empty') }}
                        </TableEmpty>
                        <TableRow
                            v-for="m in visibleRows"
                            :key="m.id"
                            class="border-soft transition-colors hover:bg-surface"
                        >
                            <TableCell>
                                <DriverChip :driver="m.driver" size="sm" />
                            </TableCell>
                            <TableCell>
                                <div class="flex flex-col">
                                    <span class="font-mono text-xs text-ink">{{
                                        m.name
                                    }}</span>
                                    <input
                                        v-if="editingId === m.id"
                                        :ref="focusLabelInput"
                                        v-model="editLabel"
                                        type="text"
                                        class="mt-0.5 w-full max-w-xs rounded-xs border border-medium bg-surface px-1.5 py-0.5 text-xs text-ink outline-none focus:border-accent-blue"
                                        @keyup.enter="saveEdit(m)"
                                        @keyup.esc="cancelEdit"
                                        @blur="saveEdit(m)"
                                    />
                                    <span
                                        v-else
                                        class="group flex items-center gap-1 text-xs text-ink-muted"
                                    >
                                        {{ m.label }}
                                        <button
                                            v-if="m.providerKind === 'broker'"
                                            type="button"
                                            class="rounded-xs p-0.5 text-ink-subtle opacity-0 transition-opacity group-hover:opacity-100 hover:text-accent-blue"
                                            :title="
                                                t('admin.ai.catalog.edit_name')
                                            "
                                            @click="startEdit(m)"
                                        >
                                            <Pencil class="size-3" />
                                        </button>
                                    </span>
                                </div>
                            </TableCell>
                            <TableCell
                                class="text-xs text-ink-muted capitalize"
                            >
                                {{ m.kind }}
                            </TableCell>
                            <TableCell class="font-mono text-xs text-ink-muted">
                                {{ formatContext(m.contextWindow) }}
                            </TableCell>
                            <TableCell class="font-mono text-xs text-ink-muted">
                                {{
                                    formatPrice(
                                        m.inputPricePerMTok,
                                        m.outputPricePerMTok,
                                    )
                                }}
                            </TableCell>
                            <TableCell class="text-right">
                                <div
                                    class="inline-flex items-center justify-end gap-2"
                                >
                                    <span
                                        v-if="!m.providerConfigured"
                                        class="text-[10px] whitespace-nowrap text-amber-500"
                                        :title="
                                            t(
                                                'admin.ai.catalog.provider_not_connected_hint',
                                            )
                                        "
                                    >
                                        {{
                                            t(
                                                'admin.ai.catalog.provider_not_connected',
                                            )
                                        }}
                                    </span>
                                    <span
                                        v-if="
                                            m.providerConfigured &&
                                            testResult[m.id]
                                        "
                                        class="inline-flex items-center gap-1 text-[10px]"
                                        :class="
                                            testResult[m.id]?.success
                                                ? 'text-emerald-500'
                                                : 'text-amber-500'
                                        "
                                        :title="
                                            testResult[m.id]?.detail ??
                                            testResult[m.id]?.message
                                        "
                                    >
                                        <component
                                            :is="
                                                testResult[m.id]?.success
                                                    ? Check
                                                    : AlertTriangle
                                            "
                                            class="size-3"
                                        />
                                        {{ testResult[m.id]?.message }}
                                    </span>
                                    <button
                                        v-if="m.providerConfigured"
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-xs border border-medium bg-surface px-1.5 py-0.5 text-[10px] text-ink-muted transition-colors hover:text-accent-blue disabled:opacity-50"
                                        :disabled="testing[m.id]"
                                        :title="t('admin.ai.catalog.test_hint')"
                                        @click="testModel(m)"
                                    >
                                        <component
                                            :is="
                                                testing[m.id]
                                                    ? Loader2
                                                    : Activity
                                            "
                                            class="size-3"
                                            :class="
                                                testing[m.id]
                                                    ? 'animate-spin'
                                                    : ''
                                            "
                                        />
                                        {{ t('admin.ai.catalog.test_cta') }}
                                    </button>
                                    <Switch
                                        :model-value="m.enabled"
                                        :disabled="
                                            !m.providerConfigured && !m.enabled
                                        "
                                        class="data-[state=checked]:bg-accent-blue"
                                        @update:model-value="
                                            (v: boolean) => toggle(m, v)
                                        "
                                    />
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AdminLayout>
</template>
