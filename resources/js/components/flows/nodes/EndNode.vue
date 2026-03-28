<script setup lang="ts">
import type { EndNodeConfig } from '@/types/flows';
import { Handle, Position } from '@vue-flow/core';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    id: string;
    data: EndNodeConfig;
}>();
</script>

<template>
    <div
        class="flex h-16 w-16 items-center justify-center rounded-full border-2 border-red-500 bg-red-50 shadow-sm dark:bg-red-950/40"
    >
        <Handle type="target" :position="Position.Top" class="!bg-red-500" />

        <span class="text-xs font-semibold text-red-700 dark:text-red-300">
            {{ t('flows.nodes.end') }}
        </span>
    </div>
</template>
