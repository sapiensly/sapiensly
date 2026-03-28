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
    <div class="min-w-[200px] rounded-lg border bg-card p-3 shadow-sm">
        <Handle type="target" :position="Position.Top" class="!bg-primary" />

        <div class="mb-1 flex items-center gap-2">
            <span class="text-xs font-medium text-muted-foreground">
                {{ t('flows.nodes.condition') }}
            </span>
            <Badge variant="secondary" class="text-[10px]">
                {{ data.match_type }}
            </Badge>
        </div>

        <div
            v-for="(rule, i) in data.rules"
            :key="rule.id"
            class="relative flex items-center py-1 text-xs"
        >
            <span class="truncate">{{
                rule.label || rule.pattern || t('flows.nodes.empty_rule')
            }}</span>
            <Handle
                type="source"
                :position="Position.Right"
                :id="rule.id"
                class="!bg-primary"
                :style="{ top: `${52 + i * 28}px` }"
            />
        </div>

        <div
            class="relative flex items-center py-1 text-xs text-muted-foreground italic"
        >
            <span>{{ t('flows.nodes.default') }}</span>
            <Handle
                type="source"
                :position="Position.Right"
                id="default"
                class="!bg-muted-foreground"
                :style="{ top: `${52 + data.rules.length * 28}px` }"
            />
        </div>
    </div>
</template>
