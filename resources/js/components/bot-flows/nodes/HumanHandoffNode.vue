<script setup lang="ts">
import type { HumanHandoffNodeConfig } from '@/types/botFlows';
import { Handle, Position } from '@vue-flow/core';
import { UserRound } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    id: string;
    data: HumanHandoffNodeConfig;
}>();
</script>

<template>
    <div
        class="flex min-w-[160px] max-w-[220px] items-center gap-2 rounded-sp-sm border-2 px-3 py-2.5 shadow-sp-float"
        style="
            border-color: var(--sp-warning);
            background: color-mix(in oklab, var(--sp-warning) 15%, var(--sp-bg-secondary));
        "
    >
        <Handle type="target" :position="Position.Top" class="!bg-sp-warning" />

        <div
            class="flex size-7 shrink-0 items-center justify-center rounded-xs"
            style="
                background: color-mix(in oklab, var(--sp-warning) 22%, transparent);
                color: var(--sp-warning);
            "
        >
            <UserRound class="size-4" />
        </div>

        <div class="min-w-0 leading-tight">
            <p class="text-xs font-semibold" style="color: var(--sp-warning)">
                {{ t('botFlows.nodes.human_handoff') }}
            </p>
            <p v-if="data.reason" class="truncate text-[11px] text-ink-subtle">
                {{ data.reason }}
            </p>
        </div>
    </div>
</template>
