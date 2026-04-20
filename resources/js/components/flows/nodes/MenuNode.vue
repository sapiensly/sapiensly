<script setup lang="ts">
import type { MenuNodeConfig } from '@/types/flows';
import { Handle, Position } from '@vue-flow/core';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    id: string;
    data: MenuNodeConfig;
}>();
</script>

<template>
    <div class="min-w-[200px] rounded-sp-sm border border-soft bg-navy p-3 shadow-sp-float">
        <Handle type="target" :position="Position.Top" class="!bg-accent-blue" />

        <div class="mb-1 text-xs font-medium text-ink-muted">
            {{ t('flows.nodes.menu') }}
        </div>
        <div class="mb-2 text-sm text-ink">
            {{ data.message || t('flows.nodes.no_message') }}
        </div>

        <div
            v-for="(option, i) in data.options"
            :key="option.id"
            class="relative flex items-center py-1 text-xs text-ink"
        >
            <span class="truncate">{{ option.label }}</span>
            <Handle
                type="source"
                :position="Position.Right"
                :id="option.id"
                class="!bg-accent-blue"
                :style="{ top: `${60 + i * 28}px` }"
            />
        </div>
    </div>
</template>
