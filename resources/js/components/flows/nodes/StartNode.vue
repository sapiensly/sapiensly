<script setup lang="ts">
import type { StartNodeConfig } from '@/types/flows';
import { Handle, Position } from '@vue-flow/core';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    id: string;
    data: StartNodeConfig;
}>();
</script>

<template>
    <div
        class="flex h-16 w-16 items-center justify-center rounded-full border-2 border-green-500 bg-green-50 shadow-sm dark:bg-green-950/40"
    >
        <span class="text-xs font-semibold text-green-700 dark:text-green-300">
            {{ t('flows.nodes.start') }}
        </span>

        <Handle
            type="source"
            :position="Position.Bottom"
            class="!bg-green-500"
        />
    </div>
</template>
