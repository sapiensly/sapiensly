<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Database, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * What each module can be built out of.
 *
 * Everything on this page is derived — the manifest schema decides which app
 * blocks exist, the dashboard planner which of them a dashboard may use, the
 * flow reference what a conversation is made of. Nothing here is a list kept by
 * hand, so the page cannot advertise a component the platform would refuse.
 */
interface ComponentEntry {
    type: string;
    name: string;
    description: string;
    family: string;
    needs_data: boolean;
}

type ModuleKey = 'chat' | 'apps' | 'dashboards';

const props = defineProps<{ modules: Record<ModuleKey, ComponentEntry[]> }>();

const { t } = useI18n();

const MODULES: ModuleKey[] = ['chat', 'apps', 'dashboards'];

const module = ref<ModuleKey>('apps');
const query = ref('');
const family = ref('');
const dataOnly = ref('');
const sortBy = ref<'name' | 'family'>('name');
const sortDir = ref<'asc' | 'desc'>('asc');

const entries = computed<ComponentEntry[]>(
    () => props.modules[module.value] ?? [],
);

/** Only the families this module actually has — an empty filter chip is noise. */
const families = computed(() =>
    [...new Set(entries.value.map((e) => e.family))].sort(),
);

function fold(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

const visible = computed(() => {
    const needle = fold(query.value.trim());

    const filtered = entries.value.filter((entry) => {
        if (family.value !== '' && entry.family !== family.value) return false;
        if (dataOnly.value === 'data' && !entry.needs_data) return false;
        if (dataOnly.value === 'standalone' && entry.needs_data) return false;
        if (needle === '') return true;

        // The description is searched too: an author looking for "kanban" may
        // well type "board", and the authoring note is where that word lives.
        return (
            fold(entry.name).includes(needle) ||
            fold(entry.type).includes(needle) ||
            fold(entry.description).includes(needle)
        );
    });

    const dir = sortDir.value === 'desc' ? -1 : 1;

    return [...filtered].sort((a, b) => {
        if (sortBy.value === 'family' && a.family !== b.family) {
            return a.family.localeCompare(b.family) * dir;
        }

        return a.name.localeCompare(b.name) * dir;
    });
});

function toggleSort(key: 'name' | 'family'): void {
    if (sortBy.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';

        return;
    }
    sortBy.value = key;
    sortDir.value = 'asc';
}

/** Switching module resets the filters: a family that exists in one module
 *  usually does not in the next, and a filter you cannot see is a filter that
 *  hides rows for no visible reason. */
// Tabs emits StringOrNumber: its `value` is typed for numeric tabs too, even
// though every value here is a ModuleKey.
function selectModule(next: string | number | undefined): void {
    if (next === undefined) return;
    module.value = String(next) as ModuleKey;
    family.value = '';
    dataOnly.value = '';
}
</script>

<template>
    <Head :title="t('admin.components.title')" />

    <AdminLayout :title="t('admin.components.title')">
        <div class="space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] leading-tight font-semibold text-ink">
                    {{ t('admin.components.title') }}
                </h1>
                <p class="max-w-3xl text-xs text-ink-muted">
                    {{ t('admin.components.subtitle') }}
                </p>
            </header>

            <Tabs :model-value="module" @update:model-value="selectModule">
                <TabsList class="grid w-full max-w-md grid-cols-3">
                    <TabsTrigger
                        v-for="key in MODULES"
                        :key="key"
                        :value="key"
                        class="gap-1.5 text-xs"
                    >
                        {{ t('admin.components.tab.' + key) }}
                        <span class="text-ink-subtle">{{
                            (modules[key] ?? []).length
                        }}</span>
                    </TabsTrigger>
                </TabsList>
            </Tabs>

            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-full max-w-xs flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-ink-subtle"
                    />
                    <Input
                        v-model="query"
                        type="search"
                        :placeholder="t('admin.components.search')"
                        class="h-8 border-medium bg-surface pl-8 text-xs"
                    />
                </div>

                <span class="text-[11px] text-ink-subtle">
                    {{
                        t('admin.components.count', {
                            n: visible.length,
                            total: entries.length,
                        })
                    }}
                </span>

                <ToggleGroup v-model="dataOnly" type="single" class="gap-0.5">
                    <ToggleGroupItem
                        value=""
                        class="h-8 rounded-pill px-3 text-xs"
                    >
                        {{ t('admin.components.all') }}
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="data"
                        class="h-8 rounded-pill px-3 text-xs"
                    >
                        {{ t('admin.components.needs_data') }}
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="standalone"
                        class="h-8 rounded-pill px-3 text-xs"
                    >
                        {{ t('admin.components.standalone') }}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <ToggleGroup v-model="family" type="single" class="gap-0.5">
                    <ToggleGroupItem
                        value=""
                        class="h-7 rounded-pill px-2.5 text-[11px]"
                    >
                        {{ t('admin.components.all') }}
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        v-for="f in families"
                        :key="f"
                        :value="f"
                        class="h-7 rounded-pill px-2.5 text-[11px]"
                    >
                        {{ t('admin.components.family.' + f) }}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <div class="overflow-hidden rounded-sp-sm border border-soft">
                <div class="relative w-full overflow-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-soft bg-surface-hover">
                                <th
                                    v-for="col in ['name', 'family'] as const"
                                    :key="col"
                                    class="px-3 py-2 text-left text-[11px] font-medium tracking-wider text-ink-muted uppercase"
                                    :class="col === 'family' ? 'w-40' : ''"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 uppercase transition-opacity hover:opacity-70"
                                        @click="toggleSort(col)"
                                    >
                                        {{ t('admin.components.sort.' + col) }}
                                        <ArrowUp
                                            v-if="
                                                sortBy === col &&
                                                sortDir === 'asc'
                                            "
                                            class="size-3"
                                        />
                                        <ArrowDown
                                            v-else-if="sortBy === col"
                                            class="size-3"
                                        />
                                    </button>
                                </th>
                                <th
                                    class="px-3 py-2 text-left text-[11px] font-medium tracking-wider text-ink-muted uppercase"
                                >
                                    {{ t('admin.components.description') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-soft">
                            <tr
                                v-for="entry in visible"
                                :key="entry.type"
                                class="align-top transition-colors hover:bg-surface-hover"
                            >
                                <td class="px-3 py-2.5">
                                    <div class="font-medium text-ink">
                                        {{ entry.name }}
                                    </div>
                                    <code class="text-[11px] text-ink-subtle">{{
                                        entry.type
                                    }}</code>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-pill bg-surface px-2 py-0.5 text-[11px] text-ink-muted"
                                    >
                                        {{
                                            t(
                                                'admin.components.family.' +
                                                    entry.family,
                                            )
                                        }}
                                    </span>
                                    <span
                                        v-if="entry.needs_data"
                                        class="mt-1 inline-flex items-center gap-1 text-[11px] text-ink-subtle"
                                        :title="
                                            t('admin.components.needs_data')
                                        "
                                    >
                                        <Database class="size-3" />
                                        {{ t('admin.components.needs_data') }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-xs text-ink-muted">
                                    {{ entry.description }}
                                </td>
                            </tr>
                            <tr v-if="visible.length === 0">
                                <td
                                    colspan="3"
                                    class="px-3 py-6 text-center text-xs text-ink-subtle"
                                >
                                    {{ t('admin.components.none') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
