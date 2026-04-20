<script setup lang="ts">
import AiTabs from '@/components/admin-v2/AiTabs.vue';
import DriverChip from '@/components/admin-v2/DriverChip.vue';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AdminV2Layout from '@/layouts/AdminV2Layout.vue';
import type { AiModel } from '@/lib/admin/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{ models: AiModel[] }>();
const { t } = useI18n();

const filter = ref<'all' | 'chat' | 'embedding'>('all');

const rows = computed(() => {
    if (filter.value === 'all') return props.models;
    return props.models.filter((m) => m.kind === filter.value);
});

function toggle(model: AiModel, next: boolean) {
    router.patch(
        `/admin2/ai/catalog/${model.id}`,
        { enabled: next },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['models'],
        },
    );
}
</script>

<template>
    <Head :title="t('admin_v2.nav.ai')" />

    <AdminV2Layout :title="t('admin_v2.nav.ai')">
        <div class="space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] font-semibold leading-tight text-ink">
                    {{ t('admin_v2.ai.heading') }}
                </h1>
                <p class="text-xs text-ink-muted">
                    {{ t('admin_v2.ai.catalog.description') }}
                </p>
            </header>

            <AiTabs current="catalog" />

            <div class="flex items-center justify-end gap-4">
                <ToggleGroup v-model="filter" type="single" class="gap-0.5">
                    <ToggleGroupItem value="all" class="h-8 rounded-pill px-3 text-xs">
                        {{ t('admin_v2.ai.catalog.filter.all') }}
                    </ToggleGroupItem>
                    <ToggleGroupItem value="chat" class="h-8 rounded-pill px-3 text-xs">
                        {{ t('admin_v2.ai.catalog.filter.chat') }}
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="embedding"
                        class="h-8 rounded-pill px-3 text-xs"
                    >
                        {{ t('admin_v2.ai.catalog.filter.embedding') }}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <div class="overflow-hidden rounded-sp-sm border border-soft bg-navy">
                <Table>
                    <TableHeader>
                        <TableRow class="border-soft hover:bg-transparent">
                            <TableHead
                                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin_v2.ai.catalog.col.driver') }}
                            </TableHead>
                            <TableHead
                                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin_v2.ai.catalog.col.model') }}
                            </TableHead>
                            <TableHead
                                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin_v2.ai.catalog.col.kind') }}
                            </TableHead>
                            <TableHead
                                class="text-right text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin_v2.ai.catalog.col.enabled') }}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty v-if="rows.length === 0" :colspan="4">
                            {{ t('admin_v2.ai.catalog.empty') }}
                        </TableEmpty>
                        <TableRow
                            v-for="m in rows"
                            :key="m.id"
                            class="border-soft transition-colors hover:bg-white/5"
                        >
                            <TableCell>
                                <DriverChip :driver="m.driver" size="sm" />
                            </TableCell>
                            <TableCell>
                                <div class="flex flex-col">
                                    <span class="font-mono text-xs text-ink">{{
                                        m.name
                                    }}</span>
                                    <span class="text-xs text-ink-muted">{{
                                        m.label
                                    }}</span>
                                </div>
                            </TableCell>
                            <TableCell class="text-xs text-ink-muted capitalize">
                                {{ m.kind }}
                            </TableCell>
                            <TableCell class="text-right">
                                <Switch
                                    :model-value="m.enabled"
                                    class="data-[state=checked]:bg-accent-blue"
                                    @update:model-value="(v: boolean) => toggle(m, v)"
                                />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AdminV2Layout>
</template>
