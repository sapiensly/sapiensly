<script setup lang="ts">
import type { BotFlowNodeType } from '@/types/botFlows';
import {
    ArrowRightLeft,
    Bot,
    CircleStop,
    CornerDownLeft,
    GitBranch,
    GripVertical,
    ListOrdered,
    MessageSquare,
    TextCursorInput,
    UserRound,
} from '@lucide/vue';
import { type Component } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface PaletteItem {
    type: BotFlowNodeType;
    label: string;
    description: string;
    icon: Component;
    /** Tint drives the icon tile bg + icon color. Matches the node colour on canvas. */
    tint: string;
}

const items: PaletteItem[] = [
    {
        type: 'agent',
        label: 'botFlows.nodes.agent',
        description: 'botFlows.palette.agent_description',
        icon: Bot,
        tint: '#a855f7',
    },
    {
        type: 'menu',
        label: 'botFlows.nodes.menu',
        description: 'botFlows.palette.menu_description',
        icon: ListOrdered,
        tint: 'var(--sp-warning)',
    },
    {
        type: 'condition',
        label: 'botFlows.nodes.condition',
        description: 'botFlows.palette.condition_description',
        icon: GitBranch,
        tint: 'var(--sp-accent-cyan)',
    },
    {
        type: 'agent_handoff',
        label: 'botFlows.nodes.agent_handoff',
        description: 'botFlows.palette.agent_handoff_description',
        icon: ArrowRightLeft,
        tint: 'var(--sp-spectrum-magenta)',
    },
    {
        type: 'message',
        label: 'botFlows.nodes.message',
        description: 'botFlows.palette.message_description',
        icon: MessageSquare,
        tint: 'var(--sp-accent-blue)',
    },
    {
        type: 'input',
        label: 'botFlows.nodes.input',
        description: 'botFlows.palette.input_description',
        icon: TextCursorInput,
        tint: 'var(--sp-accent-teal)',
    },
    {
        type: 'human_handoff',
        label: 'botFlows.nodes.human_handoff',
        description: 'botFlows.palette.human_handoff_description',
        icon: UserRound,
        tint: 'var(--sp-warning)',
    },
    {
        type: 'connector',
        label: 'botFlows.nodes.connector',
        description: 'botFlows.palette.connector_description',
        icon: CornerDownLeft,
        tint: 'var(--sp-spectrum-indigo)',
    },
    {
        type: 'end',
        label: 'botFlows.nodes.end',
        description: 'botFlows.palette.end_description',
        icon: CircleStop,
        tint: 'var(--sp-danger)',
    },
];

const onDragStart = (event: DragEvent, type: BotFlowNodeType) => {
    if (event.dataTransfer) {
        event.dataTransfer.setData('application/vueflow', type);
        event.dataTransfer.effectAllowed = 'move';
    }
};
</script>

<template>
    <div class="flex h-full w-[240px] flex-col border-r border-soft bg-navy">
        <div class="border-b border-soft px-3 py-3">
            <h3
                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
            >
                {{ t('botFlows.palette.title') }}
            </h3>
        </div>

        <div class="flex-1 space-y-1.5 overflow-y-auto p-2">
            <div
                v-for="item in items"
                :key="item.type"
                class="group flex cursor-grab items-center gap-3 rounded-sp-sm border border-soft bg-white/[0.03] px-3 py-2.5 transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06] active:cursor-grabbing"
                draggable="true"
                @dragstart="onDragStart($event, item.type)"
            >
                <!-- Colored icon tile — bg = tint @ 15% over navy, icon in tint. -->
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-xs"
                    :style="{
                        backgroundColor: `color-mix(in oklab, ${item.tint} 18%, transparent)`,
                        color: item.tint,
                    }"
                >
                    <component :is="item.icon" class="size-4" />
                </div>

                <div class="min-w-0 flex-1 leading-tight">
                    <p class="truncate text-[13px] font-medium text-ink">
                        {{ t(item.label) }}
                    </p>
                    <p class="truncate text-[11px] text-ink-subtle">
                        {{ t(item.description) }}
                    </p>
                </div>

                <!-- Drag grip — subtle, brightens on hover. -->
                <GripVertical
                    class="size-3.5 shrink-0 text-ink-subtle opacity-60 transition-opacity group-hover:opacity-100"
                />
            </div>
        </div>
    </div>
</template>
