<script setup lang="ts">
import type { MessageNodeConfig } from '@/types/flows';
import { Handle, Position } from '@vue-flow/core';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    id: string;
    data: MessageNodeConfig;
}>();
</script>

<template>
    <div class="min-w-[200px] rounded-lg border bg-card p-3 shadow-sm">
        <Handle type="target" :position="Position.Top" class="!bg-primary" />

        <div class="mb-1 text-xs font-medium text-muted-foreground">
            {{ t('flows.nodes.message') }}
        </div>
        <div class="max-w-[220px] truncate text-sm">
            {{ data.message || t('flows.nodes.no_message') }}
        </div>

        <Handle type="source" :position="Position.Bottom" class="!bg-primary" />
    </div>
</template>
