<script setup lang="ts">
import type { SlashCommand } from '@/lib/builderSlashCommands';
import { Sparkles } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    /** Commands filtered by the parent against the current input. */
    commands: SlashCommand[];
    /** Index of the row that arrows/enter act on. */
    highlightedIndex: number;
    /** When the parent has a focused input but no command at all matches,
     *  it can still show the empty state by passing all-empty here. */
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'select', command: SlashCommand): void;
    (e: 'hover', index: number): void;
}>();

// Tiny safety net: the parent normalizes the index, but if it ever drifts
// out of range we clamp here so the highlight class doesn't apply to a
// non-existent row.
const safeIndex = computed(() => {
    if (props.commands.length === 0) return -1;
    return Math.max(0, Math.min(props.highlightedIndex, props.commands.length - 1));
});

function descriptionKey(cmd: SlashCommand): string {
    return `apps.builder.slash.${cmd.id}.description`;
}
function usageKey(cmd: SlashCommand): string {
    return `apps.builder.slash.${cmd.id}.usage`;
}
</script>

<template>
    <div
        v-if="open"
        class="absolute bottom-full left-0 right-0 z-20 mb-2 max-h-72 overflow-auto rounded-sp-sm border border-soft bg-navy shadow-lg"
    >
        <header class="flex items-center gap-1.5 border-b border-soft px-3 py-2 text-[10px] uppercase tracking-wider text-ink-subtle">
            <Sparkles class="size-3" />
            {{ $t('apps.builder.slash.menu_heading') }}
        </header>

        <p
            v-if="commands.length === 0"
            class="px-3 py-3 text-xs text-ink-muted"
        >
            {{ $t('apps.builder.slash.no_matches') }}
        </p>

        <ul v-else class="py-1">
            <li
                v-for="(cmd, idx) in commands"
                :key="cmd.id"
                @mousedown.prevent="emit('select', cmd)"
                @mouseenter="emit('hover', idx)"
                :class="[
                    'cursor-pointer px-3 py-2 transition-colors',
                    idx === safeIndex ? 'bg-accent-blue/15' : 'hover:bg-surface',
                ]"
            >
                <div class="flex items-baseline gap-2">
                    <code
                        :class="[
                            'font-mono text-[13px]',
                            idx === safeIndex ? 'text-accent-blue' : 'text-ink',
                        ]"
                    >/{{ cmd.name }}</code>
                    <span
                        v-if="$t(usageKey(cmd))"
                        class="font-mono text-[10px] text-ink-subtle"
                    >{{ $t(usageKey(cmd)) }}</span>
                </div>
                <p class="mt-0.5 text-[11px] leading-snug text-ink-muted">
                    {{ $t(descriptionKey(cmd)) }}
                </p>
            </li>
        </ul>
    </div>
</template>
