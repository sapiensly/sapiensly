<script setup lang="ts">
import type { ConnectorNodeConfig } from '@/types/flows';
import { Handle, Position } from '@vue-flow/core';
import { CornerDownLeft } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    id: string;
    data: ConnectorNodeConfig;
}>();

const label = computed(() => {
    if (props.data.target_node_id === '__start__') {
        return t('flows.nodes.connector_to_start');
    }
    return props.data.target_label || t('flows.nodes.connector_no_target');
});
</script>

<template>
    <div class="min-w-[180px] rounded-lg border border-dashed border-indigo-400 bg-indigo-50 p-3 shadow-sm dark:bg-indigo-950/40">
        <Handle type="target" :position="Position.Top" class="!bg-indigo-500" />

        <div class="mb-1 text-xs font-medium text-muted-foreground">
            {{ t('flows.nodes.connector') }}
        </div>

        <div class="flex items-center gap-2 text-sm text-indigo-700 dark:text-indigo-300">
            <CornerDownLeft class="h-4 w-4" />
            <span class="font-medium">{{ label }}</span>
        </div>
    </div>
</template>
