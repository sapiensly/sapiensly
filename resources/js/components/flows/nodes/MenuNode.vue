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
    <div class="min-w-[200px] rounded-lg border bg-card p-3 shadow-sm">
        <Handle type="target" :position="Position.Top" class="!bg-primary" />

        <div class="mb-1 text-xs font-medium text-muted-foreground">
            {{ t('flows.nodes.menu') }}
        </div>
        <div class="mb-2 text-sm">
            {{ data.message || t('flows.nodes.no_message') }}
        </div>

        <div
            v-for="(option, i) in data.options"
            :key="option.id"
            class="relative flex items-center py-1 text-xs"
        >
            <span class="truncate">{{ option.label }}</span>
            <Handle
                type="source"
                :position="Position.Right"
                :id="option.id"
                class="!bg-primary"
                :style="{ top: `${60 + i * 28}px` }"
            />
        </div>
    </div>
</template>
