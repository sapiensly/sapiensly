<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { ConditionNodeConfig } from '@/types/flows';
import { Handle, Position } from '@vue-flow/core';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    id: string;
    data: ConditionNodeConfig;
}>();
</script>

<template>
    <div class="min-w-[200px] rounded-sp-sm border border-soft bg-navy p-3 shadow-sp-float">
        <Handle type="target" :position="Position.Top" class="!bg-accent-blue" />

        <div class="mb-1 flex items-center gap-2">
            <span class="text-xs font-medium text-ink-muted">
                {{ t('flows.nodes.condition') }}
            </span>
            <Badge variant="secondary" class="text-[10px]">
                {{ data.match_type }}
            </Badge>
        </div>

        <div
            v-for="(rule, i) in data.rules"
            :key="rule.id"
            class="relative flex items-center py-1 text-xs text-ink"
        >
            <span class="truncate">{{
                rule.label || rule.pattern || t('flows.nodes.empty_rule')
            }}</span>
            <Handle
                type="source"
                :position="Position.Right"
                :id="rule.id"
                class="!bg-accent-blue"
                :style="{ top: `${52 + i * 28}px` }"
            />
        </div>

        <div
            class="relative flex items-center py-1 text-xs text-ink-subtle italic"
        >
            <span>{{ t('flows.nodes.default') }}</span>
            <Handle
                type="source"
                :position="Position.Right"
                id="default"
                class="!bg-ink-subtle"
                :style="{ top: `${52 + data.rules.length * 28}px` }"
            />
        </div>
    </div>
</template>
