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
        class="flex h-16 w-16 items-center justify-center rounded-full border-2 shadow-sp-float"
        style="
            border-color: var(--sp-danger);
            background: color-mix(in oklab, var(--sp-danger) 15%, var(--sp-bg-secondary));
        "
    >
        <Handle type="target" :position="Position.Top" class="!bg-sp-danger" />

        <span class="text-xs font-semibold text-sp-danger">
            {{ t('flows.nodes.end') }}
        </span>
    </div>
</template>
