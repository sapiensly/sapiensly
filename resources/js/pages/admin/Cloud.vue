<script setup lang="ts">
import CapacityBar from '@/components/admin/CapacityBar.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Cpu, Database, HardDrive } from '@/lib/admin/icons';
import type { CloudProps } from '@/lib/admin/types';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<CloudProps>();
const { t } = useI18n();

function bytes(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';
    if (value < 1024) return `${value} B`;
    const units = ['KB', 'MB', 'GB', 'TB', 'PB'];
    let v = value / 1024;
    for (const u of units) {
        if (v < 1024) return `${v.toFixed(1)} ${u}`;
        v /= 1024;
    }
    return `${v.toFixed(1)} EB`;
}

const connectionPressure = computed(() => {
    if (!props.database || !props.database.connections.max) return null;
    return (
        (props.database.connections.active / props.database.connections.max) *
        100
    );
});

const connectionClass = computed(() => {
    if (connectionPressure.value === null) return 'text-ink-subtle';
    if (connectionPressure.value > 80) return 'text-sp-danger';
    if (connectionPressure.value > 60) return 'text-sp-warning';
    return 'text-sp-success';
});
</script>

<template>
    <Head :title="t('admin.nav.cloud')" />

    <AdminLayout :title="t('admin.nav.cloud')">
        <div class="space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] font-semibold leading-tight text-ink">
                    {{ t('admin.cloud.heading') }}
                </h1>
                <p class="text-xs text-ink-muted">
                    {{ t('admin.cloud.description') }}
                </p>
            </header>

            <!-- Storage + Database columns per layout_spec §5. -->
            <div class="grid gap-4 xl:grid-cols-2">
                <SettingsCard
                    :icon="HardDrive"
                    :title="t('admin.cloud.storage.title')"
                    :description="t('admin.cloud.storage.description')"
                >
                    <template v-if="storage">
                        <dl class="space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <dt class="text-xs text-ink-muted">
                                    {{ t('admin.cloud.storage.driver') }}
                                </dt>
                                <dd
                                    class="font-mono text-xs text-ink uppercase"
                                >
                                    {{ storage.driver }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-xs text-ink-muted">
                                    {{ t('admin.cloud.storage.bucket') }}
                                </dt>
                                <dd class="font-mono text-xs text-ink">
                                    {{ storage.bucket }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-xs text-ink-muted">
                                    {{ t('admin.cloud.storage.region') }}
                                </dt>
                                <dd class="font-mono text-xs text-ink">
                                    {{ storage.region }}
                                </dd>
                            </div>
                        </dl>

                        <div class="pt-1">
                            <p class="mb-1.5 text-[10px] tracking-wider text-ink-subtle uppercase">
                                {{ t('admin.cloud.storage.capacity') }}
                            </p>
                            <CapacityBar
                                :used="storage.usedBytes"
                                :total="storage.totalBytes"
                            />
                            <p
                                v-if="storage.usedBytes === null"
                                class="mt-2 text-[10px] text-ink-subtle"
                            >
                                {{ t('admin.cloud.storage.capacity_hint') }}
                            </p>
                        </div>
                    </template>
                    <p
                        v-else
                        class="rounded-xs border border-dashed border-soft p-3 text-xs text-ink-muted"
                    >
                        {{ t('admin.cloud.storage.empty') }}
                    </p>
                </SettingsCard>

                <SettingsCard
                    :icon="Database"
                    :title="t('admin.cloud.database.title')"
                    :description="t('admin.cloud.database.description')"
                    tint="var(--sp-accent-cyan)"
                >
                    <template v-if="database">
                        <dl class="space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <dt class="text-xs text-ink-muted">
                                    {{ t('admin.cloud.database.engine') }}
                                </dt>
                                <dd class="font-mono text-xs text-ink">
                                    {{ database.engine }} {{ database.version }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-xs text-ink-muted">
                                    {{ t('admin.cloud.database.host') }}
                                </dt>
                                <dd class="truncate font-mono text-xs text-ink">
                                    {{ database.host }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-xs text-ink-muted">
                                    {{ t('admin.cloud.database.size') }}
                                </dt>
                                <dd class="font-mono text-xs text-ink">
                                    {{ bytes(database.sizeBytes) }}
                                </dd>
                            </div>
                        </dl>

                        <div class="pt-1">
                            <div class="mb-1.5 flex items-center justify-between">
                                <p
                                    class="text-[10px] tracking-wider text-ink-subtle uppercase"
                                >
                                    {{ t('admin.cloud.database.connections') }}
                                </p>
                                <p class="font-mono text-xs" :class="connectionClass">
                                    {{ database.connections.active }} /
                                    {{ database.connections.max }}
                                </p>
                            </div>
                            <CapacityBar
                                :used="database.connections.active"
                                :total="database.connections.max || null"
                            />
                        </div>
                    </template>
                    <p
                        v-else
                        class="rounded-xs border border-dashed border-soft p-3 text-xs text-ink-muted"
                    >
                        {{ t('admin.cloud.database.empty') }}
                    </p>
                </SettingsCard>
            </div>

            <!-- pgvector -->
            <SettingsCard
                :icon="Cpu"
                :title="t('admin.cloud.pgvector.title')"
                :description="t('admin.cloud.pgvector.description')"
            >
                <template v-if="pgvector.enabled">
                    <div class="grid grid-cols-3 gap-3">
                        <div
                            class="rounded-xs border border-soft bg-white/[0.02] p-3"
                        >
                            <p
                                class="text-[10px] tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin.cloud.pgvector.indexes') }}
                            </p>
                            <p class="font-mono text-lg font-semibold text-ink">
                                {{ pgvector.indexCount }}
                            </p>
                        </div>
                        <div
                            class="rounded-xs border border-soft bg-white/[0.02] p-3"
                        >
                            <p
                                class="text-[10px] tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin.cloud.pgvector.vectors') }}
                            </p>
                            <p class="font-mono text-lg font-semibold text-ink">
                                {{ pgvector.vectorCount.toLocaleString() }}
                            </p>
                        </div>
                        <div
                            class="rounded-xs border border-soft bg-white/[0.02] p-3"
                        >
                            <p
                                class="text-[10px] tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('admin.cloud.pgvector.size') }}
                            </p>
                            <p class="font-mono text-lg font-semibold text-ink">
                                {{ bytes(pgvector.sizeBytes) }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <Badge
                            variant="outline"
                            class="border-sp-success/40 bg-sp-success/10 font-normal text-sp-success"
                        >
                            v{{ pgvector.version }}
                        </Badge>
                        <span class="text-xs text-ink-muted">
                            {{ t('admin.cloud.pgvector.enabled_label') }}
                        </span>
                    </div>

                    <div
                        class="mt-3 overflow-hidden rounded-xs border border-soft"
                    >
                        <Table>
                            <TableHeader>
                                <TableRow class="border-soft hover:bg-transparent">
                                    <TableHead
                                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                                    >
                                        {{ t('admin.cloud.pgvector.col.name') }}
                                    </TableHead>
                                    <TableHead
                                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                                    >
                                        {{ t('admin.cloud.pgvector.col.table') }}
                                    </TableHead>
                                    <TableHead
                                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                                    >
                                        {{ t('admin.cloud.pgvector.col.metric') }}
                                    </TableHead>
                                    <TableHead
                                        class="text-right text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                                    >
                                        {{ t('admin.cloud.pgvector.col.rows') }}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableEmpty
                                    v-if="pgvector.indexes.length === 0"
                                    :colspan="4"
                                >
                                    {{ t('admin.cloud.pgvector.no_indexes') }}
                                </TableEmpty>
                                <TableRow
                                    v-for="idx in pgvector.indexes"
                                    :key="idx.name"
                                    class="border-soft"
                                >
                                    <TableCell class="font-mono text-xs text-ink">
                                        {{ idx.name }}
                                    </TableCell>
                                    <TableCell class="font-mono text-xs text-ink-muted">
                                        {{ idx.table }}
                                    </TableCell>
                                    <TableCell class="text-xs text-ink-muted">
                                        {{ idx.metric }}
                                    </TableCell>
                                    <TableCell
                                        class="text-right font-mono text-xs text-ink-muted"
                                    >
                                        {{ idx.rows.toLocaleString() }}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </template>

                <div
                    v-else
                    class="rounded-xs border border-dashed border-soft p-4 text-xs text-ink-muted"
                >
                    {{ t('admin.cloud.pgvector.disabled') }}
                </div>
            </SettingsCard>
        </div>
    </AdminLayout>
</template>
