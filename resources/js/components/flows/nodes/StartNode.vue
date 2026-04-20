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
        class="flex h-16 w-16 items-center justify-center rounded-full border-2 shadow-sp-float"
        style="
            border-color: var(--sp-success);
            background: color-mix(in oklab, var(--sp-success) 15%, var(--sp-bg-secondary));
        "
    >
        <span class="text-xs font-semibold text-sp-success">
            {{ t('flows.nodes.start') }}
        </span>

        <Handle
            type="source"
            :position="Position.Bottom"
            class="!bg-sp-success"
        />
    </div>
</template>
