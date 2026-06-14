<script setup lang="ts">
import type { ChatAgentOption } from '@/types/chatModule';
import { Link } from '@inertiajs/vue3';
import { Bot } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    agents: ChatAgentOption[];
    highlight: number;
}>();

defineEmits<{
    select: [agent: ChatAgentOption];
    hover: [index: number];
}>();
</script>

<template>
    <div
        class="absolute bottom-full left-0 z-20 mb-2 max-h-64 w-72 overflow-y-auto rounded-xl border border-medium bg-surface p-1 shadow-lg"
    >
        <template v-if="agents.length">
            <button
                v-for="(agent, i) in agents"
                :key="agent.id"
                type="button"
                :class="[
                    'flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm transition-colors',
                    i === highlight
                        ? 'bg-accent-blue/15 text-ink'
                        : 'text-ink-muted hover:bg-white/5 hover:text-ink',
                ]"
                @mousedown.prevent="$emit('select', agent)"
                @mousemove="$emit('hover', i)"
            >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent-blue/15 text-accent-blue"
                >
                    <Bot class="size-3.5" />
                </span>
                <span class="truncate">{{ agent.name }}</span>
            </button>
        </template>

        <div v-else class="px-2.5 py-3 text-sm text-ink-subtle">
            <p>{{ t('chat.mention.none') }}</p>
            <Link
                href="/agents"
                class="mt-1 inline-block text-accent-blue hover:underline"
            >
                {{ t('chat.mention.create') }}
            </Link>
        </div>
    </div>
</template>
