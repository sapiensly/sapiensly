<script setup lang="ts">
import type { ToolActivityDto } from '@/types/chatModule';
import { Brain, Check, Wrench, X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

// Live tool-call chips for a streaming message: each tool shows a pulsing
// "using <tool>…" while it runs, then settles to a check (done) or a red x
// (failed). The reserved `__reasoning__` name (extended thinking) renders as a
// pulsing "thinking…" chip instead. `accent`/`accentSoft` tint the chip to the
// agent's hue in a multi-agent bubble; without them it falls back to the
// assistant accent.
defineProps<{
    items: ToolActivityDto[];
    accent?: string | null;
    accentSoft?: string | null;
}>();

const { t } = useI18n();

const REASONING = '__reasoning__';

function prettyToolName(name: string): string {
    return name.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function chipLabel(item: ToolActivityDto): string {
    if (item.name === REASONING) {
        return t('chat.tools.thinking');
    }
    return item.status === 'running'
        ? t('chat.tools.using', { tool: prettyToolName(item.name) })
        : prettyToolName(item.name);
}
</script>

<template>
    <div v-if="items.length" class="mb-2 flex flex-wrap gap-1.5">
        <span
            v-for="item in items"
            :key="item.id"
            class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
            :class="
                accent
                    ? ''
                    : 'border-accent-blue/30 bg-accent-blue/10 text-accent-blue'
            "
            :style="
                accent
                    ? {
                          borderColor: accentSoft ?? undefined,
                          backgroundColor: accentSoft ?? undefined,
                          color: accent,
                      }
                    : undefined
            "
        >
            <Brain
                v-if="item.name === REASONING"
                class="size-3 animate-pulse"
            />
            <Wrench
                v-else-if="item.status === 'running'"
                class="size-3 animate-pulse"
            />
            <Check v-else-if="item.status === 'done'" class="size-3" />
            <X v-else class="size-3 text-sp-danger" />
            <span
                :class="{ 'line-through opacity-70': item.status === 'error' }"
            >
                {{ chipLabel(item) }}
            </span>
        </span>
    </div>
</template>
