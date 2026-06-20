<script setup lang="ts">
import type { InputNodeConfig } from '@/types/botFlows';
import { Paperclip } from '@lucide/vue';
import { Handle, Position } from '@vue-flow/core';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    id: string;
    data: InputNodeConfig;
}>();
</script>

<template>
    <div
        class="max-w-[280px] min-w-[220px] rounded-sp-sm border border-soft bg-navy p-3 shadow-sp-float"
        style="
            border-color: color-mix(
                in oklab,
                var(--sp-accent-teal) 45%,
                var(--sp-border-soft)
            );
        "
    >
        <Handle
            type="target"
            :position="Position.Top"
            class="!bg-accent-teal"
        />

        <div
            class="mb-1 flex items-center gap-1.5 text-xs font-medium text-ink-muted"
        >
            <Paperclip
                v-if="data.input_type === 'file'"
                class="text-accent-teal h-3.5 w-3.5"
            />
            {{
                data.input_type === 'file'
                    ? t('botFlows.nodes.input_file')
                    : t('botFlows.nodes.input')
            }}
        </div>

        <div
            class="rounded-xs px-1 py-0.5 text-sm whitespace-pre-wrap text-ink"
            :class="{ 'text-ink-subtle italic': !data.prompt }"
        >
            {{ data.prompt || t('botFlows.nodes.input_no_prompt') }}
        </div>

        <div v-if="data.variable" class="mt-1 px-1 text-[11px] text-ink-subtle">
            → <span class="font-mono">{{ data.variable }}</span>
            <span v-if="data.input_type && data.input_type !== 'text'">
                ({{ data.input_type }})
            </span>
        </div>

        <Handle
            type="source"
            :position="Position.Bottom"
            class="!bg-accent-teal"
        />
    </div>
</template>
